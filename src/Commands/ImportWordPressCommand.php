<?php

declare(strict_types=1);

namespace BlogCore\Commands;

use BlogCore\Core\Config;
use RuntimeException;

/**
 * Imports posts and categories from a WordPress site into the blog-core
 * file structure using the WordPress REST API (v2).
 *
 * Improvements over a typical WordPress export:
 *  - Tags are batch-fetched up-front (no N+1 API calls)
 *  - Posts can be filtered to a single slug with --post
 *  - Already-imported posts are skipped unless --force is passed
 *  - WordPress HTML is sanitised (classes, poll blocks, cruft removed)
 *  - Inline images are downloaded and src attributes rewritten
 *  - Featured images are downloaded to both posts/{slug}/ (for process-images
 *    WebP conversion) and public/images/posts/{slug}/ (for immediate access)
 *  - Uses the REST API _fields param to fetch only required data
 */
class ImportWordPressCommand
{
    private const USER_AGENT = 'blog-core-wordpress-importer/1.0';

    // WordPress HTML attributes that carry no useful information after export
    private const STRIP_ATTRS = [
        'class', 'id', 'style',
        'data-align', 'data-mode', 'data-layout',
        'data-wp-block', 'data-block',
    ];

    private const STRIP_IMG_ATTRS = [
        'width', 'height', 'srcset', 'sizes',
        'data-recalc-dims', 'loading', 'decoding', 'fetchpriority',
    ];

    // -------------------------------------------------------------------------
    // Entry points
    // -------------------------------------------------------------------------

    /**
     * CLI entry point.
     *
     * Usage:
     *   php bin/import_wordpress.php --url https://example.com [-v] [--post slug] [--force]
     */
    public static function run(Config $config, array $argv = []): void
    {
        $verbose  = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
        $force    = in_array('--force', $argv, true);
        $siteUrl  = self::parseArg('--url', $argv);
        $onlySlug = self::parseArg('--post', $argv);

        if ($siteUrl === null) {
            fwrite(STDERR, "Error: --url <wordpress-site-url> is required.\n");
            exit(1);
        }

        static::execute($config, rtrim($siteUrl, '/'), $onlySlug, $force, $verbose);
    }

    /**
     * Programmatic entry point.
     *
     * @param string      $siteUrl   WordPress site base URL (no trailing slash).
     * @param string|null $onlySlug  Import only the post with this slug.
     * @param bool        $force     Re-import posts that already exist on disk.
     */
    public static function execute(
        Config  $config,
        string  $siteUrl,
        ?string $onlySlug = null,
        bool    $force    = false,
        bool    $verbose  = false
    ): void {
        self::requireCurl();

        $apiBase = $siteUrl . '/wp-json/wp/v2';

        // ------------------------------------------------------------------
        // 1. Fetch all tags up-front to avoid per-post API calls
        // ------------------------------------------------------------------
        self::log($verbose, 'Fetching tags...');
        $tagMap = []; // [wp-id => name]

        foreach (self::fetchAllPaged($apiBase . '/tags?per_page=100') as $tag) {
            $tagMap[(int)$tag['id']] = $tag['name'];
        }

        self::log($verbose, sprintf('  %d tag(s) found.', count($tagMap)));

        // ------------------------------------------------------------------
        // 2. Import categories
        // ------------------------------------------------------------------
        self::log($verbose, 'Importing categories...');
        $categoryMap = self::importCategories($apiBase, $config, $force, $verbose);

        // ------------------------------------------------------------------
        // 3. Import posts
        // ------------------------------------------------------------------
        self::log($verbose, 'Importing posts...');
        self::importPosts($apiBase, $config, $tagMap, $categoryMap, $onlySlug, $force, $verbose);

        self::log($verbose, 'Import complete.');
    }

    // -------------------------------------------------------------------------
    // Categories
    // -------------------------------------------------------------------------

    /**
     * Fetch all WordPress categories and write one JSON file per category.
     * Returns a map of [wp-id => slug].
     */
    private static function importCategories(
        string $apiBase,
        Config $config,
        bool   $force,
        bool   $verbose
    ): array {
        $catDir     = rtrim($config->getCategoriesDir(), '/');
        $categoryMap = [];

        $wpCategories = self::fetchAllPaged($apiBase . '/categories?per_page=100');

        foreach ($wpCategories as $cat) {
            $wpId = (int)$cat['id'];
            $slug = $cat['slug'];
            $categoryMap[$wpId] = $slug;

            // Skip empty categories
            if ((int)($cat['count'] ?? 0) === 0) {
                continue;
            }

            $file = $catDir . '/' . $slug . '.json';

            if (file_exists($file) && !$force) {
                self::log($verbose, "  skip (exists): {$slug}");
                continue;
            }

            if (!is_dir($catDir) && !mkdir($catDir, 0755, true)) {
                throw new RuntimeException("Could not create categories directory: {$catDir}");
            }

            $data = [
                'id'          => $slug,
                'title'       => self::decodeEntities($cat['name'] ?? ''),
                'slug'        => $slug,
                'description' => self::decodeEntities(strip_tags($cat['description'] ?? '')),
                'image'       => null,
                'featured'    => false,
            ];

            file_put_contents(
                $file,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            self::log($verbose, "  Category: {$slug}");
        }

        return $categoryMap;
    }

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------

    private static function importPosts(
        string  $apiBase,
        Config  $config,
        array   $tagMap,
        array   $categoryMap,
        ?string $onlySlug,
        bool    $force,
        bool    $verbose
    ): void {
        $postsDir  = rtrim($config->getPostsDir(), '/');
        $publicDir = rtrim($config->getPublicDir(), '/');

        // Request only the fields we need
        $fields   = 'id,slug,title,excerpt,content,status,categories,tags,featured_media,date,modified';
        $endpoint = $apiBase . '/posts?per_page=20&status=publish,draft&_fields=' . $fields;

        if ($onlySlug !== null) {
            $endpoint .= '&slug=' . urlencode($onlySlug);
        }

        $wpPosts = self::fetchAllPaged($endpoint);

        if (empty($wpPosts)) {
            self::log($verbose, '  No posts found.');
            return;
        }

        foreach ($wpPosts as $post) {
            self::importPost($post, $apiBase, $config, $postsDir, $publicDir, $tagMap, $categoryMap, $force, $verbose);
        }
    }

    private static function importPost(
        array  $post,
        string $apiBase,
        Config $config,
        string $postsDir,
        string $publicDir,
        array  $tagMap,
        array  $categoryMap,
        bool   $force,
        bool   $verbose
    ): void {
        $slug     = $post['slug'];
        $postDir  = $postsDir . '/' . $slug;
        $metaFile = $postDir . '/meta.json';
        $mdFile   = $postDir . '/post.md';

        if (file_exists($metaFile) && file_exists($mdFile) && !$force) {
            self::log($verbose, "  skip (exists): {$slug}");
            return;
        }

        if (!is_dir($postDir) && !mkdir($postDir, 0755, true)) {
            throw new RuntimeException("Could not create post directory: {$postDir}");
        }

        $title   = self::decodeEntities($post['title']['rendered'] ?? '');
        $excerpt = trim(preg_replace('/\s+/', ' ', self::decodeEntities(
            strip_tags($post['excerpt']['rendered'] ?? '')
        )));
        $isDraft = ($post['status'] ?? 'publish') !== 'publish';

        // Tags
        $tags = array_values(array_filter(
            array_map(fn($id) => $tagMap[(int)$id] ?? null, (array)($post['tags'] ?? []))
        ));

        // Categories
        $categories = array_values(array_filter(
            array_map(fn($id) => $categoryMap[(int)$id] ?? null, (array)($post['categories'] ?? []))
        ));

        // Featured image — downloaded to posts/{slug}/ for process-images
        // AND to public/images/posts/{slug}/ for immediate web access
        $featuredImagePath = null;
        $featuredMediaId   = (int)($post['featured_media'] ?? 0);

        if ($featuredMediaId > 0) {
            $featuredImagePath = self::importFeaturedImage(
                $apiBase, $featuredMediaId, $slug, $postDir, $publicDir, $verbose
            );
        }

        // Content HTML — clean WordPress markup, download inline images
        $html = self::cleanHtml($post['content']['rendered'] ?? '');
        $html = self::importInlineImages($html, $slug, $publicDir, $verbose);

        // Write meta.json
        $meta = [
            'title'       => $title,
            'slug'        => $slug,
            'description' => $excerpt ?: null,
            'image'       => $featuredImagePath,
            'tags'        => $tags,
            'categories'  => $categories,
            'featured'    => false,
            'draft'       => $isDraft,
            'publishedAt' => $post['date'] ?? null,
        ];

        file_put_contents(
            $metaFile,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Write post.md — clean HTML is stored directly; Parsedown renders it as-is
        file_put_contents($mdFile, $html . "\n");

        self::log($verbose, sprintf(
            '  Post: %s (%s)%s',
            $title,
            $slug,
            $isDraft ? ' [draft]' : ''
        ));
    }

    /**
     * Fetch the featured image for a post, download it to both the post source
     * directory (for process-images) and the public directory (for immediate
     * web access), and return the public URL path.
     */
    private static function importFeaturedImage(
        string $apiBase,
        int    $mediaId,
        string $slug,
        string $postDir,
        string $publicDir,
        bool   $verbose
    ): ?string {
        try {
            $response = self::get($apiBase . '/media/' . $mediaId);
            $media    = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $srcUrl   = $media['source_url'] ?? null;

            if (!$srcUrl) {
                return null;
            }

            $basename     = basename(parse_url($srcUrl, PHP_URL_PATH));
            $publicImgDir = $publicDir . '/images/posts/' . $slug;

            // Download to posts/{slug}/ so process-images can generate WebP versions
            self::downloadFile($srcUrl, $postDir . '/' . $basename, $verbose);

            // Download to public/images/posts/{slug}/ for immediate access
            self::downloadFile($srcUrl, $publicImgDir . '/' . $basename, $verbose);

            return '/images/posts/' . $slug . '/' . $basename;
        } catch (\Throwable $e) {
            fwrite(STDERR, "  [warn] Could not fetch featured image for '{$slug}': {$e->getMessage()}\n");
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // HTML processing
    // -------------------------------------------------------------------------

    /**
     * Strip WordPress-specific markup clutter from post HTML.
     * Keeps all semantic content: figures, captions, tables, code blocks, etc.
     */
    private static function cleanHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML(
            '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($dom);

        // Remove non-content elements
        foreach ($xpath->query('//script|//style|//noscript|//iframe') as $node) {
            $node->parentNode->removeChild($node);
        }

        // Replace WordPress poll blocks with a notice
        foreach ($xpath->query('//*[contains(@class,"wp-polls")]') as $node) {
            $notice = $dom->createElement('p');
            $notice->appendChild($dom->createTextNode('[Poll removed during migration]'));
            $node->parentNode->replaceChild($notice, $node);
        }

        // Remove poll loading spinners
        foreach ($xpath->query('//*[contains(@class,"wp-polls-loading")]') as $node) {
            $node->parentNode->removeChild($node);
        }

        // Strip WordPress-specific attributes from all elements
        foreach ($xpath->query('//*') as $node) {
            foreach (self::STRIP_ATTRS as $attr) {
                $node->removeAttribute($attr);
            }
        }

        // Strip noise attributes from images (keep src and alt)
        foreach ($xpath->query('//img') as $img) {
            foreach (self::STRIP_IMG_ATTRS as $attr) {
                $img->removeAttribute($attr);
            }
        }

        // Remove empty paragraphs
        foreach ($xpath->query('//p[not(normalize-space(.))]') as $node) {
            $node->parentNode->removeChild($node);
        }

        // Serialize body contents only
        $body   = $dom->getElementsByTagName('body')->item(0);
        $output = '';

        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    /**
     * Find all <img> tags in the HTML, download each image to
     * public/images/posts/{slug}/, and rewrite the src attribute.
     */
    private static function importInlineImages(
        string $html,
        string $slug,
        string $publicDir,
        bool   $verbose
    ): string {
        if (trim($html) === '' || !str_contains($html, '<img')) {
            return $html;
        }

        $imgDir = $publicDir . '/images/posts/' . $slug;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML(
            '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        foreach ($dom->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src');

            if (!$src || !filter_var($src, FILTER_VALIDATE_URL)) {
                continue;
            }

            $basename = basename(parse_url($src, PHP_URL_PATH));

            if (!is_dir($imgDir) && !mkdir($imgDir, 0755, true)) {
                fwrite(STDERR, "  [warn] Could not create directory: {$imgDir}\n");
                continue;
            }

            if (self::downloadFile($src, $imgDir . '/' . $basename, $verbose)) {
                $img->setAttribute('src', '/images/posts/' . $slug . '/' . $basename);
            }
        }

        $body   = $dom->getElementsByTagName('body')->item(0);
        $output = '';

        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch all pages of a paginated WordPress REST API endpoint.
     * Handles X-WP-TotalPages automatically.
     */
    private static function fetchAllPaged(string $url): array
    {
        $results    = [];
        $page       = 1;
        $totalPages = 1;

        do {
            $sep      = str_contains($url, '?') ? '&' : '?';
            $response = self::get($url . $sep . 'page=' . $page);

            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data) || empty($data)) {
                break;
            }

            $results    = array_merge($results, $data);
            $totalPages = (int)$response['totalPages'];
            $page++;
        } while ($page <= $totalPages);

        return $results;
    }

    /**
     * HTTP GET. Returns ['body' => string, 'totalPages' => int].
     */
    private static function get(string $url): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw      = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("cURL error for {$url}: {$error}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("HTTP {$httpCode} for {$url}");
        }

        $headers    = substr((string)$raw, 0, $hdrSize);
        $body       = substr((string)$raw, $hdrSize);
        $totalPages = 1;

        if (preg_match('/X-WP-TotalPages:\s*(\d+)/i', $headers, $m)) {
            $totalPages = (int)$m[1];
        }

        return ['body' => $body, 'totalPages' => $totalPages];
    }

    /**
     * Download $url to $dest. Skips if $dest already exists.
     * Returns true on success or skip, false on failure.
     */
    private static function downloadFile(string $url, string $dest, bool $verbose): bool
    {
        if (file_exists($dest)) {
            return true;
        }

        $dir = dirname($dest);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            fwrite(STDERR, "  [warn] Could not create directory: {$dir}\n");
            return false;
        }

        $fh = fopen($dest, 'wb');
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);

        curl_close($ch);
        fclose($fh);

        if ($errno !== 0 || $httpCode !== 200) {
            unlink($dest);
            fwrite(STDERR, "  [warn] Failed to download ({$httpCode}): {$url}\n");
            return false;
        }

        if ($verbose) {
            echo '    downloaded: ' . basename($dest) . "\n";
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    private static function requireCurl(): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                "ext-curl is required for the WordPress importer. Enable it in your php.ini."
            );
        }
    }

    /**
     * Parse a named CLI argument: --name value  or  --name=value
     */
    private static function parseArg(string $name, array $argv): ?string
    {
        foreach ($argv as $i => $arg) {
            if ($arg === $name && isset($argv[$i + 1])) {
                return $argv[$i + 1];
            }

            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name) + 1);
            }
        }

        return null;
    }

    private static function decodeEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function log(bool $verbose, string $message): void
    {
        if ($verbose) {
            echo $message . PHP_EOL;
        }
    }
}

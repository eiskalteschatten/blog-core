<?php

declare(strict_types=1);

namespace BlogCore\Commands;

use BlogCore\Core\Config;
use BlogCore\Helpers\SlugHelper;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Imports posts, categories, tags, and comments from a WordPress WXR export
 * file (.xml) into the blog-core file structure.
 *
 * Advantages over the REST API importer:
 *  - Works fully offline — no live WordPress site required
 *  - Drafts are included natively (no --auth credentials needed)
 *  - Comments are imported alongside posts
 *  - No pagination, rate limits, or network timeouts on content
 *  - Only cURL is needed (for downloading images referenced in the XML)
 *
 * Usage:
 *   php bin/import_wordpress_xml.php --file export.xml [-v] [--post slug] [--force] [--skip-images]
 *
 * Comments are written to {postDir}/comments.json as an array of objects.
 */
class ImportWordPressXmlCommand
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
     *   php bin/import_wordpress_xml.php --file export.xml [-v] [--post slug] [--force] [--skip-images]
     */
    public static function run(Config $config, array $argv = []): void
    {
        $verbose     = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
        $force       = in_array('--force', $argv, true);
        $skipImages  = in_array('--skip-images', $argv, true);
        $file        = self::parseArg('--file', $argv);
        $onlySlug    = self::parseArg('--post', $argv);

        if ($file === null) {
            fwrite(STDERR, "Error: --file <path-to-wordpress-export.xml> is required.\n");
            exit(1);
        }

        if (!is_readable($file)) {
            fwrite(STDERR, "Error: Cannot read file: {$file}\n");
            exit(1);
        }

        static::execute($config, $file, $onlySlug, $force, $skipImages, $verbose);
    }

    /**
     * Programmatic entry point.
     *
     * @param string      $xmlFile    Path to the WordPress WXR export .xml file.
     * @param string|null $onlySlug   Import only the post with this slug.
     * @param bool        $force      Re-import posts that already exist on disk.
     * @param bool        $skipImages Skip downloading images (content src attributes are left as-is).
     */
    public static function execute(
        Config  $config,
        string  $xmlFile,
        ?string $onlySlug   = null,
        bool    $force       = false,
        bool    $skipImages  = false,
        bool    $verbose     = false
    ): void {
        if (!$skipImages) {
            self::requireCurl();
        }

        // ------------------------------------------------------------------
        // 1. Parse the WXR document
        // ------------------------------------------------------------------
        self::log($verbose, "Parsing {$xmlFile}...");

        $dom = new DOMDocument('1.0', 'UTF-8');

        if (!@$dom->load($xmlFile, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException("Failed to parse XML file: {$xmlFile}");
        }

        $xpath = new DOMXPath($dom);

        // Register namespaces used in WXR exports
        $xpath->registerNamespace('wp',      'http://wordpress.org/export/1.2/');
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xpath->registerNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');
        $xpath->registerNamespace('dc',      'http://purl.org/dc/elements/1.1/');

        // ------------------------------------------------------------------
        // 2. Build tag map: term_slug => name  (for post_tag terms)
        // ------------------------------------------------------------------
        self::log($verbose, 'Building tag map...');
        $tagMap = []; // [slug => name]

        foreach ($xpath->query('//channel/wp:tag') as $tagNode) {
            $slug = self::nodeText($xpath, 'wp:tag_slug', $tagNode);
            $name = self::nodeText($xpath, 'wp:tag_name', $tagNode);

            if ($slug !== '') {
                $tagMap[$slug] = $name !== '' ? $name : $slug;
            }
        }

        self::log($verbose, sprintf('  %d tag(s) found.', count($tagMap)));

        // ------------------------------------------------------------------
        // 3. Import categories
        // ------------------------------------------------------------------
        self::log($verbose, 'Importing categories...');
        $categoryMap = self::importCategories($xpath, $config, $force, $verbose);

        // ------------------------------------------------------------------
        // 4. Import posts
        // ------------------------------------------------------------------
        self::log($verbose, 'Importing posts...');
        self::importPosts($xpath, $config, $tagMap, $categoryMap, $onlySlug, $force, $skipImages, $verbose);

        self::log($verbose, 'Import complete.');
    }

    // -------------------------------------------------------------------------
    // Categories
    // -------------------------------------------------------------------------

    /**
     * Read all <wp:category> nodes, write one JSON file per category.
     * Returns a map of [wp_term_slug => slug].
     */
    private static function importCategories(
        DOMXPath $xpath,
        Config   $config,
        bool     $force,
        bool     $verbose
    ): array {
        $catDir      = rtrim($config->getCategoriesDir(), '/');
        $categoryMap = [];

        foreach ($xpath->query('//channel/wp:category') as $catNode) {
            $slug        = self::nodeText($xpath, 'wp:category_nicename', $catNode);
            $name        = self::nodeText($xpath, 'wp:cat_name', $catNode);
            $description = self::nodeText($xpath, 'wp:category_description', $catNode);

            if ($slug === '') {
                continue;
            }

            $categoryMap[$slug] = $slug;

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
                'title'       => self::decodeEntities($name),
                'slug'        => $slug,
                'description' => self::decodeEntities(strip_tags($description)),
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
        DOMXPath $xpath,
        Config   $config,
        array    $tagMap,
        array    $categoryMap,
        ?string  $onlySlug,
        bool     $force,
        bool     $skipImages,
        bool     $verbose
    ): void {
        $postsDir         = rtrim($config->getPostsDir(), '/');
        $sourceImagesBase = rtrim($config->getOriginalPostImagesDir(), '/');

        foreach ($xpath->query('//channel/item') as $item) {
            $postType = self::nodeText($xpath, 'wp:post_type', $item);

            if ($postType !== 'post') {
                continue;
            }

            $slug = self::nodeText($xpath, 'wp:post_name', $item);

            if ($onlySlug !== null && $slug !== $onlySlug) {
                continue;
            }

            self::importPost($item, $xpath, $config, $postsDir, $sourceImagesBase, $tagMap, $categoryMap, $force, $skipImages, $verbose);
        }
    }

    private static function importPost(
        \DOMNode $item,
        DOMXPath $xpath,
        Config   $config,
        string   $postsDir,
        string   $sourceImagesBase,
        array    $tagMap,
        array    $categoryMap,
        bool     $force,
        bool     $skipImages,
        bool     $verbose
    ): void {
        $slug    = self::nodeText($xpath, 'wp:post_name', $item);
        $status  = self::nodeText($xpath, 'wp:status', $item);
        $isDraft = $status !== 'publish';

        // Generate a slug from the title when WordPress has none (e.g. unsaved drafts)
        if ($slug === '') {
            $rawTitle = self::nodeText($xpath, 'title', $item);
            $slug     = SlugHelper::make(self::decodeEntities($rawTitle));
        }

        $publishedAt = self::nodeText($xpath, 'wp:post_date_gmt', $item);

        // Fall back to local date if GMT is missing
        if ($publishedAt === '' || str_starts_with($publishedAt, '0000')) {
            $publishedAt = self::nodeText($xpath, 'wp:post_date', $item);
        }

        $postDir  = self::resolvePostDir($postsDir, $slug, $publishedAt, $isDraft);
        $imageDir = self::resolveImageDir($postsDir, $postDir, $sourceImagesBase);
        $metaFile = $postDir . '/meta.json';
        $mdFile   = $postDir . '/post.md';

        if (file_exists($metaFile) && file_exists($mdFile) && !$force) {
            self::log($verbose, "  skip (exists): {$slug}");
            return;
        }

        if (!is_dir($postDir) && !mkdir($postDir, 0755, true)) {
            throw new RuntimeException("Could not create post directory: {$postDir}");
        }

        $title   = self::decodeEntities(self::nodeText($xpath, 'title', $item));
        $rawExcerpt = self::nodeText($xpath, 'excerpt:encoded', $item);
        $excerpt   = trim(preg_replace('/\s+/', ' ', self::decodeEntities(strip_tags($rawExcerpt))));
        $rawHtml   = self::nodeText($xpath, 'content:encoded', $item);

        // Tags (category nodes with domain="post_tag")
        $tags = [];

        foreach ($xpath->query('category[@domain="post_tag"]', $item) as $tagNode) {
            $nicename = $tagNode->getAttribute('nicename');
            $tags[]   = $tagMap[$nicename] ?? self::decodeEntities($tagNode->textContent);
        }

        $tags = array_values(array_unique($tags));

        // Categories (category nodes with domain="category")
        $categories = [];

        foreach ($xpath->query('category[@domain="category"]', $item) as $catNode) {
            $nicename = $catNode->getAttribute('nicename');

            if (isset($categoryMap[$nicename])) {
                $categories[] = $categoryMap[$nicename];
            }
        }

        $categories = array_values(array_unique($categories));

        // Featured image — WXR stores the attachment URL in wp:attachment_url on
        // a sibling <item wp:post_type="attachment"> linked by wp:post_parent.
        $postId           = (int)self::nodeText($xpath, 'wp:post_id', $item);
        $featuredId       = (int)self::nodeText($xpath, 'wp:postmeta[wp:meta_key="_thumbnail_id"]/wp:meta_value', $item);
        $featuredImagePath = null;

        if ($featuredId > 0 && !$skipImages) {
            if (!is_dir($imageDir) && !mkdir($imageDir, 0755, true)) {
                throw new RuntimeException("Could not create image directory: {$imageDir}");
            }

            $featuredImagePath = self::importFeaturedImage($xpath, $featuredId, $slug, $imageDir, $verbose);
        }

        // Content — clean HTML, download inline images
        $html = self::cleanHtml($rawHtml);

        if (!$skipImages) {
            if (!is_dir($imageDir) && !mkdir($imageDir, 0755, true)) {
                throw new RuntimeException("Could not create image directory: {$imageDir}");
            }

            $html = self::downloadInlineImages($html, $slug, $imageDir, $verbose);
        }

        // Comments
        $comments = self::extractComments($xpath, $item);

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
            'publishedAt' => $publishedAt !== '' ? $publishedAt : null,
        ];

        file_put_contents(
            $metaFile,
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Write post.md
        file_put_contents($mdFile, $html . "\n");

        // Write comments.json (only if there are comments)
        if (!empty($comments)) {
            file_put_contents(
                $postDir . '/comments.json',
                json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        self::log($verbose, sprintf(
            '  Post: %s (%s)%s%s',
            $title,
            $slug,
            $isDraft ? ' [draft]' : '',
            !empty($comments) ? sprintf(' [%d comment(s)]', count($comments)) : ''
        ));
    }

    // -------------------------------------------------------------------------
    // Comments
    // -------------------------------------------------------------------------

    /**
     * Extract approved comments from a <item> node.
     * Returns an array of comment objects sorted by date ascending.
     */
    private static function extractComments(DOMXPath $xpath, \DOMNode $item): array
    {
        $comments = [];

        foreach ($xpath->query('wp:comment', $item) as $commentNode) {
            $approved = self::nodeText($xpath, 'wp:comment_approved', $commentNode);

            // Only import approved (non-spam, non-trash) comments
            if ($approved !== '1') {
                continue;
            }

            $type = self::nodeText($xpath, 'wp:comment_type', $commentNode);

            // Skip pingbacks and trackbacks
            if (in_array($type, ['pingback', 'trackback'], true)) {
                continue;
            }

            $comments[] = [
                'id'        => (int)self::nodeText($xpath, 'wp:comment_id', $commentNode),
                'parentId'  => (int)self::nodeText($xpath, 'wp:comment_parent', $commentNode) ?: null,
                'author'    => self::decodeEntities(self::nodeText($xpath, 'wp:comment_author', $commentNode)),
                'authorUrl' => self::nodeText($xpath, 'wp:comment_author_url', $commentNode) ?: null,
                'date'      => self::nodeText($xpath, 'wp:comment_date_gmt', $commentNode) ?: null,
                'content'   => trim(self::nodeText($xpath, 'wp:comment_content', $commentNode)),
            ];
        }

        usort($comments, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));

        return $comments;
    }

    // -------------------------------------------------------------------------
    // Featured image
    // -------------------------------------------------------------------------

    /**
     * Look up the attachment item for $featuredId and download its source URL.
     * Returns the expected public path or null on failure.
     */
    private static function importFeaturedImage(
        DOMXPath $xpath,
        int      $featuredId,
        string   $slug,
        string   $imageDir,
        bool     $verbose
    ): ?string {
        $attachmentNodes = $xpath->query(
            sprintf('//channel/item[wp:post_type="attachment" and wp:post_id="%d"]', $featuredId)
        );

        if ($attachmentNodes === false || $attachmentNodes->length === 0) {
            fwrite(STDERR, "  [warn] Featured image attachment {$featuredId} not found in XML for '{$slug}'.\n");
            return null;
        }

        $attachment = $attachmentNodes->item(0);
        $srcUrl     = self::nodeText($xpath, 'wp:attachment_url', $attachment);

        if ($srcUrl === '') {
            return null;
        }

        $basename = basename(parse_url($srcUrl, PHP_URL_PATH));

        if (!self::downloadFile($srcUrl, $imageDir . '/' . $basename, $verbose)) {
            return null;
        }

        return '/images/posts/' . $slug . '/' . $basename;
    }

    // -------------------------------------------------------------------------
    // Inline image downloading
    // -------------------------------------------------------------------------

    /**
     * Find all <img> tags in the HTML, download each image, and rewrite src
     * to /images/posts/{slug}/{basename}.
     */
    private static function downloadInlineImages(string $html, string $slug, string $imageDir, bool $verbose): string
    {
        if (trim($html) === '' || !str_contains($html, '<img')) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
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

            if (self::downloadFile($src, $imageDir . '/' . $basename, $verbose)) {
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

        // Strip WordPress block editor comments (<!-- wp:tag ... --> / <!-- /wp:tag -->)
        // These are not parseable by DOMDocument and would pass through unchanged.
        $html = preg_replace('/<!--\s*\/?wp:[^>]*-->/s', '', $html) ?? $html;

        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML(
            '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new DOMXPath($dom);

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

        // Replace WordPress slideshow blocks with <image-carousel> custom elements
        foreach ($xpath->query('//div[@data-effect="slide"]') as $slideDiv) {
            $carousel = $dom->createElement('image-carousel');

            foreach ($xpath->query('.//li/figure', $slideDiv) as $figure) {
                foreach ($xpath->query('.//img', $figure) as $img) {
                    $img->setAttribute('loading', 'lazy');
                    $img->removeAttribute('data-id');
                }

                $carousel->appendChild($figure->cloneNode(true));
            }

            $slideDiv->parentNode->replaceChild($carousel, $slideDiv);
        }

        $body   = $dom->getElementsByTagName('body')->item(0);
        $output = '';

        foreach ($body->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim(preg_replace(['/^[ \t]+$/m', '/\n{2,}/'], ['', "\n\n"], $output));
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

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
    // XML helpers
    // -------------------------------------------------------------------------

    /**
     * Get the trimmed text content of the first matching child node.
     * Returns '' if the node does not exist.
     */
    private static function nodeText(DOMXPath $xpath, string $query, \DOMNode $context): string
    {
        $nodes = $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim($nodes->item(0)->textContent);
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    private static function requireCurl(): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                "ext-curl is required for downloading images. Enable it in your php.ini, or pass --skip-images."
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

    /**
     * Resolve the on-disk post directory path.
     * New imports go to posts/YYYY/MM/{slug}. Falls back to posts/{slug} if the
     * date cannot be parsed (e.g. drafts with a 0000-00-00 date).
     */
    private static function resolvePostDir(string $postsDir, string $slug, string $publishedAt, bool $isDraft): string
    {
        // Keep an existing legacy flat directory to avoid creating duplicates
        $legacyDir = $postsDir . '/' . $slug;

        if (is_dir($legacyDir)) {
            return $legacyDir;
        }

        if ($isDraft) {
            return $postsDir . '/drafts/' . $slug;
        }

        $dt = self::parseWpDate($publishedAt);

        // Published posts with no parseable date fall back to the drafts folder
        if ($dt === null) {
            return $postsDir . '/drafts/' . $slug;
        }

        return sprintf('%s/%s/%s/%s', $postsDir, $dt->format('Y'), $dt->format('m'), $slug);
    }

    /**
     * Resolve image directory by mirroring the post directory structure.
     */
    private static function resolveImageDir(string $postsDir, string $postDir, string $sourceImagesBase): string
    {
        $postsDir = rtrim($postsDir, '/');
        $postDir  = rtrim($postDir, '/');

        if (str_starts_with($postDir, $postsDir . '/')) {
            $relativePostDir = substr($postDir, strlen($postsDir) + 1);
            return $sourceImagesBase . '/' . $relativePostDir;
        }

        return $sourceImagesBase . '/' . basename($postDir);
    }

    private static function parseWpDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function decodeEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function log(bool $verbose, string $message): void
    {
        if ($verbose) {
            echo $message . "\n";
        }
    }
}

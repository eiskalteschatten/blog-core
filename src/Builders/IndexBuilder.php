<?php

declare(strict_types=1);

namespace BlogCore\Builders;

use BlogCore\Core\Config;
use BlogCore\Core\Database;
use BlogCore\Core\SchemaManager;
use BlogCore\Models\Category;
use BlogCore\Models\Post;
use BlogCore\Models\PostCategoryMapper;
use BlogCore\Models\PostTagMapper;
use BlogCore\Models\Tag;
use BlogCore\Commands\ProcessImagesCommand;
use BlogCore\Commands\PublishAssetsCommand;
use BlogCore\Helpers\FeedHelper;
use BlogCore\Helpers\SitemapHelper;
use BlogCore\Parsers\CategoryParser;
use BlogCore\Parsers\PostParser;
use RuntimeException;

class IndexBuilder
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Full re-index: ensures the schema exists, then syncs all categories and
     * posts from the filesystem to SQLite.
     *
     * Safe to run repeatedly — all writes are upserts.
     */
    public function build(bool $verbose = false): void
    {
        Database::init($this->config->getStoragePath());
        SchemaManager::migrate();

        $this->log($verbose, "Schema ready.");

        $this->indexCategories($verbose);
        $this->indexPosts($verbose);
        $this->writeFeed($verbose);
        $this->writeSitemap($verbose);
        $this->processImages($verbose);
        $this->rewriteContentImagePaths($verbose);
        $this->publishAssets($verbose);

        $this->log($verbose, "Index complete.");
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function indexCategories(bool $verbose): void
    {
        $categories = CategoryParser::parseAll($this->config->getCategoriesDir());
        $this->log($verbose, sprintf("Found %d category file(s).", count($categories)));

        foreach ($categories as $data) {
            Category::upsertFromData($data);
            $this->log($verbose, "  Category: {$data['title']} ({$data['slug']})");
        }
    }

    private function indexPosts(bool $verbose): void
    {
        $posts = PostParser::parseAll($this->config->getPostsDir());
        $this->log($verbose, sprintf("Found %d post(s).", count($posts)));

        foreach ($posts as $data) {
            $postId = Post::upsertFromData($data);

            // Re-link categories and tags from scratch on every index run
            PostCategoryMapper::unlinkPost($postId);
            PostTagMapper::unlinkPost($postId);

            // Link categories
            foreach ($data['categories'] as $stringId) {
                $category = Category::findByStringId($stringId);
                if ($category) {
                    PostCategoryMapper::link($postId, (int)$category['id']);
                } else {
                    $this->log($verbose, "    [warn] Category '{$stringId}' not found for post '{$data['slug']}'.");
                }
            }

            // Link tags (create on the fly if needed)
            foreach ($data['tags'] as $tagName) {
                if (trim($tagName) === '') {
                    continue;
                }
                $tagId = Tag::findOrCreate($tagName);
                PostTagMapper::link($postId, $tagId);
            }

            $draft = $data['is_draft'] ? ' [draft]' : '';
            $this->log($verbose, "  Post: {$data['title']} ({$data['slug']}){$draft}");
        }
    }

    private function publishAssets(bool $verbose): void
    {
        $this->log($verbose, 'Publishing core assets...');
        PublishAssetsCommand::execute($this->config, $verbose);
    }

    private function processImages(bool $verbose): void
    {
        $sizes = $this->config->getImageSizes();

        if (empty($sizes)) {
            $this->log($verbose, "Image processing disabled (getImageSizes() is empty).");
            return;
        }

        $this->log($verbose, "Processing post images...");
        ProcessImagesCommand::execute($this->config, $verbose);
    }

    private function rewriteContentImagePaths(bool $verbose): void
    {
        $width = $this->config->getContentImageWidth();

        if ($width === null) {
            return;
        }

        $publicDir  = rtrim($this->config->getPublicDir(), '/');
        $outputBase = $publicDir . '/images/posts';

        $db    = Database::getConnection();
        $posts = $db->query('SELECT id, slug, content_html FROM posts')->fetchAll(\PDO::FETCH_ASSOC);

        $rewritten = 0;

        foreach ($posts as $post) {
            $html = $post['content_html'];

            if (empty($html)) {
                continue;
            }

            $slug = $post['slug'];

            $doc = new \DOMDocument();
            // Suppress warnings from malformed HTML; use encoding hint so multibyte text is preserved
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            $imgs    = $doc->getElementsByTagName('img');
            $changed = false;

            foreach ($imgs as $img) {
                $src = $img->getAttribute('src');

                // Skip external URLs and empty values
                if (
                    $src === '' ||
                    str_starts_with($src, 'http://') ||
                    str_starts_with($src, 'https://') ||
                    str_starts_with($src, '//')
                ) {
                    continue;
                }

                $stem       = pathinfo(basename($src), PATHINFO_FILENAME);
                $publicPath = '/images/posts/' . $slug . '/' . $stem . '-' . $width . '.webp';
                $fsPath     = $outputBase . '/' . $slug . '/' . $stem . '-' . $width . '.webp';

                if (!file_exists($fsPath)) {
                    continue;
                }

                $img->setAttribute('src', $publicPath);
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            // Serialize only the body contents — strip the wrapper DOMDocument adds
            $newHtml = '';
            foreach ($doc->childNodes as $node) {
                $newHtml .= $doc->saveHTML($node);
            }
            // Remove the encoding hint we injected
            $newHtml = str_replace('<?xml encoding="UTF-8">', '', $newHtml);

            Post::updateContentHtml((int)$post['id'], $newHtml);
            $rewritten++;
        }

        $this->log($verbose, "Rewrote content image paths in {$rewritten} post(s) (width: {$width}px).");
    }

    private function writeFeed(bool $verbose): void
    {
        $publicDir = rtrim($this->config->getPublicDir(), '/');

        if (!is_dir($publicDir)) {
            throw new RuntimeException("Public directory not found: {$publicDir}");
        }

        $posts = Post::published()
            ->orderBy('published_at', 'DESC')
            ->limit(35)
            ->get();

        $path = $publicDir . '/feed.xml';
        $xml  = FeedHelper::generate($posts, $this->config);

        if (file_put_contents($path, $xml) === false) {
            throw new RuntimeException("Could not write feed to: {$path}");
        }

        $this->log($verbose, "Feed written to {$path}");
    }

    private function writeSitemap(bool $verbose): void
    {
        $publicDir = rtrim($this->config->getPublicDir(), '/');

        if (!is_dir($publicDir)) {
            throw new RuntimeException("Public directory not found: {$publicDir}");
        }

        $path = $publicDir . '/sitemap.xml';
        $xml  = SitemapHelper::generate($this->config);

        if (file_put_contents($path, $xml) === false) {
            throw new RuntimeException("Could not write sitemap to: {$path}");
        }

        $this->log($verbose, "Sitemap written to {$path}");
    }

    private function log(bool $verbose, string $message): void
    {
        if ($verbose) {
            echo $message . PHP_EOL;
        }
    }
}

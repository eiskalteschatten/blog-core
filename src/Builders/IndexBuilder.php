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
        $this->writeSitemap($verbose);

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

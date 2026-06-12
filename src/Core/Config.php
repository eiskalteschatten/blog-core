<?php

declare(strict_types=1);

namespace BlogCore\Core;

abstract class Config
{
    /**
     * The human-readable title of the site.
     */
    abstract public function getSiteTitle(): string;

    /**
     * The fully-qualified base URL of the site, no trailing slash.
     * e.g. "https://example.com"
     */
    abstract public function getSiteUrl(): string;

    /**
     * Absolute path to the directory containing post sub-directories.
     * Each sub-directory must contain post.md and meta.json.
     */
    abstract public function getPostsDir(): string;

    /**
     * Absolute path to the directory containing category JSON files.
     */
    abstract public function getCategoriesDir(): string;

    /**
     * Absolute path to the SQLite database file (will be created if absent).
     * e.g. /var/www/myapp/storage/blog.sqlite
     */
    abstract public function getStoragePath(): string;

    /**
     * Absolute path to the directory containing view templates.
     */
    abstract public function getViewsDir(): string;

    /**
     * Number of posts per paginated page. Override to change the default.
     */
    public function getPostsPerPage(): int
    {
        return 12;
    }

    /**
     * Optional URL prefix for all blog routes. No trailing slash.
     * e.g. "" (root) or "/blog"
     */
    public function getRoutePrefix(): string
    {
        return '';
    }

    /**
     * Static pages to include in the sitemap.
     * Return an array of ['loc' => '/about', 'changefreq' => 'monthly', 'priority' => '0.5'].
     */
    public function getStaticPages(): array
    {
        return [];
    }
}

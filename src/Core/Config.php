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
     * Absolute path to the directory containing post directories.
     * Post directories are discovered recursively and must contain post.md and meta.json.
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
     * Absolute path to the publicly served directory (e.g. /var/www/myapp/public).
     * The build command writes sitemap.xml here so it is served as a static file.
     */
    abstract public function getPublicDir(): string;

    /**
     * Absolute path where blog-core's bundled assets are published.
     * Defaults to {publicDir}/assets/blog-core/ — override to change the location.
     */
    public function getPublicAssetsDir(): string
    {
        return $this->getPublicDir() . '/blog-core';
    }

    /**
     * Absolute path to original source images used for post image processing.
     *
        * Images are expected in a directory tree that mirrors getPostsDir(), e.g.
        * {originalPostImagesDir}/YYYY/MM/{slug}/.
        *
        * Processed outputs are written under public/images/posts/{slug}/.
     *
     * Defaults to getPostsDir() for simple setups.
     */
    public function getOriginalPostImagesDir(): string
    {
        return $this->getPostsDir();
    }

    /**
     * Image widths (in pixels) to generate when processing post images.
     * Each source image is converted to WebP and resized to every listed width
     * (images are never enlarged). Output goes to public/images/posts/{slug}/.
     * Return an empty array to disable image processing entirely.
     */
    public function getImageSizes(): array
    {
        return [800, 1600];
    }

    /**
     * The image width (in pixels) to use when rewriting <img> src paths in
     * post content HTML after image processing. Must be one of the widths
     * returned by getImageSizes(). Return null to disable content image rewriting.
     */
    public function getContentImageWidth(): ?int
    {
        return 1600;
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

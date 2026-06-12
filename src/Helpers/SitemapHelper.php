<?php

declare(strict_types=1);

namespace BlogCore\Helpers;

use BlogCore\Core\Config;
use BlogCore\Models\Category;
use BlogCore\Models\Post;

class SitemapHelper
{
    /**
     * Generate a sitemap XML string containing:
     *  - Static pages defined in Config::getStaticPages()
     *  - All published posts
     *  - All categories
     */
    public static function generate(Config $config): string
    {
        $siteUrl = rtrim($config->getSiteUrl(), '/');

        $urls = '';

        // Static pages
        foreach ($config->getStaticPages() as $page) {
            $loc        = htmlspecialchars($siteUrl . $page['loc'], ENT_XML1);
            $changefreq = htmlspecialchars($page['changefreq'] ?? 'monthly', ENT_XML1);
            $priority   = htmlspecialchars($page['priority']   ?? '0.5', ENT_XML1);
            $urls .= self::urlEntry($loc, null, $changefreq, $priority);
        }

        // Posts index
        $urls .= self::urlEntry(htmlspecialchars($siteUrl . '/posts', ENT_XML1), null, 'daily', '0.8');

        // Individual published posts
        $posts = Post::published()->orderBy('published_at', 'DESC')->get();
        foreach ($posts as $post) {
            $loc     = htmlspecialchars($siteUrl . '/posts/' . $post['slug'], ENT_XML1);
            $lastmod = !empty($post['updated_at']) ? date('Y-m-d', strtotime($post['updated_at'])) : null;
            $urls .= self::urlEntry($loc, $lastmod, 'monthly', '0.7');
        }

        // Categories index
        $urls .= self::urlEntry(htmlspecialchars($siteUrl . '/categories', ENT_XML1), null, 'weekly', '0.6');

        // Individual categories
        $categories = Category::all();
        foreach ($categories as $cat) {
            $loc  = htmlspecialchars($siteUrl . '/categories/' . $cat['slug'], ENT_XML1);
            $urls .= self::urlEntry($loc, null, 'weekly', '0.6');
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
{$urls}</urlset>
XML;
    }

    private static function urlEntry(
        string  $loc,
        ?string $lastmod,
        string  $changefreq,
        string  $priority
    ): string {
        $lastmodTag = $lastmod ? "        <lastmod>{$lastmod}</lastmod>\n" : '';
        return <<<XML
    <url>
        <loc>{$loc}</loc>
{$lastmodTag}        <changefreq>{$changefreq}</changefreq>
        <priority>{$priority}</priority>
    </url>

XML;
    }
}

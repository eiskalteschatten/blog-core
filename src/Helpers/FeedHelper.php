<?php

declare(strict_types=1);

namespace BlogCore\Helpers;

use BlogCore\Core\Config;

class FeedHelper
{
    /**
     * Generate an RSS 2.0 XML feed from an array of post rows.
     *
     * @param array  $posts  Array of post rows (keys: title, slug, description,
     *                       content_html, published_at, image).
     * @param Config $config Site configuration for URLs and title.
     */
    public static function generate(array $posts, Config $config): string
    {
        $siteUrl   = rtrim($config->getSiteUrl(), '/');
        $siteTitle = htmlspecialchars($config->getSiteTitle(), ENT_XML1);
        $feedUrl   = htmlspecialchars($siteUrl . '/feeds/posts.xml', ENT_XML1);
        $homeUrl   = htmlspecialchars($siteUrl, ENT_XML1);
        $buildDate = htmlspecialchars(date(DATE_RSS), ENT_XML1);

        $items = '';
        foreach ($posts as $post) {
            $title       = htmlspecialchars((string)($post['title'] ?? ''), ENT_XML1);
            $link        = htmlspecialchars($siteUrl . '/posts/' . $post['slug'], ENT_XML1);
            $description = htmlspecialchars(
                strip_tags((string)($post['description'] ?? $post['content_html'] ?? '')),
                ENT_XML1
            );
            $pubDate = '';
            if (!empty($post['published_at'])) {
                $ts = strtotime($post['published_at']);
                if ($ts !== false) {
                    $pubDate = '<pubDate>' . htmlspecialchars(date(DATE_RSS, $ts), ENT_XML1) . '</pubDate>';
                }
            }

            $items .= <<<XML

        <item>
            <title>{$title}</title>
            <link>{$link}</link>
            <guid isPermaLink="true">{$link}</guid>
            <description>{$description}</description>
            {$pubDate}
        </item>
XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{$siteTitle}</title>
        <link>{$homeUrl}</link>
        <description>{$siteTitle}</description>
        <lastBuildDate>{$buildDate}</lastBuildDate>
        <atom:link href="{$feedUrl}" rel="self" type="application/rss+xml"/>
        {$items}
    </channel>
</rss>
XML;
    }
}

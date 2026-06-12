<?php

declare(strict_types=1);

namespace BlogCore\Parsers;

use Parsedown;

class MarkdownParser
{
    private static ?Parsedown $instance = null;

    private static function getInstance(): Parsedown
    {
        if (self::$instance === null) {
            self::$instance = new Parsedown();
            self::$instance->setSafeMode(false); // allow raw HTML in posts
        }
        return self::$instance;
    }

    /**
     * Convert a Markdown string to HTML.
     */
    public static function toHtml(string $markdown): string
    {
        return self::getInstance()->text($markdown);
    }
}

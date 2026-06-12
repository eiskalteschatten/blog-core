<?php

declare(strict_types=1);

namespace BlogCore\Helpers;

class SlugHelper
{
    /**
     * Generate a URL-safe slug from a string.
     *
     * Examples:
     *   "Hello World!" → "hello-world"
     *   "Foo  Bar--Baz" → "foo-bar-baz"
     */
    public static function make(string $text): string
    {
        // Transliterate non-ASCII characters
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text) ?: $text;

        // Lowercase
        $text = strtolower($text);

        // Replace anything that is not a letter, digit, or hyphen with a hyphen
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;

        // Remove leading/trailing hyphens
        $text = trim($text, '-');

        return $text;
    }
}

<?php

declare(strict_types=1);

namespace BlogCore\Parsers;

use RuntimeException;

class CategoryParser
{
    /**
     * Scan $categoriesDir for *.json files and return an array of category
     * data arrays, each ready for Category::upsertFromData().
     */
    public static function parseAll(string $categoriesDir): array
    {
        if (!is_dir($categoriesDir)) {
            throw new RuntimeException("Categories directory not found: {$categoriesDir}");
        }

        $categories = [];

        foreach (glob($categoriesDir . '/*.json') as $file) {
            try {
                $categories[] = self::parseOne($file);
            } catch (RuntimeException $e) {
                fwrite(STDERR, "[CategoryParser] Skipping {$file}: {$e->getMessage()}\n");
            }
        }

        return $categories;
    }

    /**
     * Parse a single category JSON file.
     *
     * @throws RuntimeException if the file is missing or invalid.
     */
    public static function parseOne(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Category file not found: {$filePath}");
        }

        $data = json_decode(file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new RuntimeException("Not a valid JSON object: {$filePath}");
        }

        if (empty($data['id'])) {
            throw new RuntimeException("Category JSON missing 'id': {$filePath}");
        }

        if (empty($data['title'])) {
            throw new RuntimeException("Category JSON missing 'title': {$filePath}");
        }

        if (empty($data['slug'])) {
            throw new RuntimeException("Category JSON missing 'slug': {$filePath}");
        }

        return [
            'id'          => (string)$data['id'],
            'title'       => (string)$data['title'],
            'slug'        => (string)$data['slug'],
            'description' => isset($data['description']) ? (string)$data['description'] : null,
            'image'       => isset($data['image'])        ? (string)$data['image']        : null,
            'featured'    => (bool)($data['featured'] ?? false),
        ];
    }
}

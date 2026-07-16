<?php

declare(strict_types=1);

namespace BlogCore\Parsers;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class PostParser
{
    /**
     * Scan $postsDir for sub-directories, parse each one, and return an array
     * of post data arrays.
     */
    public static function parseAll(string $postsDir): array
    {
        if (!is_dir($postsDir)) {
            throw new RuntimeException("Posts directory not found: {$postsDir}");
        }

        $posts = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($postsDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }

            $postDir  = $entry->getPathname();
            $metaPath = $postDir . '/meta.json';
            $mdPath   = $postDir . '/post.md';

            // A directory is considered a post directory only if it has both files.
            if (!file_exists($metaPath) || !file_exists($mdPath)) {
                continue;
            }

            try {
                $posts[] = self::parseOne($postDir);
            } catch (RuntimeException $e) {
                // Log and skip malformed post directories
                fwrite(STDERR, "[PostParser] Skipping {$postDir}: {$e->getMessage()}\n");
            }
        }

        return $posts;
    }

    /**
     * Parse a single post directory. Returns a post data array ready for
     * PostModel::upsertFromData().
     *
     * @throws RuntimeException if meta.json or post.md is missing/invalid.
     */
    public static function parseOne(string $dir): array
    {
        $metaPath = $dir . '/meta.json';
        $mdPath   = $dir . '/post.md';

        if (!file_exists($metaPath)) {
            throw new RuntimeException("meta.json not found in {$dir}");
        }

        if (!file_exists($mdPath)) {
            throw new RuntimeException("post.md not found in {$dir}");
        }

        $meta = json_decode(file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($meta)) {
            throw new RuntimeException("meta.json is not a valid JSON object in {$dir}");
        }

        if (empty($meta['slug'])) {
            throw new RuntimeException("meta.json is missing 'slug' in {$dir}");
        }

        if (empty($meta['title'])) {
            throw new RuntimeException("meta.json is missing 'title' in {$dir}");
        }

        $markdown    = file_get_contents($mdPath);
        $contentHtml = MarkdownParser::toHtml($markdown);
        $comments    = self::parseComments($dir);

        return [
            'title'        => (string)$meta['title'],
            'slug'         => (string)$meta['slug'],
            'description'  => isset($meta['description'])  ? (string)$meta['description']  : null,
            'image'        => isset($meta['image'])         ? (string)$meta['image']         : null,
            'content_html' => $contentHtml,
            'is_draft'     => (bool)($meta['draft']    ?? false),
            'featured'     => (bool)($meta['featured'] ?? false),
            'published_at' => isset($meta['publishedAt']) ? (string)$meta['publishedAt'] : null,
            'tags'         => array_values(array_filter(array_map('strval', (array)($meta['tags'] ?? [])))),
            'categories'   => array_values(array_filter(array_map('strval', (array)($meta['categories'] ?? [])))),
            'comments'     => $comments,
        ];
    }

    /**
     * Parse optional comments.json in a post directory.
     * Returns normalized comment records; malformed entries are skipped.
     */
    private static function parseComments(string $dir): array
    {
        return self::readCommentsFile($dir . '/comments.json', 'comments.json');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function readCommentsFile(string $path, string $label): array
    {
        $dir = dirname($path);

        if (!file_exists($path)) {
            return [];
        }

        try {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[PostParser] Invalid {$label} in {$dir}: {$e->getMessage()}\n");
            return [];
        }

        if (!is_array($data)) {
            fwrite(STDERR, "[PostParser] Invalid {$label} in {$dir}: expected a JSON array or object\n");
            return [];
        }

        if (!array_is_list($data)) {
            $data = $data['comments'] ?? [];
        }

        if (!is_array($data)) {
            fwrite(STDERR, "[PostParser] Invalid {$label} in {$dir}: expected 'comments' to be an array\n");
            return [];
        }

        $comments = [];

        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int)($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $comments[] = [
                'id'              => $id,
                'parentId'        => isset($row['parentId']) ? (int)$row['parentId'] : null,
                'parentCommentId' => isset($row['parentCommentId'])
                    ? (int)$row['parentCommentId']
                    : (isset($row['parent_comment_id']) ? (int)$row['parent_comment_id'] : null),
                'author'          => (string)($row['author'] ?? ''),
                'authorUrl'       => isset($row['authorUrl']) && $row['authorUrl'] !== '' ? (string)$row['authorUrl'] : null,
                'date'            => isset($row['date']) && $row['date'] !== '' ? (string)$row['date'] : null,
                'content'         => (string)($row['content'] ?? ''),
            ];
        }

        return $comments;
    }
}

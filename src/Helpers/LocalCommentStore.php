<?php

declare(strict_types=1);

namespace BlogCore\Helpers;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class LocalCommentStore
{
    /**
     * Append a new local comment for a post slug using lock + atomic replace.
     *
     * @return array<string,mixed> The stored comment payload.
     */
    public static function appendForPostSlug(string $postsDir, string $slug, array $comment): array
    {
        $postDir = self::findPostDirBySlug($postsDir, $slug);

        if ($postDir === null) {
            throw new RuntimeException("Post directory not found for slug '{$slug}'.");
        }

        return self::appendToPostDir($postDir, $comment);
    }

    /**
     * @return array<string,mixed>
     */
    private static function appendToPostDir(string $postDir, array $comment): array
    {
        $commentsPath = $postDir . '/comments-local.json';

        $lockHandle = self::openLockHandle($commentsPath);

        if ($lockHandle === false) {
            throw new RuntimeException("Could not open lock handle for comments file: {$commentsPath}");
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException("Could not acquire comment lock for post directory: {$postDir}");
            }

            $state = self::readState($commentsPath);

            $newComment = [
                'id'       => self::nextCommentId((array)($state['comments'] ?? [])),
                'parentId' => isset($comment['parentId']) ? (int)$comment['parentId'] : null,
                'author'   => (string)($comment['author'] ?? 'Anonymous'),
                'authorUrl'=> isset($comment['authorUrl']) && (string)$comment['authorUrl'] !== ''
                    ? (string)$comment['authorUrl']
                    : null,
                'date'     => (string)($comment['date'] ?? gmdate('Y-m-d H:i:s')),
                'content'  => (string)($comment['content'] ?? ''),
            ];

            $state['revision'] = (int)($state['revision'] ?? 0) + 1;
            $state['comments'][] = $newComment;

            self::writeStateAtomically($commentsPath, $state);

            flock($lockHandle, LOCK_UN);

            return $newComment;
        } finally {
            fclose($lockHandle);
        }
    }

    /**
     * @return resource|false
     */
    private static function openLockHandle(string $commentsPath)
    {
        $locksDir = rtrim(sys_get_temp_dir(), '/') . '/blog-core-comment-locks';

        if (!is_dir($locksDir) && !@mkdir($locksDir, 0777, true) && !is_dir($locksDir)) {
            throw new RuntimeException("Could not create lock directory: {$locksDir}");
        }

        $lockPath = $locksDir . '/' . hash('sha256', $commentsPath) . '.lock';

        return fopen($lockPath, 'c+');
    }

    /**
     * @return array{revision:int,comments:array<int,array<string,mixed>>}
     */
    private static function readState(string $commentsPath): array
    {
        if (!file_exists($commentsPath)) {
            return ['revision' => 0, 'comments' => []];
        }

        $raw = file_get_contents($commentsPath);

        if ($raw === false) {
            throw new RuntimeException("Could not read local comments file: {$commentsPath}");
        }

        if (trim($raw) === '') {
            return ['revision' => 0, 'comments' => []];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid local comments file format: {$commentsPath}");
        }

        // Backward compatibility: allow a raw array of comments.
        if (array_is_list($decoded)) {
            return [
                'revision' => 0,
                'comments' => $decoded,
            ];
        }

        $comments = $decoded['comments'] ?? [];

        if (!is_array($comments)) {
            throw new RuntimeException("Invalid 'comments' payload in {$commentsPath}");
        }

        return [
            'revision' => (int)($decoded['revision'] ?? 0),
            'comments' => $comments,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $comments
     */
    private static function nextCommentId(array $comments): int
    {
        $usedIds = [];

        foreach ($comments as $comment) {
            if (!is_array($comment)) {
                continue;
            }

            $id = (int)($comment['id'] ?? 0);

            if ($id > 0) {
                $usedIds[$id] = true;
            }
        }

        // Keep local comment ids in a high range to avoid collisions with imported IDs.
        do {
            $id = random_int(800000000000000000, 899999999999999999);
        } while (isset($usedIds[$id]));

        return $id;
    }

    /**
     * @param array{revision:int,comments:array<int,array<string,mixed>>} $state
     */
    private static function writeStateAtomically(string $commentsPath, array $state): void
    {
        $tmpPath = $commentsPath . '.tmp.' . bin2hex(random_bytes(6));

        $json = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";

        if (file_put_contents($tmpPath, $json) === false) {
            throw new RuntimeException("Could not write temp comments file: {$tmpPath}");
        }

        $tmpHandle = fopen($tmpPath, 'r+');
        if ($tmpHandle !== false) {
            fflush($tmpHandle);
            if (function_exists('fsync')) {
                fsync($tmpHandle);
            }
            fclose($tmpHandle);
        }

        if (!rename($tmpPath, $commentsPath)) {
            @unlink($tmpPath);
            throw new RuntimeException("Could not move temp comments file into place: {$commentsPath}");
        }
    }

    private static function findPostDirBySlug(string $postsDir, string $slug): ?string
    {
        if (!is_dir($postsDir)) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($postsDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }

            $dir      = $entry->getPathname();
            $metaPath = $dir . '/meta.json';
            $mdPath   = $dir . '/post.md';

            if (!file_exists($metaPath) || !file_exists($mdPath)) {
                continue;
            }

            $metaRaw = file_get_contents($metaPath);
            if ($metaRaw === false) {
                continue;
            }

            try {
                $meta = json_decode($metaRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($meta)) {
                continue;
            }

            if ((string)($meta['slug'] ?? '') === $slug) {
                return $dir;
            }
        }

        return null;
    }
}

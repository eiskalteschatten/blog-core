<?php

declare(strict_types=1);

namespace BlogCore\Models;

use BlogCore\Core\Database;
use BlogCore\Core\Model;
use BlogCore\Core\QueryBuilder;
use RuntimeException;

class Comment extends Model
{
    protected static string $table = 'comments';

    /**
     * Create or update a comment by (post_id, external comment id).
     *
     * Supports either:
     * - wp_comment_id / parent_wp_comment_id (legacy WordPress naming), or
     * - comment_id / parent_id (generic naming for non-WordPress sources).
     */
    public static function upsertFromData(array $data): int
    {
        $commentId = (int)($data['comment_id'] ?? $data['wp_comment_id'] ?? 0);

        if ($commentId <= 0) {
            throw new RuntimeException('Comment id is required and must be > 0.');
        }

        return static::upsert(
            [
                'post_id'       => (int)$data['post_id'],
                'wp_comment_id' => $commentId,
            ],
            [
                'parent_wp_comment_id' => isset($data['parent_id'])
                    ? ((int)$data['parent_id'] ?: null)
                    : (isset($data['parent_wp_comment_id']) ? ((int)$data['parent_wp_comment_id'] ?: null) : null),
                'parent_comment_id'    => isset($data['parent_comment_id']) ? ((int)$data['parent_comment_id'] ?: null) : null,
                'author'               => $data['author'] ?? '',
                'author_url'           => $data['author_url'] ?? null,
                'comment_date'         => $data['comment_date'] ?? null,
                'content'              => $data['content'] ?? '',
            ]
        );
    }

    /** Return all comments for a post ordered by comment date. */
    public static function forPost(int $postId): QueryBuilder
    {
        return static::query()
            ->where('post_id', $postId)
            ->orderBy('comment_date', 'ASC');
    }

    /**
     * Resolve parent_comment_id by matching parent_wp_comment_id within a post.
     * This supports imported and incoming comments that provide external ids.
     */
    public static function resolveParentLinksForPost(int $postId): void
    {
        $db = Database::getConnection();

        $sql = "
            UPDATE comments AS child
            SET parent_comment_id = (
                SELECT parent.id
                FROM comments AS parent
                WHERE parent.post_id = child.post_id
                  AND parent.wp_comment_id = child.parent_wp_comment_id
                LIMIT 1
            )
            WHERE child.post_id = :post_id
              AND child.parent_wp_comment_id IS NOT NULL
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([':post_id' => $postId]);
    }

    /** A comment belongs to exactly one post. */
    public static function post(int $commentId): ?array
    {
        $comment = static::find($commentId);

        if (!$comment) {
            return null;
        }

        return Post::find((int)$comment['post_id']);
    }

    /** Return a comment's parent comment, if any. */
    public static function parent(int $commentId): ?array
    {
        $comment = static::find($commentId);

        if (!$comment || empty($comment['parent_comment_id'])) {
            return null;
        }

        return static::find((int)$comment['parent_comment_id']);
    }

    /** Return all direct child comments for a parent comment. */
    public static function children(int $commentId): array
    {
        return static::query()
            ->where('parent_comment_id', $commentId)
            ->orderBy('comment_date', 'ASC')
            ->get();
    }

    /** Remove all comments linked to a post (used before re-indexing). */
    public static function unlinkPost(int $postId): void
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM comments WHERE post_id = ?');
        $stmt->execute([$postId]);
    }
}

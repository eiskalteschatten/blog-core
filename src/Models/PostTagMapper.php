<?php

declare(strict_types=1);

namespace BlogCore\Models;

use BlogCore\Core\Database;

class PostTagMapper
{
    private static string $table = 'post_tag_mapper';

    public static function link(int $postId, int $tagId): void
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO " . self::$table . " (post_id, tag_id) VALUES (?, ?)"
        );
        $stmt->execute([$postId, $tagId]);
    }

    /** Remove all tag links for a post (used before re-indexing). */
    public static function unlinkPost(int $postId): void
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM " . self::$table . " WHERE post_id = ?");
        $stmt->execute([$postId]);
    }
}

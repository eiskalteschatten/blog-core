<?php

declare(strict_types=1);

namespace BlogCore\Models;

use BlogCore\Core\Database;

class PostCategoryMapper
{
    private static string $table = 'post_category_mapper';

    public static function link(int $postId, int $categoryId): void
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO " . self::$table . " (post_id, category_id) VALUES (?, ?)"
        );
        $stmt->execute([$postId, $categoryId]);
    }

    /** Remove all category links for a post (used before re-indexing). */
    public static function unlinkPost(int $postId): void
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM " . self::$table . " WHERE post_id = ?");
        $stmt->execute([$postId]);
    }
}

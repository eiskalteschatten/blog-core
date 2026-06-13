<?php

declare(strict_types=1);

namespace BlogCore\Models;

use BlogCore\Core\Model;
use BlogCore\Core\QueryBuilder;

class Post extends Model
{
    protected static string $table = 'posts';

    /**
     * Create or update a post by its slug. Returns the post id.
     */
    public static function upsertFromData(array $data): int
    {
        return static::upsert(
            ['slug' => $data['slug']],
            [
                'title'        => $data['title']        ?? '',
                'description'  => $data['description']  ?? null,
                'image'        => $data['image']         ?? null,
                'content_html' => $data['content_html']  ?? null,
                'is_draft'     => (int)($data['is_draft'] ?? false),
                'featured'     => (int)($data['featured'] ?? false),
                'published_at' => $data['published_at']  ?? null,
            ]
        );
    }

    public static function findBySlug(string $slug): ?array
    {
        return static::query()->where('slug', $slug)->first();
    }

    public static function updateContentHtml(int $id, string $html): void
    {
        $db = \BlogCore\Core\Database::getConnection();
        $stmt = $db->prepare('UPDATE posts SET content_html = :html WHERE id = :id');
        $stmt->execute([':html' => $html, ':id' => $id]);
    }

    /** QueryBuilder scoped to published (non-draft, non-scheduled) posts. */
    public static function published(): QueryBuilder
    {
        return static::query()
            ->where('is_draft', 0)
            ->whereRaw("(published_at IS NULL OR published_at <= datetime('now'))");
    }

    /** Return all categories linked to a post. */
    public static function categories(int $postId): array
    {
        return static::query()
            ->select('categories.*')
            ->join('post_category_mapper', 'post_category_mapper.category_id = categories.id')
            ->where('post_category_mapper.post_id', $postId)
            ->get();
    }

    /** Return all tags linked to a post. */
    public static function tags(int $postId): array
    {
        return static::query()
            ->select('tags.*')
            ->join('post_tag_mapper', 'post_tag_mapper.tag_id = tags.id')
            ->where('post_tag_mapper.post_id', $postId)
            ->get();
    }
}

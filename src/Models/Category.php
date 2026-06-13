<?php

declare(strict_types=1);

namespace BlogCore\Models;

use BlogCore\Core\Model;
use BlogCore\Core\QueryBuilder;

class Category extends Model
{
    protected static string $table = 'categories';

    /**
     * Create or update a category by its string_id. Returns the category id.
     */
    public static function upsertFromData(array $data): int
    {
        return static::upsert(
            ['string_id' => $data['id']],
            [
                'title'       => $data['title']       ?? '',
                'slug'        => $data['slug']         ?? '',
                'description' => $data['description']  ?? null,
                'image'       => $data['image']        ?? null,
                'featured'    => (int)($data['featured'] ?? false),
            ]
        );
    }

    public static function findBySlug(string $slug): ?array
    {
        return static::query()->where('slug', $slug)->first();
    }

    public static function findByStringId(string $stringId): ?array
    {
        return static::query()->where('string_id', $stringId)->first();
    }

    /** QueryBuilder for published posts belonging to this category. */
    public static function posts(int $categoryId, bool $includeDrafts = false): QueryBuilder
    {
        $base = $includeDrafts ? Post::query() : Post::published();

        return $base
            ->join('post_category_mapper', 'post_category_mapper.post_id = posts.id')
            ->where('post_category_mapper.category_id', $categoryId);
    }
}

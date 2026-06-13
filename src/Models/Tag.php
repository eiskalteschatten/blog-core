<?php

declare(strict_types=1);

namespace BlogCore\Models;

use BlogCore\Core\Model;
use BlogCore\Core\QueryBuilder;
use BlogCore\Helpers\SlugHelper;

class Tag extends Model
{
    protected static string $table = 'tags';

    /**
     * Find a tag by name, creating it if it does not exist.
     * Returns the tag id.
     */
    public static function findOrCreate(string $name): int
    {
        $slug     = SlugHelper::make($name);
        $existing = static::query()->where('slug', $slug)->first();

        if ($existing) {
            return (int)$existing['id'];
        }

        return static::upsert(['slug' => $slug], ['name' => $name]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return static::query()->where('slug', $slug)->first();
    }

    /** QueryBuilder for published posts belonging to this tag. */
    public static function posts(int $tagId, bool $includeDrafts = false): QueryBuilder
    {
        $base = $includeDrafts ? Post::query() : Post::published();

        return $base
            ->join('post_tag_mapper', 'post_tag_mapper.post_id = posts.id')
            ->where('post_tag_mapper.tag_id', $tagId);
    }
}

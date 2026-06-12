<?php

declare(strict_types=1);

namespace BlogCore\Core;

use PDO;

abstract class Model
{
    /**
     * Sub-classes must declare: protected static string $table = 'table_name';
     */
    protected static string $table;

    // -------------------------------------------------------------------------
    // Query entry-points
    // -------------------------------------------------------------------------

    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::db(), static::$table);
    }

    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find(int $id): ?array
    {
        return static::query()->where('id', $id)->first();
    }

    public static function where(string $column, mixed $value, string $op = '='): QueryBuilder
    {
        return static::query()->where($column, $value, $op);
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Insert a row and return its new id.
     */
    protected static function insert(array $data): int
    {
        $db      = static::db();
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql  = "INSERT INTO " . static::$table . " ({$columns}) VALUES ({$placeholders})";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$db->lastInsertId();
    }

    /**
     * INSERT OR REPLACE (SQLite) — replace full row if UNIQUE constraint fires.
     */
    protected static function insertOrReplace(array $data): int
    {
        $db      = static::db();
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql  = "INSERT OR REPLACE INTO " . static::$table . " ({$columns}) VALUES ({$placeholders})";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$db->lastInsertId();
    }

    /**
     * Find a row matching $where conditions; if found update it, if not insert it.
     * Returns the row id.
     */
    protected static function upsert(array $where, array $attributes): int
    {
        $db  = static::db();
        $qb  = static::query();
        foreach ($where as $col => $val) {
            $qb->where($col, $val);
        }
        $existing = $qb->first();

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            $attributes['updated_at'] = $now;
            $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($attributes)));
            $vals = array_values($attributes);

            // Append WHERE bindings
            $whereConditions = implode(' AND ', array_map(fn($k) => "{$k} = ?", array_keys($where)));
            $vals = array_merge($vals, array_values($where));

            $sql  = "UPDATE " . static::$table . " SET {$sets} WHERE {$whereConditions}";
            $stmt = $db->prepare($sql);
            $stmt->execute($vals);
            return (int)$existing['id'];
        }

        $data = array_merge($where, $attributes, [
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return static::insert($data);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public static function db(): PDO
    {
        return Database::getConnection();
    }
}

<?php

declare(strict_types=1);

namespace BlogCore\Core;

class SchemaManager
{
    public static function migrate(): void
    {
        $db = Database::getConnection();

        $db->exec("
            CREATE TABLE IF NOT EXISTS posts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        TEXT    NOT NULL,
                slug         TEXT    UNIQUE NOT NULL,
                description  TEXT,
                image        TEXT,
                content_html TEXT,
                is_draft     INTEGER NOT NULL DEFAULT 0,
                featured     INTEGER NOT NULL DEFAULT 0,
                published_at TEXT,
                created_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                string_id   TEXT    UNIQUE NOT NULL,
                title       TEXT    NOT NULL,
                slug        TEXT    UNIQUE NOT NULL,
                description TEXT,
                image       TEXT,
                featured    INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    UNIQUE NOT NULL,
                slug       TEXT    UNIQUE NOT NULL,
                created_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS post_category_mapper (
                post_id     INTEGER NOT NULL,
                category_id INTEGER NOT NULL,
                PRIMARY KEY (post_id, category_id),
                FOREIGN KEY (post_id)     REFERENCES posts(id)      ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
            );
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS post_tag_mapper (
                post_id INTEGER NOT NULL,
                tag_id  INTEGER NOT NULL,
                PRIMARY KEY (post_id, tag_id),
                FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE
            );
        ");
    }
}

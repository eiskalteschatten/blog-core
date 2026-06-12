<?php

declare(strict_types=1);

namespace BlogCore\Core;

use PDO;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Initialise the SQLite connection. Must be called before getConnection().
     */
    public static function init(string $path): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException("Could not create storage directory: {$dir}");
            }
        }

        self::$instance = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        self::$instance->exec('PRAGMA journal_mode=WAL;');
        self::$instance->exec('PRAGMA foreign_keys=ON;');
    }

    /**
     * Return the initialised PDO instance.
     *
     * @throws RuntimeException if init() has not been called yet.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            throw new RuntimeException(
                'Database not initialised. Call Database::init($path) before using models.'
            );
        }

        return self::$instance;
    }

    /** Reset for testing purposes. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}

<?php

declare(strict_types=1);

namespace BlogCore\Commands;

use BlogCore\Core\Config;
use RuntimeException;

class PublishAssetsCommand
{
    /**
     * CLI entry point.
     *
     * Usage in host bin/publish_assets.php:
     *   PublishAssetsCommand::run(new MyApp\Config\BlogConfig(), $argv);
     */
    public static function run(Config $config, array $argv = []): void
    {
        $verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
        static::execute($config, $verbose);
    }

    /**
     * Create a symlink from the host's public assets directory to the package's
     * assets/ directory. Called automatically by IndexBuilder during build.
     *
     * If the symlink already points to the correct target it is left untouched.
     * If it points somewhere else it is removed and recreated.
     *
     * Destination: Config::getPublicAssetsDir()
     *   Default:   {publicDir}/blog-core  →  {package}/assets
     */
    public static function execute(Config $config, bool $verbose = false): void
    {
        $srcDir  = self::packageAssetsDir();
        $link    = rtrim($config->getPublicAssetsDir(), '/');

        if (!is_dir($srcDir)) {
            // No assets bundled with this version of the package — nothing to do.
            return;
        }

        // Ensure the parent directory of the symlink exists
        $parentDir = dirname($link);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true)) {
            throw new RuntimeException("Could not create directory: {$parentDir}");
        }

        // Already a correct symlink — nothing to do
        if (is_link($link) && realpath(readlink($link)) === realpath($srcDir)) {
            if ($verbose) {
                echo "    skip (already linked): {$link}\n";
            }
            return;
        }

        // Remove a stale symlink or a leftover directory
        if (is_link($link)) {
            unlink($link);
        } elseif (is_dir($link)) {
            throw new RuntimeException(
                "Cannot create symlink: a real directory already exists at {$link}. Remove it manually."
            );
        }

        if (!symlink($srcDir, $link)) {
            throw new RuntimeException("Could not create symlink: {$srcDir} → {$link}");
        }

        if ($verbose) {
            echo "    → symlink: {$link} → {$srcDir}\n";
        }
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Absolute path to the assets/ directory bundled in the blog-core package.
     * Works whether using a path repository symlink or a normal Composer install.
     */
    private static function packageAssetsDir(): string
    {
        // src/Commands/ → src/ → package root → assets/
        return dirname(__DIR__, 2) . '/assets';
    }
}

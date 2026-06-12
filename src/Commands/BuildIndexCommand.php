<?php

declare(strict_types=1);

namespace BlogCore\Commands;

use BlogCore\Builders\IndexBuilder;
use BlogCore\Core\Config;

class BuildIndexCommand
{
    /**
     * Entry point for the CLI executable.
     *
     * Looks for a `blog-core.config.php` file in the current working directory.
     * That file must return an instance of BlogCore\Core\Config.
     *
     * Usage:
     *   blog-core-build
     *   blog-core-build -v | --verbose
     */
    public static function main(array $argv = []): void
    {
        $verbose    = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
        $configFile = getcwd() . '/blog-core.config.php';

        if (!file_exists($configFile)) {
            fwrite(STDERR, "Error: blog-core.config.php not found in " . getcwd() . "\n");
            fwrite(STDERR, "Create a blog-core.config.php at your project root that returns a BlogCore\\Core\\Config instance.\n");
            exit(1);
        }

        $config = require $configFile;

        if (!$config instanceof Config) {
            fwrite(STDERR, "Error: blog-core.config.php must return a BlogCore\\Core\\Config instance.\n");
            exit(1);
        }

        try {
            (new IndexBuilder($config))->build($verbose);
            echo "Done." . PHP_EOL;
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }
}

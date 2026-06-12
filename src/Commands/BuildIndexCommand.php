<?php

declare(strict_types=1);

namespace BlogCore\Commands;

use BlogCore\Builders\IndexBuilder;
use BlogCore\Core\Config;

class BuildIndexCommand
{
    /**
     * Run the index builder with a pre-built Config instance.
     * This is the primary entry point — mirrors how Application is used in public/index.php.
     *
     * Usage in host bin/build_index.php:
     *   BuildIndexCommand::run(new MyApp\Config\BlogConfig(), $argv);
     */
    public static function run(Config $config, array $argv = []): void
    {
        $verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);

        try {
            (new IndexBuilder($config))->build($verbose);
            echo "Done." . PHP_EOL;
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }

    /**
     * Standalone entry point for the `blog-core-build` vendor binary.
     * Discovers config via a `blog-core.config.php` file in CWD.
     */
    public static function main(array $argv = []): void
    {
        $configFile = getcwd() . '/blog-core.config.php';

        if (!file_exists($configFile)) {
            fwrite(STDERR, "Error: blog-core.config.php not found in " . getcwd() . "\n");
            fwrite(STDERR, "Alternatively, call BuildIndexCommand::run(\$config, \$argv) from your own bin script.\n");
            exit(1);
        }

        $config = require $configFile;

        if (!$config instanceof Config) {
            fwrite(STDERR, "Error: blog-core.config.php must return a BlogCore\\Core\\Config instance.\n");
            exit(1);
        }

        self::run($config, $argv);
    }
}

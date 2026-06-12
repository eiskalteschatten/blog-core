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


}

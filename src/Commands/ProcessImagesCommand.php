<?php

declare(strict_types=1);

namespace BlogCore\Commands;

use BlogCore\Core\Config;
use FilesystemIterator;
use Imagick;
use ImagickException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class ProcessImagesCommand
{
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'tiff', 'tif'];

    /**
     * CLI entry point. Parses -v / --verbose from $argv, then delegates to execute().
     *
     * Usage in host bin/process_images.php:
     *   ProcessImagesCommand::run(new MyApp\Config\BlogConfig(), $argv);
     */
    public static function run(Config $config, array $argv = []): void
    {
        $verbose = in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
        static::execute($config, $verbose);
    }

    /**
     * Process all post images using the Imagick PHP extension.
     *
    * For each post (discovered from post metadata), finds all supported image
    * files in Config::getOriginalPostImagesDir()/.../{slug}/ (mirroring
    * getPostsDir()), resizes them to
     * each width defined in Config::getImageSizes(), converts to WebP, and writes
     * the results to public/images/posts/{slug}/{filename}-{width}.webp.
     *
     * Images whose output files are already newer than the source are skipped.
     * Returns silently (with a warning) if ext-imagick is not loaded.
     */
    public static function execute(Config $config, bool $verbose = false): void
    {
        $sizes = $config->getImageSizes();

        if (empty($sizes)) {
            return;
        }

        if (!extension_loaded('imagick')) {
            fwrite(STDERR, "[images] The 'imagick' PHP extension is not installed — skipping image processing.\n");
            fwrite(STDERR, "[images] Install ext-imagick (https://pecl.php.net/package/imagick) to enable image resizing.\n");
            return;
        }

        $postsDir      = $config->getPostsDir();
        $sourceBaseDir = rtrim($config->getOriginalPostImagesDir(), '/');
        $outputBase    = rtrim($config->getPublicDir(), '/') . '/images/posts';

        if (!is_dir($sourceBaseDir)) {
            if ($verbose) {
                echo "  Images source directory not found: {$sourceBaseDir}\n";
            }
            return;
        }

        if (!is_dir($outputBase) && !mkdir($outputBase, 0755, true)) {
            throw new RuntimeException("Could not create output directory: {$outputBase}");
        }

        $totalProcessed = 0;
        $totalSkipped   = 0;

        foreach (static::findPostDirectories($postsDir) as $postDir) {
            $metaPath = $postDir . '/meta.json';

            try {
                $meta = json_decode(file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            $slug = $meta['slug'] ?? basename($postDir);

            if (!is_string($slug) || trim($slug) === '') {
                continue;
            }

            $imageSourceDir = static::resolveSourceImageDir($postsDir, $postDir, $sourceBaseDir);
            $images         = static::findImages($imageSourceDir);

            if (empty($images)) {
                continue;
            }

            $outputDir = $outputBase . '/' . $slug;

            if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
                throw new RuntimeException("Could not create output directory: {$outputDir}");
            }

            if ($verbose) {
                echo "  Post images [{$slug}]: " . count($images) . " source image(s)\n";
            }

            foreach ($images as $imagePath) {
                $baseName = pathinfo($imagePath, PATHINFO_FILENAME);

                foreach ($sizes as $width) {
                    $outputFile = $outputDir . '/' . $baseName . '-' . (int)$width . '.webp';

                    if (file_exists($outputFile) && filemtime($outputFile) >= filemtime($imagePath)) {
                        $totalSkipped++;
                        if ($verbose) {
                            echo "    skip (cached): " . basename($imagePath) . " @ {$width}px\n";
                        }
                        continue;
                    }

                    try {
                        $image = new Imagick($imagePath);

                        // Strip EXIF/ICC profiles to reduce file size
                        $image->stripImage();

                        // Auto-rotate based on EXIF orientation, then clear the flag
                        $image->autoOrient();

                        // Resize to width only if the image is wider than the target
                        // (never enlarge)
                        if ($image->getImageWidth() > (int)$width) {
                            $image->resizeImage((int)$width, 0, Imagick::FILTER_LANCZOS, 1);
                        }

                        $image->setImageFormat('webp');
                        $image->setImageCompressionQuality(85);
                        $image->writeImage($outputFile);
                        $image->clear();

                        $totalProcessed++;

                        if ($verbose) {
                            echo "    → " . basename($outputFile) . "\n";
                        }
                    } catch (ImagickException $e) {
                        fwrite(STDERR, "    [error] " . basename($imagePath) . " @ {$width}px: " . $e->getMessage() . "\n");
                    }
                }
            }
        }

        if ($verbose) {
            echo "  Images: {$totalProcessed} converted, {$totalSkipped} up to date.\n";
        }
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Find all post directories recursively. A post directory is any directory
     * containing a meta.json file.
     */
    private static function findPostDirectories(string $postsDir): array
    {
        $directories = [];

        if (!is_dir($postsDir)) {
            return $directories;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($postsDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }

            $postDir = $entry->getPathname();

            if (file_exists($postDir . '/meta.json')) {
                $directories[] = $postDir;
            }
        }

        sort($directories);

        return $directories;
    }

    /**
     * Find all supported image files directly inside $dir (non-recursive).
     */
    private static function findImages(string $dir): array
    {
        $images = [];

        if (!is_dir($dir)) {
            return $images;
        }

        foreach (new \DirectoryIterator($dir) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), self::SUPPORTED_EXTENSIONS, true)) {
                $images[] = $file->getPathname();
            }
        }

        sort($images);
        return $images;
    }

    /**
     * Resolve source image directory by mirroring the post directory structure.
     */
    private static function resolveSourceImageDir(string $postsDir, string $postDir, string $sourceBaseDir): string
    {
        $postsDir = rtrim($postsDir, '/');
        $postDir  = rtrim($postDir, '/');

        if (str_starts_with($postDir, $postsDir . '/')) {
            $relativePostDir = substr($postDir, strlen($postsDir) + 1);
            return $sourceBaseDir . '/' . $relativePostDir;
        }

        return $sourceBaseDir . '/' . basename($postDir);
    }
}

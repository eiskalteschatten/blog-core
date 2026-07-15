# Blog Core

A reusable, dependency-light PHP library for building file-system-based blogs. Posts are Markdown files, categories are JSON files, and everything is indexed into a SQLite database.

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Host project setup](#host-project-setup)
  - [1. Extend Config](#1-extend-config)
  - [2. Bootstrap the web entry point](#2-bootstrap-the-web-entry-point)
  - [3. Create a bin script](#3-create-a-bin-script)
  - [4. Add a Composer script (optional)](#4-add-a-composer-script-optional)
- [Content format](#content-format)
  - [Posts](#posts)
  - [Categories](#categories)
- [Commands](#commands)
  - [Build (or rebuild) the index](#build-or-rebuild-the-index)
  - [Process post images](#process-post-images-also-runs-automatically-as-part-of-build-index)
  - [Installing ext-imagick](#installing-ext-imagick)
  - [Import from WordPress (one-time migration)](#import-from-wordpress-one-time-migration)
  - [Publish core assets](#publish-core-assets-also-runs-automatically-as-part-of-build-index)
  - [Start the development server](#start-the-development-server)
- [Routing](#routing)
  - [Default routes](#default-routes)
  - [API](#api)
  - [Adding custom routes](#adding-custom-routes)
  - [Route prefix](#route-prefix)
- [Views](#views)
  - [Variable reference](#variable-reference)

---

## Requirements

- PHP ≥ 8.1
- SQLite (via the bundled `pdo_sqlite` extension)
- ImageMagick (ext-imagick)

---

## Installation

```bash
composer require alexseifert/blog-core
```

---

## Host project setup

### 1. Extend `Config`

Create a class that extends `BlogCore\Core\Config` and implements the required methods:

```php
<?php

namespace MyBlog\Config;

use BlogCore\Core\Config;

class BlogConfig extends Config
{
    private string $root;

    public function __construct()
    {
        $this->root = dirname(__DIR__);
    }

    // Required
    public function getSiteTitle(): string    { return 'My Blog'; }
    public function getSiteUrl(): string      { return 'https://example.com'; }
    public function getPostsDir(): string     { return $this->root . '/posts'; }
    public function getCategoriesDir(): string{ return $this->root . '/categories'; }
    public function getStoragePath(): string  { return $this->root . '/storage/blog.sqlite'; }
    public function getViewsDir(): string     { return $this->root . '/views'; }
    public function getPublicDir(): string    { return $this->root . '/public'; }

    // Optional overrides
    public function getPostsPerPage(): int    { return 12; }          // default: 12
    public function getRoutePrefix(): string  { return ''; }          // e.g. '/blog'
    public function getStaticPages(): array
    {
        return [
            ['loc' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => '/about', 'changefreq' => 'monthly', 'priority' => '0.5'],
        ];
    }

    // Image sizes to generate (widths in px). Override to customise.
    // Return [] to disable image processing.
    public function getImageSizes(): array { return [800, 1200]; }

    // Which of the above widths to use when rewriting <img> src paths in
    // post content HTML after image processing. Return null to disable.
    public function getContentImageWidth(): ?int { return 800; }
}
```

### 2. Bootstrap the web entry point

`public/index.php`:

```php
<?php
require_once '../vendor/autoload.php';

$app = new \BlogCore\Application(new \MyBlog\Config\BlogConfig());
$app->run();
```

`public/.htaccess`:

```apache
Options -Indexes
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

### 3. Create a bin script

`bin/build_index.php`:

```php
#!/usr/bin/env php
<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

use BlogCore\Commands\BuildIndexCommand;
use MyBlog\Config\BlogConfig;

BuildIndexCommand::run(new BlogConfig(), $argv);
```

### 4. Add a Composer script (optional)

`composer.json`:

```json
"scripts": {
    "build-index": "php bin/build_index.php"
}
```

---

## Content format

### Posts

Each post lives in its own subdirectory under `posts/`.
For large archives, the recommended layout is year/month buckets:

```
posts/
└── 2026/
    └── 06/
        └── my-first-post/
            ├── meta.json
            └── post.md
```

`blog-core` scans recursively, so legacy flat directories like `posts/my-first-post/` also continue to work.

**`meta.json`**

```json
{
    "title": "My First Post",
    "slug": "my-first-post",
    "description": "A short summary shown in listings.",
    "image": "/images/my-first-post.jpg",
    "tags": ["PHP", "Blog"],
    "categories": ["general"],
    "featured": false,
    "draft": false,
    "publishedAt": "2026-06-01T12:00:00"
}
```

| Field | Required | Notes |
|---|---|---|
| `title` | yes | |
| `slug` | yes | Must be unique |
| `description` | no | Used in listings and RSS |
| `image` | no | Absolute URL path |
| `tags` | no | Array of strings |
| `categories` | no | Array of category `id` strings |
| `featured` | no | Defaults to `false` |
| `draft` | no | Drafts are excluded from all public routes, feeds and sitemap |
| `publishedAt` | no | ISO 8601 datetime |

**`post.md`** — standard Markdown, rendered via [Parsedown](https://github.com/erusev/parsedown).

### Categories

Each category is a single JSON file in the `categories/` directory:

```
categories/
└── general.json
```

```json
{
    "id": "general",
    "title": "General",
    "slug": "general",
    "description": "General posts.",
    "image": "/images/categories/general.jpg",
    "featured": true
}
```

| Field | Required | Notes |
|---|---|---|
| `id` | yes | Referenced by `categories` in post `meta.json` |
| `title` | yes | |
| `slug` | yes | Used in URLs |
| `description` | no | |
| `image` | no | |
| `featured` | no | Featured categories appear on the home page |

---

## Commands

### Build (or rebuild) the index

Scans the `posts/` and `categories/` directories, converts Markdown to HTML, upserts everything into the SQLite database, writes `feed.xml` and `sitemap.xml` to `getPublicDir()`, processes post images, and rewrites `<img>` src paths in post content HTML to point to the generated WebP files (if `getContentImageWidth()` is set). Safe to run repeatedly.

```bash
# via Composer script
composer build-index

# directly
php bin/build_index.php

# with verbose output
php bin/build_index.php --verbose
php bin/build_index.php -v
```

### Process post images (also runs automatically as part of `build-index`)

Scans each post directory for images (jpg, jpeg, png, gif, webp, avif, tiff), resizes them to the widths defined in `Config::getImageSizes()`, converts to WebP, and writes them to `public/images/posts/{slug}/{filename}-{width}.webp`.

Already up-to-date outputs (output mtime ≥ source mtime) are skipped. Requires the [`imagick` PHP extension](https://pecl.php.net/package/imagick) (`ext-imagick`); if not loaded, a warning is printed and the step is skipped without failing the build.

```bash
# via Composer script (standalone)
composer process-images

# directly
php bin/process_images.php

# with verbose output
php bin/process_images.php --verbose
php bin/process_images.php -v
```

To reference a processed image in a post template, the path pattern is:

```
/images/posts/{slug}/{original-filename}-{width}.webp
```

For example, if `posts/hello-world/hero.jpg` is processed at widths `[800, 1200]`:

```
/images/posts/hello-world/hero-800.webp
/images/posts/hello-world/hero-1200.webp
```

Return an empty array from `getImageSizes()` to disable image processing entirely.

#### Rewriting content image paths

After images are processed, build-index can also rewrite the `<img src>` attributes in the stored post HTML to point to the generated WebP files. To enable this, override `getContentImageWidth()` in your `Config` and return one of the widths from `getImageSizes()`:

```php
public function getContentImageWidth(): ?int
{
    return 800; // must be one of the values in getImageSizes()
}
```

Returning `null` (the default) disables the rewrite step. Only `src` attributes that resolve to an existing file on disk are updated, so the step is safe to run when `ext-imagick` is missing.

### Installing ext-imagick

#### macOS (Homebrew)

```bash
brew install imagemagick pkg-config
pecl install imagick
```

If PECL fails to compile (e.g. on a very recent PHP version where a pre-built release is not yet available), build from source:

```bash
brew install imagemagick pkg-config autoconf

git clone https://github.com/Imagick/imagick.git
cd imagick
phpize
./configure
make
make install
```

Then add the following line to your `php.ini` (`php --ini` shows the path):

```ini
extension=imagick.so
```

#### Linux (Debian / Ubuntu)

```bash
apt-get install php-imagick
```

Or build via PECL if the distro package is outdated:

```bash
apt-get install libmagickwand-dev
pecl install imagick
```

Add to `php.ini`:

```ini
extension=imagick.so
```

#### Windows

1. Download the matching DLL from [PECL Windows downloads](https://pecl.php.net/package/imagick) (match your PHP version, architecture, and thread-safety setting).
2. Copy `php_imagick.dll` to your PHP `ext/` directory.
3. Download the ImageMagick Windows binaries from [imagemagick.org](https://imagemagick.org/script/download.php#windows) and add the install directory to your system `PATH`.
4. Add to `php.ini`:

```ini
extension=php_imagick.dll
```

Verify installation on any platform:

```bash
php -m | grep imagick
```

### Import from WordPress (one-time migration)

Connects to the WordPress REST API (v2) and imports all posts and categories into the blog-core file structure.

**What it does:**
- Fetches all categories → writes one `categories/{slug}.json` per category
- Batch-fetches all tags up-front (no per-post tag API calls)
- Fetches all published posts; also fetches draft posts when `--auth` is supplied
- Writes `posts/YYYY/MM/{slug}/meta.json` + `posts/YYYY/MM/{slug}/post.md` for each post
- Downloads all images (featured and inline content) to `posts/YYYY/MM/{slug}/` so `process-images` can generate WebP versions
- Inline image `src` attributes are rewritten to `/images/posts/{slug}/{basename}`
- Strips WordPress-specific HTML cruft (block classes, poll blocks, `srcset`/`sizes`, etc.)
- Skips already-imported posts and categories unless `--force` is passed

```bash
# via Composer script
composer import-wordpress -- --url https://example.com

# directly
php bin/import_wordpress.php --url https://example.com

# with verbose output
php bin/import_wordpress.php --url https://example.com --verbose

# import a single post by slug
php bin/import_wordpress.php --url https://example.com --post my-post-slug

# re-import (overwrite) already-imported content
php bin/import_wordpress.php --url https://example.com --force

# include draft posts (requires authentication)
php bin/import_wordpress.php --url https://example.com --auth username:app-password
```

**Importing drafts** requires a WordPress Application Password. Generate one under **Users → Profile → Application Passwords** in the WordPress admin. The `--auth` value is sent as HTTP Basic Auth and applies to all API requests including image downloads.

**After importing**, run `build-index` to populate the SQLite database and `process-images` to generate WebP versions of the featured images:

```bash
composer build-index
composer process-images
```

Requires `ext-curl` (available in most PHP installations).

### Publish core assets (also runs automatically as part of `build-index`)

Creates a symlink at `getPublicAssetsDir()` (default: `public/blog-core/`) pointing to the package's bundled `assets/` directory. If the symlink already exists and is correct it is left untouched.

```bash
# via Composer script (standalone)
composer publish-assets

# directly
php bin/publish_assets.php

# with verbose output
php bin/publish_assets.php --verbose
php bin/publish_assets.php -v
```

Override `getPublicAssetsDir()` in your `Config` to change the symlink location:

```php
public function getPublicAssetsDir(): string
{
    return $this->getPublicDir() . '/blog-core'; // default
}
```

Bundled assets are then accessible under `/blog-core/` in the browser, e.g. `/blog-core/js/blog.js`.

### Start the development server

```bash
php -S localhost:8000 -t public/
```

---

## Routing

### Default routes

blog-core registers these routes automatically. All routes are prefixed with the value returned by `Config::getRoutePrefix()` (empty string by default).

| Method | Pattern | View template | Description |
|---|---|---|---|
| GET | `/` | `pages/home` | Home page |
| GET | `/posts` | `pages/posts/index` | Paginated posts listing |
| GET | `/posts/:slug` | `pages/posts/single` | Single post |
| GET | `/categories` | `pages/categories/index` | Paginated categories listing |
| GET | `/categories/:slug` | `pages/categories/single` | Single category with its posts |
| GET | `/tags` | `pages/tags/index` | All tags |
| GET | `/tags/:slug` | `pages/tags/single` | Single tag with its posts |
| GET | `/feed.xml` | *(none — raw RSS 2.0 XML)* | RSS feed (dynamic fallback; prefer the static file written by `build-index`) |
| GET | `/sitemap.xml` | *(none — raw XML)* | XML sitemap (dynamic fallback; prefer the static file written by `build-index`) |
| GET | `/api/posts` | *(none — JSON)* | Published posts (see [API](#api)) |

View templates are resolved relative to `Config::getViewsDir()`. A `pages/404` template is used for not-found responses.

### API

#### `GET /api/posts`

Returns a JSON array of published posts ordered by `published_at` descending.

| Query parameter | Default | Description |
|---|---|---|
| `limit` | `getPostsPerPage()` | Maximum number of posts to return (minimum: 1) |
| `offset` | `0` | Number of posts to skip (minimum: 0) |

```
GET /api/posts
GET /api/posts?limit=5
GET /api/posts?limit=10&offset=20
```

Each item in the array is a post row from the database (same fields available in the view templates: `id`, `title`, `slug`, `description`, `image`, `content_html`, `published_at`, etc.).

### Adding custom routes

Call `$app->addRoute()` before `$app->run()`. Custom routes take precedence over default ones, so you can override any default route by registering the same pattern.

```php
$app = new \BlogCore\Application(new BlogConfig());

// Static page
$app->addRoute('GET', '/about', function (array $params) use ($app): void {
    // $renderer is not directly accessible, but you can render manually:
    include __DIR__ . '/../views/pages/about.php';
});

// Override the default home page
$app->addRoute('GET', '/', function (array $params): void {
    include __DIR__ . '/../views/pages/my-custom-home.php';
});

$app->run();
```

### Route prefix

To mount all blog routes under a sub-path (e.g. `/blog`), return it from `getRoutePrefix()`:

```php
public function getRoutePrefix(): string
{
    return '/blog';
}
```

All default routes become `/blog/`, `/blog/posts`, `/blog/posts/:slug`, etc.

---

## Views

Templates receive variables via `extract()` and always have `$config` (the `Config` instance) in scope. The layout template (`views/layouts/main.php`) receives the rendered page output as `$pageContent`.

### Variable reference

| Route | Variables passed to view |
|---|---|
| `/` | `$recentPosts`, `$featuredCategories` |
| `/posts` | `$pagination` |
| `/posts/:slug` | `$post`, `$categories`, `$tags` |
| `/categories` | `$pagination` |
| `/categories/:slug` | `$category`, `$pagination` |
| `/tags` | `$tags` |
| `/tags/:slug` | `$tag`, `$pagination` |

The `$pagination` array shape:

```php
[
    'items'       => array,   // rows for the current page
    'total'       => int,     // total row count
    'perPage'     => int,
    'currentPage' => int,
    'lastPage'    => int,
    'hasPrev'     => bool,
    'hasNext'     => bool,
    'prevPage'    => int,
    'nextPage'    => int,
]
```

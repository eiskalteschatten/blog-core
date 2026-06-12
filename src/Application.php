<?php

declare(strict_types=1);

namespace BlogCore;

use BlogCore\Core\Config;
use BlogCore\Core\Database;
use BlogCore\Core\Renderer;
use BlogCore\Core\Router;
use BlogCore\Core\SchemaManager;
use BlogCore\Helpers\FeedHelper;
use BlogCore\Helpers\PaginationHelper;
use BlogCore\Helpers\SitemapHelper;
use BlogCore\Models\Category;
use BlogCore\Models\Post;
use BlogCore\Models\Tag;

class Application
{
    private Config   $config;
    private Router   $router;
    private Renderer $renderer;

    public function __construct(Config $config)
    {
        $this->config   = $config;
        $this->renderer = new Renderer($config);
        $this->router   = new Router();

        Database::init($config->getStoragePath());
        SchemaManager::migrate();

        $this->registerDefaultRoutes();
    }

    /**
     * Add a custom route that will take precedence over the default routes.
     * Must be called before run().
     *
     * @param string   $method  HTTP method: GET, POST, …
     * @param string   $pattern URI pattern, e.g. "/about" or "/posts/:slug"
     * @param callable $handler function(array $params): void
     */
    public function addRoute(string $method, string $pattern, callable $handler): void
    {
        $this->router->add($method, $pattern, $handler);
    }

    /**
     * Dispatch the current HTTP request.
     */
    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';
        $this->router->dispatch($method, $uri);
    }

    // -------------------------------------------------------------------------
    // Default route handlers
    // -------------------------------------------------------------------------

    private function registerDefaultRoutes(): void
    {
        $prefix   = $this->config->getRoutePrefix();
        $router   = $this->router;
        $renderer = $this->renderer;
        $config   = $this->config;

        // Home
        $router->add('GET', $prefix . '/', function () use ($renderer, $config): void {
            $perPage     = $config->getPostsPerPage();
            $recentPosts = Post::published()
                ->orderBy('published_at', 'DESC')
                ->limit($perPage)
                ->get();

            $featuredCategories = Category::where('featured', 1)
                ->orderBy('title')
                ->limit(6)
                ->get();

            $renderer->render('pages/home', [
                'recentPosts'        => $recentPosts,
                'featuredCategories' => $featuredCategories,
            ]);
        });

        // Posts index
        $router->add('GET', $prefix . '/posts', function () use ($renderer, $config): void {
            $page       = PaginationHelper::currentPage();
            $pagination = PaginationHelper::paginate(
                Post::published()->orderBy('published_at', 'DESC'),
                $page,
                $config->getPostsPerPage()
            );

            $renderer->render('pages/posts/index', ['pagination' => $pagination]);
        });

        // Single post
        $router->add('GET', $prefix . '/posts/:slug', function (array $params) use ($renderer): void {
            $post = Post::findBySlug($params['slug']);

            if (!$post || $post['is_draft']) {
                http_response_code(404);
                $renderer->render('pages/404', []);
                return;
            }

            $categories = Post::categories((int)$post['id']);
            $tags       = Post::tags((int)$post['id']);

            $renderer->render('pages/posts/single', [
                'post'       => $post,
                'categories' => $categories,
                'tags'       => $tags,
            ]);
        });

        // Categories index
        $router->add('GET', $prefix . '/categories', function () use ($renderer, $config): void {
            $page       = PaginationHelper::currentPage();
            $pagination = PaginationHelper::paginate(
                Category::query()->orderBy('title'),
                $page,
                $config->getPostsPerPage()
            );

            $renderer->render('pages/categories/index', ['pagination' => $pagination]);
        });

        // Single category
        $router->add('GET', $prefix . '/categories/:slug', function (array $params) use ($renderer, $config): void {
            $category = Category::findBySlug($params['slug']);

            if (!$category) {
                http_response_code(404);
                $renderer->render('pages/404', []);
                return;
            }

            $page       = PaginationHelper::currentPage();
            $pagination = PaginationHelper::paginate(
                Category::posts((int)$category['id'])->orderBy('published_at', 'DESC'),
                $page,
                $config->getPostsPerPage()
            );

            $renderer->render('pages/categories/single', [
                'category'   => $category,
                'pagination' => $pagination,
            ]);
        });

        // Tags index
        $router->add('GET', $prefix . '/tags', function () use ($renderer): void {
            $tags = Tag::query()->orderBy('name')->get();
            $renderer->render('pages/tags/index', ['tags' => $tags]);
        });

        // Single tag
        $router->add('GET', $prefix . '/tags/:slug', function (array $params) use ($renderer, $config): void {
            $tag = Tag::findBySlug($params['slug']);

            if (!$tag) {
                http_response_code(404);
                $renderer->render('pages/404', []);
                return;
            }

            $page       = PaginationHelper::currentPage();
            $pagination = PaginationHelper::paginate(
                Tag::posts((int)$tag['id'])->orderBy('published_at', 'DESC'),
                $page,
                $config->getPostsPerPage()
            );

            $renderer->render('pages/tags/single', [
                'tag'        => $tag,
                'pagination' => $pagination,
            ]);
        });

        // RSS feed
        $router->add('GET', $prefix . '/feeds/posts.xml', function () use ($renderer, $config): void {
            $posts = Post::published()
                ->orderBy('published_at', 'DESC')
                ->limit(35)
                ->get();

            $renderer->xml(FeedHelper::generate($posts, $config));
        });

        // Sitemap
        $router->add('GET', $prefix . '/sitemap.xml', function () use ($renderer, $config): void {
            $renderer->xml(SitemapHelper::generate($config));
        });
    }
}

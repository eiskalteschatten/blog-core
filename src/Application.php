<?php

declare(strict_types=1);

namespace BlogCore;

use BlogCore\Core\Config;
use BlogCore\Core\Database;
use BlogCore\Core\Renderer;
use BlogCore\Core\Router;
use BlogCore\Core\SchemaManager;
use BlogCore\Helpers\FeedHelper;
use BlogCore\Helpers\CsrfHelper;
use BlogCore\Helpers\LocalCommentStore;
use BlogCore\Helpers\PaginationHelper;
use BlogCore\Helpers\SitemapHelper;
use BlogCore\Models\Category;
use BlogCore\Models\Comment;
use BlogCore\Models\Post;
use BlogCore\Models\Tag;

class Application
{
    private Config   $config;
    private Router   $router;
    private Renderer $renderer;

    private const COMMENTS_FORM_KEY = 'comments';

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
            $isDevServer = str_contains($_SERVER['SERVER_SOFTWARE'] ?? '', 'Development Server');
            $post = $isDevServer
                ? Post::findBySlug($params['slug'])
                : Post::published()->where('slug', $params['slug'])->first();

            if (!$post) {
                http_response_code(404);
                $renderer->render('pages/404', []);
                return;
            }

            $categories = Post::categories((int)$post['id']);
            $tags       = Post::tags((int)$post['id']);
            $comments   = Post::comments((int)$post['id']);
            $csrfToken  = CsrfHelper::token(self::COMMENTS_FORM_KEY);

            $renderer->render('pages/posts/single', [
                'post'       => $post,
                'categories' => $categories,
                'tags'       => $tags,
                'comments'   => $comments,
                'commentCsrfToken' => $csrfToken,
            ]);
        });

        // Create comment
        $router->add('POST', $prefix . '/posts/:slug/comments', function (array $params) use ($renderer, $config, $prefix): void {
            $isDevServer = str_contains($_SERVER['SERVER_SOFTWARE'] ?? '', 'Development Server');
            $post = $isDevServer
                ? Post::findBySlug($params['slug'])
                : Post::published()->where('slug', $params['slug'])->first();

            if (!$post) {
                http_response_code(404);
                $renderer->render('pages/404', []);
                return;
            }

            if (!CsrfHelper::validate(self::COMMENTS_FORM_KEY, (string)($_POST['_csrf'] ?? ''))) {
                http_response_code(422);

                $categories = Post::categories((int)$post['id']);
                $tags       = Post::tags((int)$post['id']);
                $comments   = Post::comments((int)$post['id']);

                $renderer->render('pages/posts/single', [
                    'post'              => $post,
                    'categories'        => $categories,
                    'tags'              => $tags,
                    'comments'          => $comments,
                    'commentCsrfToken'  => CsrfHelper::token(self::COMMENTS_FORM_KEY),
                    'commentFormErrors' => ['Your session expired. Please refresh the page and try again.'],
                    'commentFormOld'    => [
                        'author'     => trim((string)($_POST['author'] ?? '')),
                        'author_url' => trim((string)($_POST['author_url'] ?? '')),
                        'content'    => trim((string)($_POST['content'] ?? '')),
                    ],
                ]);

                return;
            }

            $author    = trim((string)($_POST['author'] ?? ''));
            $authorUrl = trim((string)($_POST['author_url'] ?? ''));
            $content   = trim((string)($_POST['content'] ?? ''));

            $errors = [];

            if ($author === '') {
                $author = 'Anonymous';
            }

            if (mb_strlen($author) > 120) {
                $errors[] = 'Author name must be 120 characters or fewer.';
            }

            if ($authorUrl !== '' && filter_var($authorUrl, FILTER_VALIDATE_URL) === false) {
                $errors[] = 'Author URL must be a valid URL.';
            }

            if (mb_strlen($content) < 2) {
                $errors[] = 'Comment content must be at least 2 characters.';
            }

            if (mb_strlen($content) > 10000) {
                $errors[] = 'Comment content must be 10000 characters or fewer.';
            }

            if (!empty($errors)) {
                http_response_code(422);

                $categories = Post::categories((int)$post['id']);
                $tags       = Post::tags((int)$post['id']);
                $comments   = Post::comments((int)$post['id']);

                $renderer->render('pages/posts/single', [
                    'post'             => $post,
                    'categories'       => $categories,
                    'tags'             => $tags,
                    'comments'         => $comments,
                    'commentCsrfToken' => CsrfHelper::token(self::COMMENTS_FORM_KEY),
                    'commentFormErrors'=> $errors,
                    'commentFormOld'   => [
                        'author'     => $author,
                        'author_url' => $authorUrl,
                        'content'    => $content,
                    ],
                ]);

                return;
            }

            try {
                $storedComment = LocalCommentStore::appendForPostSlug($config->getPostsDir(), (string)$params['slug'], [
                    'author'    => $author,
                    'authorUrl' => $authorUrl !== '' ? $authorUrl : null,
                    'content'   => $content,
                    'date'      => gmdate('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                http_response_code(500);

                $categories = Post::categories((int)$post['id']);
                $tags       = Post::tags((int)$post['id']);
                $comments   = Post::comments((int)$post['id']);

                $renderer->render('pages/posts/single', [
                    'post'             => $post,
                    'categories'       => $categories,
                    'tags'             => $tags,
                    'comments'         => $comments,
                    'commentCsrfToken' => CsrfHelper::token(self::COMMENTS_FORM_KEY),
                    'commentFormErrors'=> ['Could not save your comment right now. Please try again.'],
                    'commentFormOld'   => [
                        'author'     => $author,
                        'author_url' => $authorUrl,
                        'content'    => $content,
                    ],
                ]);
                return;
            }

            try {
                Comment::upsertFromData([
                    'post_id'      => (int)$post['id'],
                    'comment_id'   => (int)$storedComment['id'],
                    'parent_id'    => null,
                    'author'       => (string)$storedComment['author'],
                    'author_url'   => isset($storedComment['authorUrl']) ? (string)$storedComment['authorUrl'] : null,
                    'comment_date' => (string)$storedComment['date'],
                    'content'      => (string)$storedComment['content'],
                ]);
            } catch (\Throwable $e) {
                // SQLite is a cache; comment source-of-truth is comments.json.
            }

            $location = $prefix . '/posts/' . rawurlencode((string)$params['slug']) . '?comment=posted#comments';
            header('Location: ' . $location, true, 303);
            return;
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

        // API: published posts
        $router->add('GET', $prefix . '/api/posts', function () use ($renderer, $config): void {
            $defaultLimit = $config->getPostsPerPage();
            $limit        = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : $defaultLimit;
            $offset       = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

            $posts = Post::published()
                ->orderBy('published_at', 'DESC')
                ->limit($limit)
                ->offset($offset)
                ->get();

            $renderer->json($posts);
        });

        // Search
        $router->add('GET', $prefix . '/search', function () use ($renderer, $config): void {
            $query = trim($_GET['q'] ?? '');
            $page  = PaginationHelper::currentPage();

            $pagination = $query !== ''
                ? PaginationHelper::paginate(
                    Post::search($query)->orderBy('published_at', 'DESC'),
                    $page,
                    $config->getPostsPerPage()
                )
                : null;

            $renderer->render('pages/search', [
                'query'      => $query,
                'pagination' => $pagination,
            ]);
        });

        // RSS feed (dynamic fallback — static feed.xml written by build-index takes precedence)
        $router->add('GET', $prefix . '/feed.xml', function () use ($renderer, $config): void {
            $posts = Post::published()
                ->orderBy('published_at', 'DESC')
                ->limit(35)
                ->get();

            $renderer->xml(FeedHelper::generate($posts, $config));
        });

        // Sitemap (dynamic fallback — static sitemap.xml written by build-index takes precedence)
        $router->add('GET', $prefix . '/sitemap.xml', function () use ($renderer, $config): void {
            $renderer->xml(SitemapHelper::generate($config));
        });
    }

}

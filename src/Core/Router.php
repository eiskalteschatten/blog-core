<?php

declare(strict_types=1);

namespace BlogCore\Core;

class Router
{
    private array $routes = [];
    private \Closure $notFoundHandler;

    public function __construct()
    {
        $this->notFoundHandler = static function (): void {
            http_response_code(404);
            echo '<h1>404 Not Found</h1>';
        };
    }

    /**
     * Register a route.
     *
     * @param string   $method  HTTP method (GET, POST, …)
     * @param string   $pattern URI pattern, supports :param placeholders.
     *                          e.g. "/posts/:slug"
     * @param callable $handler Called with (array $params) on match.
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function setNotFoundHandler(callable $handler): void
    {
        $this->notFoundHandler = \Closure::fromCallable($handler);
    }

    /**
     * Match the current request and invoke the appropriate handler.
     */
    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);

        // Strip query string and normalise
        $path = strtok($uri, '?') ?: '/';
        $path = '/' . trim($path, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->match($route['pattern'], $path);

            if ($params !== null) {
                ($route['handler'])($params);
                return;
            }
        }

        ($this->notFoundHandler)();
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Try to match $path against $pattern.
     * Returns an associative array of captured params on success, or null.
     */
    private function match(string $pattern, string $path): ?array
    {
        // Collect param names in order
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $pattern, $paramNames);

        // Build regex: replace :param with a capture group
        $regex = preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#u';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($paramNames[1] as $i => $name) {
            $params[$name] = urldecode($matches[$i + 1]);
        }

        return $params;
    }
}

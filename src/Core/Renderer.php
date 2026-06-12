<?php

declare(strict_types=1);

namespace BlogCore\Core;

class Renderer
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Render a view wrapped in the default layout.
     *
     * @param string $view   View path relative to the views directory, without .php.
     *                       e.g. "pages/home"
     * @param array  $data   Variables to extract into the view scope.
     * @param string $layout Layout path relative to the views directory, without .php.
     *                       Pass an empty string to skip the layout.
     */
    public function render(
        string $view,
        array $data = [],
        string $layout = 'layouts/main'
    ): void {
        $viewsDir = rtrim(realpath($this->config->getViewsDir()) ?: $this->config->getViewsDir(), '/');
        $viewPath = $this->resolvePath($viewsDir, $view . '.php');

        if ($viewPath === null) {
            $this->notFound();
            return;
        }

        // Make config available inside every view
        $data['config'] = $this->config;

        if ($layout === '') {
            // No layout: just render the view directly
            (static function (string $_path, array $_data): void {
                extract($_data, EXTR_SKIP);
                include $_path;
            })($viewPath, $data);
            return;
        }

        $layoutPath = $this->resolvePath($viewsDir, $layout . '.php');

        if ($layoutPath === null) {
            // Layout missing – fall back to bare view
            (static function (string $_path, array $_data): void {
                extract($_data, EXTR_SKIP);
                include $_path;
            })($viewPath, $data);
            return;
        }

        // Capture page content; allow view to define $pageTitle by reference
        $pageContent = (static function (string $_path, array $_data): string {
            extract($_data, EXTR_SKIP);
            ob_start();
            include $_path;
            return ob_get_clean() ?: '';
        })($viewPath, $data);

        // Render layout with page content available
        (static function (
            string $_path,
            array  $_data,
            string $pageContent
        ): void {
            extract($_data, EXTR_SKIP);
            include $_path;
        })($layoutPath, $data, $pageContent);
    }

    /**
     * Send a JSON response.
     */
    public function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send a raw XML response.
     */
    public function xml(string $content, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/xml; charset=utf-8');
        echo $content;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Resolve a path beneath $root, returning the real path or null if it
     * would escape $root (path traversal protection).
     */
    private function resolvePath(string $root, string $relative): ?string
    {
        $full = $root . '/' . ltrim($relative, '/');
        $real = realpath($full);

        if ($real === false || !str_starts_with($real, $root . '/')) {
            return null;
        }

        return $real;
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo '<h1>404 Not Found</h1>';
    }
}

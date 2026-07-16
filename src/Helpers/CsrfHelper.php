<?php

declare(strict_types=1);

namespace BlogCore\Helpers;

class CsrfHelper
{
    private const SESSION_NAMESPACE = 'blogcore_csrf_tokens';
    private const SESSION_RENDERED_AT_NAMESPACE = 'blogcore_form_rendered_at';

    public static function token(string $formKey): string
    {
        self::ensureSessionStarted();

        $token = (string)($_SESSION[self::SESSION_NAMESPACE][$formKey] ?? '');

        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_NAMESPACE][$formKey] = $token;
        }

        return $token;
    }

    public static function validate(string $formKey, string $submittedToken): bool
    {
        self::ensureSessionStarted();

        $expectedToken = (string)($_SESSION[self::SESSION_NAMESPACE][$formKey] ?? '');

        return $submittedToken !== ''
            && $expectedToken !== ''
            && hash_equals($expectedToken, $submittedToken);
    }

    public static function markRendered(string $formKey): void
    {
        self::ensureSessionStarted();
        $_SESSION[self::SESSION_RENDERED_AT_NAMESPACE][$formKey] = time();
    }

    public static function meetsMinAge(string $formKey, int $minSeconds): bool
    {
        self::ensureSessionStarted();

        $renderedAt = (int)($_SESSION[self::SESSION_RENDERED_AT_NAMESPACE][$formKey] ?? 0);

        if ($renderedAt <= 0) {
            return false;
        }

        return (time() - $renderedAt) >= max(0, $minSeconds);
    }

    public static function rotate(string $formKey): string
    {
        self::ensureSessionStarted();

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_NAMESPACE][$formKey] = $token;

        return $token;
    }

    private static function ensureSessionStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

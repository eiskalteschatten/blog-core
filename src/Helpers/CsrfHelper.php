<?php

declare(strict_types=1);

namespace BlogCore\Helpers;

class CsrfHelper
{
    private const SESSION_NAMESPACE = 'blogcore_csrf_tokens';

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

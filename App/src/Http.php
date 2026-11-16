<?php
declare(strict_types=1);

namespace App;

/** Small helpers for the read-only JSON endpoints and pages. */
final class Http
{
    /** Allows GET/HEAD only; applies optional APP_BASIC_AUTH ("user:bcrypt-hash"). */
    public static function guard(): void
    {
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($m !== 'GET' && $m !== 'HEAD') {
            self::json(['error' => 'method not allowed'], 405);
        }
        $auth = env('APP_BASIC_AUTH');
        if ($auth !== '' && str_contains($auth, ':')) {
            [$u, $hash] = explode(':', $auth, 2);
            $ok = isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
                && hash_equals($u, (string) $_SERVER['PHP_AUTH_USER'])
                && password_verify((string) $_SERVER['PHP_AUTH_PW'], $hash);
            if (!$ok) {
                header('WWW-Authenticate: Basic realm="AdGuard Stats", charset="UTF-8"');
                self::json(['error' => 'authentication required'], 401);
            }
        }
    }

    public static function json(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** Parses filters or answers 400. */
    public static function filters(): Filters
    {
        try {
            return Filters::fromQuery($_GET);
        } catch (\InvalidArgumentException $e) {
            self::json(['error' => $e->getMessage()], 400);
        }
    }

    /** Opens the database, or answers 503 while it doesn't exist yet. */
    public static function db(): \PDO
    {
        if (!is_file(Db::path())) {
            self::json(['error' => 'database not ready'], 503);
        }
        return Db::open();
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

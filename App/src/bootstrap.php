<?php
// Shared start-up for web pages and jobs: strict errors, time zone, autoloader, env helper.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set(env('TZ', 'Africa/Johannesburg'));

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/** An environment variable, or $default when it is unset or empty. */
function env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/** Folder for runtime data (database, lock files, downloaded lists). */
function data_dir(): string
{
    return rtrim(env('DATA_DIR', '/data'), '/');
}

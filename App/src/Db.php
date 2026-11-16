<?php
declare(strict_types=1);

namespace App;

use PDO;

/** SQLite connection (WAL), migration runner and the small `meta` key/value store. */
final class Db
{
    public static function path(): string
    {
        return env('DB_PATH', data_dir() . '/stats.sqlite');
    }

    public static function open(?string $path = null): PDO
    {
        $pdo = new PDO('sqlite:' . ($path ?? self::path()), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 15000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        return $pdo;
    }

    /** Applies every migrations/NNN_*.sql newer than meta.schema_version. Returns the new version. */
    public static function migrate(PDO $pdo, ?string $dir = null): int
    {
        $dir ??= APP_ROOT . '/migrations';
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
        $current = (int) (self::meta($pdo, 'schema_version') ?? 0);

        $files = glob($dir . '/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $version = (int) substr(basename($file), 0, 3);
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec((string) file_get_contents($file));
                self::setMeta($pdo, 'schema_version', (string) $version);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new \RuntimeException('migration ' . basename($file) . ' failed: ' . $e->getMessage(), 0, $e);
            }
            $current = $version;
        }
        self::seedCategories($pdo);
        Categoriser::seedOverrides($pdo);
        return $current;
    }

    /** Inserts or updates the app taxonomy from config/categories.php (ids are fixed there). */
    public static function seedCategories(PDO $pdo): void
    {
        $st = $pdo->prepare('INSERT INTO categories (id, slug, name, colour) VALUES (?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET slug = excluded.slug, name = excluded.name, colour = excluded.colour');
        foreach (Categories::all() as $c) {
            $st->execute([$c['id'], $c['slug'], $c['name'], $c['colour']]);
        }
    }

    public static function meta(PDO $pdo, string $key): ?string
    {
        $st = $pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public static function setMeta(PDO $pdo, string $key, ?string $value): void
    {
        if ($value === null) {
            $pdo->prepare('DELETE FROM meta WHERE key = ?')->execute([$key]);
            return;
        }
        $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }

    /** All meta rows whose key starts with $prefix, as key => value. */
    public static function metaLike(PDO $pdo, string $prefix): array
    {
        $st = $pdo->prepare("SELECT key, value FROM meta WHERE key LIKE ? ESCAPE '\\'");
        $st->execute([addcslashes($prefix, '%_\\') . '%']);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

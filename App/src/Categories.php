<?php
declare(strict_types=1);

namespace App;

/** Read-only view of config/categories.php. */
final class Categories
{
    public const UNCATEGORISED = 18;

    private static ?array $config = null;

    public static function config(): array
    {
        return self::$config ??= require APP_ROOT . '/config/categories.php';
    }

    /** @return list<array{id:int,slug:string,name:string,colour:string}> */
    public static function all(): array
    {
        return self::config()['categories'];
    }

    public static function idForSlug(string $slug): ?int
    {
        foreach (self::all() as $c) {
            if ($c['slug'] === $slug) {
                return $c['id'];
            }
        }
        return null;
    }

    /** Source category => app category id, for one source (hagezi|ut1|cloudflare), in priority order. */
    public static function mapping(string $source): array
    {
        $out = [];
        foreach (self::config()[$source] ?? [] as $srcCat => $slug) {
            $id = self::idForSlug($slug);
            if ($id === null) {
                throw new \LogicException("config/categories.php: $source:$srcCat maps to unknown slug $slug");
            }
            $out[$srcCat] = $id;
        }
        return $out;
    }
}

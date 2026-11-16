<?php
declare(strict_types=1);

namespace App;

/**
 * Public Suffix List matcher: base() returns the registrable domain (eTLD+1).
 * Handles normal, wildcard (*.ck) and exception (!www.ck) rules, plus the special cases this app
 * meets in a DNS log: reverse lookups, IP literals and single-label names.
 */
final class Psl
{
    /** @var array<string,true> */ private array $rules = [];
    /** @var array<string,true> parent of each `*.parent` rule */ private array $wild = [];
    /** @var array<string,true> */ private array $exceptions = [];

    public static function bundledPath(): string
    {
        return APP_ROOT . '/config/public_suffix_list.dat';
    }

    public static function refreshedPath(): string
    {
        return data_dir() . '/lists/public_suffix_list.dat';
    }

    /** The weekly-refreshed copy if there is one, otherwise the copy bundled in the image. */
    public static function load(?string $file = null): self
    {
        if ($file === null) {
            $file = is_file(self::refreshedPath()) ? self::refreshedPath() : self::bundledPath();
        }
        $psl = new self();
        $psl->parse((string) file_get_contents($file));
        if (count($psl->rules) < 1000 && $file !== self::bundledPath() && is_file(self::bundledPath())) {
            return self::load(self::bundledPath());   // a truncated download must not break base domains
        }
        return $psl;
    }

    public static function fromString(string $text): self
    {
        $psl = new self();
        $psl->parse($text);
        return $psl;
    }

    public function ruleCount(): int
    {
        return count($this->rules) + count($this->wild) + count($this->exceptions);
    }

    private function parse(string $text): void
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim(explode(' ', trim($line), 2)[0]);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            $line = strtolower($line);
            if ($line[0] === '!') {
                $this->exceptions[substr($line, 1)] = true;
            } elseif (str_starts_with($line, '*.')) {
                $this->wild[substr($line, 2)] = true;
            } else {
                $this->rules[$line] = true;
            }
        }
    }

    public static function normalise(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    public function base(string $host): string
    {
        $host = self::normalise($host);
        if ($host === '' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        foreach (['in-addr.arpa', 'ip6.arpa'] as $rev) {
            if ($host === $rev || str_ends_with($host, '.' . $rev)) {
                return $rev;
            }
        }

        $labels = explode('.', $host);
        $n = count($labels);
        $suffixLen = 1;                              // the implicit "*" rule
        for ($i = 0; $i < $n; $i++) {
            $s = implode('.', array_slice($labels, $i));
            if (isset($this->exceptions[$s])) {
                $suffixLen = $n - $i - 1;
                break;
            }
            if (isset($this->rules[$s])) {
                $suffixLen = $n - $i;
                break;
            }
            if ($i + 1 < $n && isset($this->wild[implode('.', array_slice($labels, $i + 1))])) {
                $suffixLen = $n - $i;
                break;
            }
        }
        if ($suffixLen >= $n) {
            return $host;                            // the name is itself a public suffix
        }
        return implode('.', array_slice($labels, $n - $suffixLen - 1));
    }
}

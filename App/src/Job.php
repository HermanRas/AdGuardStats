<?php
declare(strict_types=1);

namespace App;

/** Shared plumbing for bin/ jobs: one-line logging and flock-based locks. */
final class Job
{
    /** @var array<string,resource> */
    private static array $locks = [];

    public static function log(string $job, string $msg): void
    {
        fwrite(STDOUT, date('Y-m-d H:i:s') . " [$job] $msg\n");
    }

    /** Non-blocking per-job lock: false if another run of this job is still going. */
    public static function lockOrSkip(string $job): bool
    {
        return self::lock($job, false);
    }

    /** Blocking lock shared by every job that writes to the database. */
    public static function lockDb(): void
    {
        self::lock('db', true);
    }

    private static function lock(string $name, bool $wait): bool
    {
        $dir = data_dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fh = fopen("$dir/.lock-$name", 'c');
        if ($fh === false || !flock($fh, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            return false;
        }
        self::$locks[$name] = $fh;   // held until the process exits
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Admin\Middlewares\RateLimitStores;

/**
 * PHP port: the hits in one file per key, for runtimes that start afresh for each request
 * (PHP-FPM, CGI, mod_php, the built-in server), where {@see MemoryStore} would forget every hit
 * and never limit. Each hit is a read-modify-write under an exclusive `flock`, so concurrent
 * requests count correctly. Needs no extension, which suits shared hosting; one server only, like
 * the memory store (a load-balanced setup shares no `.tmp`).
 *
 * The files are spread over 256 subdirectories (the first two hex digits of the key's hash), and
 * an occasional request sweeps the ended windows of one of them, so neither a directory nor a
 * sweep grows with every key ever limited. A store that cannot write counts nothing (it fails
 * open): a broken `.tmp` must not stop the admin from logging in.
 */
final class FileStore implements Store
{
    /** One request in this many sweeps one subdirectory. */
    private const int SWEEP_ONE_IN = 100;

    public function __construct(private readonly string $dir)
    {
    }

    public function incr(string $key, int $intervalMs, int $nowMs): array
    {
        if (random_int(1, self::SWEEP_ONE_IN) === 1) {
            $this->removeExpired($nowMs, sprintf('%02x', random_int(0, 255)));
        }

        $hash = hash('sha256', $key);
        $file = $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.json';

        // a sweep may unlink the file between our open and our lock: then count in a new one
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $entry = $this->incrFile($file, $intervalMs, $nowMs);
            if ($entry !== false) {
                return $entry;
            }
        }

        return self::uncounted($intervalMs, $nowMs);
    }

    /**
     * @return array{counter: int, dateEnd: int}|false false: a sweep unlinked the file while we
     *                                              waited for its lock
     */
    private function incrFile(string $file, int $intervalMs, int $nowMs): array|false
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return self::uncounted($intervalMs, $nowMs);
        }
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return self::uncounted($intervalMs, $nowMs);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return self::uncounted($intervalMs, $nowMs);
            }
            clearstatcache(true, $file);
            $opened = fstat($handle);
            $current = @stat($file);
            if ($opened === false || $current === false || $opened['ino'] !== $current['ino']) {
                return false;
            }

            $stored = json_decode((string) stream_get_contents($handle), true);
            $entry = is_array($stored) && is_int($stored['counter'] ?? null) && is_int($stored['dateEnd'] ?? null) && $stored['dateEnd'] > $nowMs
                ? ['counter' => $stored['counter'], 'dateEnd' => $stored['dateEnd']]
                : ['counter' => 0, 'dateEnd' => $nowMs + $intervalMs];
            $entry['counter']++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($entry));
            fflush($handle);
            // the window's end as mtime, what removeExpired() reads first; set under the lock, which
            // removeExpired() takes before it decides
            @touch($file, (int) ceil($entry['dateEnd'] / 1000));

            return $entry;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * A hit that could not be recorded: counted as the first of its window, so never limited.
     *
     * @return array{counter: int, dateEnd: int}
     */
    private static function uncounted(int $intervalMs, int $nowMs): array
    {
        return ['counter' => 1, 'dateEnd' => $nowMs + $intervalMs];
    }

    /**
     * Deletes the files of windows that have ended, in one subdirectory (`00` to `ff`) or in all of
     * them. A file is deleted under its lock, once its window is seen ended there: a request that
     * has just counted in it set a later end first. Skipped while another sweep runs.
     */
    public function removeExpired(int $nowMs, ?string $shard = null): void
    {
        $sweepLock = @fopen($this->dir . '/.sweep.lock', 'c');
        if ($sweepLock === false) {
            return;
        }

        try {
            if (!flock($sweepLock, LOCK_EX | LOCK_NB)) {
                return;
            }
            foreach (glob($this->dir . '/' . ($shard ?? '*') . '/*.json') ?: [] as $file) {
                $mtime = @filemtime($file);
                if ($mtime === false || $mtime * 1000 > $nowMs) {
                    continue;
                }
                $handle = @fopen($file, 'r');
                if ($handle === false) {
                    continue;
                }
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    clearstatcache(true, $file);
                    $mtime = @filemtime($file);
                    if ($mtime !== false && $mtime * 1000 <= $nowMs) {
                        @unlink($file);
                    }
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            }
        } finally {
            flock($sweepLock, LOCK_UN);
            fclose($sweepLock);
        }
    }
}

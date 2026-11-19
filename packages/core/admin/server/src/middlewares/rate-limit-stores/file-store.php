<?php

declare(strict_types=1);

namespace Strapi\Admin\Middlewares\RateLimitStores;

/**
 * PHP port: the hits in one file per key, for runtimes that start afresh for each request
 * (PHP-FPM, CGI, mod_php, the built-in server), where {@see MemoryStore} would forget every hit
 * and never limit. Each hit is a read-modify-write under an exclusive `flock`, so concurrent
 * requests count correctly. Needs no extension, which suits shared hosting; one server only, like
 * the memory store (a load-balanced setup shares no `.tmp`).
 */
final class FileStore implements Store
{
    public function __construct(private readonly string $dir)
    {
    }

    public function incr(string $key, int $intervalMs, int $nowMs): array
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o777, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create the rate limit directory {$this->dir}");
        }

        if (random_int(1, 100) === 1) {
            $this->removeExpired($nowMs);
        }

        $file = $this->dir . '/' . hash('sha256', $key) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open the rate limit file {$file}");
        }

        try {
            flock($handle, LOCK_EX);
            $stored = json_decode((string) stream_get_contents($handle), true);
            $entry = is_array($stored) && is_int($stored['counter'] ?? null) && is_int($stored['dateEnd'] ?? null) && $stored['dateEnd'] > $nowMs
                ? ['counter' => $stored['counter'], 'dateEnd' => $stored['dateEnd']]
                : ['counter' => 0, 'dateEnd' => $nowMs + $intervalMs];
            $entry['counter']++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($entry));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        // the window's end as mtime: what removeExpired() reads without opening the file
        @touch($file, (int) ceil($entry['dateEnd'] / 1000));

        return $entry;
    }

    /** Deletes the files of windows that have ended. */
    public function removeExpired(int $nowMs): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime * 1000 <= $nowMs) {
                @unlink($file);
            }
        }
    }
}

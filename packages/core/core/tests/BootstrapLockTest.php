<?php

declare(strict_types=1);

namespace Strapi\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * PHP port: boots that wait on `.tmp/bootstrap.lock` while another process bootstraps. Once that
 * bootstrap has settled the same inputs, a waiting boot is settled too: it must not run a whole
 * bootstrap of its own (under PHP-FPM, every request arriving during a bootstrap would, in turn).
 * Each boot here is a process of its own, as each PHP-FPM request is.
 */
final class BootstrapLockTest extends TestCase
{
    private string $dir = '';

    private static function appDir(): string
    {
        return dirname(__DIR__, 4) . '/examples/getstarted';
    }

    protected function setUp(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('needs /proc to see a boot waiting on the lock');
        }
        $this->dir = sys_get_temp_dir() . '/strapi-bootstrap-lock-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        @unlink(self::appDir() . '/.tmp/bootstrap.settled');
    }

    /**
     * A production boot of the example app on this test's database, printing whether it was settled.
     *
     * @param array<int, resource>|null $pipes set to the process's pipes
     * @return resource
     */
    private function startBoot(?array &$pipes)
    {
        $script = <<<'PHP'
            require $argv[1];
            $strapi = Strapi\Core\Core::createStrapi(['appDir' => $argv[2]])->load();
            echo $strapi->bootstrapSettled() ? 'settled' : 'unsettled';
            $strapi->destroy();
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=disable', '-r', $script, dirname(__DIR__, 4) . '/vendor/autoload.php', self::appDir()],
            [1 => ['pipe', 'w'], 2 => ['file', "{$this->dir}/stderr", 'a']],
            $pipes,
            self::appDir(),
            [
                'PATH' => (string) getenv('PATH'),
                'NODE_ENV' => 'production',
                'DATABASE_CLIENT' => 'sqlite',
                'DATABASE_FILENAME' => "{$this->dir}/data.db",
                'LOG_LEVEL' => 'error',
                'STRAPI_NO_EXIT' => '1',
                'APP_KEYS' => 'lock-key-1,lock-key-2',
                'API_TOKEN_SALT' => 'lock-api-token-salt',
                'ADMIN_JWT_SECRET' => 'lock-admin-jwt-secret',
                'TRANSFER_TOKEN_SALT' => 'lock-transfer-token-salt',
                'JWT_SECRET' => 'lock-jwt-secret',
                'ENCRYPTION_KEY' => 'lock-encryption-key',
            ],
        );
        self::assertIsResource($process);

        return $process;
    }

    /**
     * @param resource $process
     * @param array<int, resource>|null $pipes
     */
    private function outcome($process, ?array $pipes): string
    {
        $output = (string) stream_get_contents($pipes[1] ?? throw new \LogicException('no stdout'));
        proc_close($process);

        return trim($output) . (trim($output) === '' ? ' (stderr: ' . @file_get_contents("{$this->dir}/stderr") . ')' : '');
    }

    /** Whether the process has the bootstrap lock open: it is waiting for it, past its record check. */
    private static function waitsOnTheLock(int $pid): bool
    {
        foreach (glob("/proc/{$pid}/fd/*") ?: [] as $fd) {
            if (str_ends_with((string) @readlink($fd), '/.tmp/bootstrap.lock')) {
                return true;
            }
        }

        return false;
    }

    public function testABootThatWaitedForASettlingBootstrapIsSettled(): void
    {
        $record = self::appDir() . '/.tmp/bootstrap.settled';

        // a first boot bootstraps and records what it settled
        $process = $this->startBoot($pipes);
        self::assertSame('unsettled', $this->outcome($process, $pipes));
        $settled = (string) file_get_contents($record);

        // another process is bootstrapping (it holds the lock; the record is gone until it ends).
        // A process of its own: a lock this test held would be inherited by the boot it starts
        $holder = proc_open(
            [PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "locked\n"; fgets(STDIN);', self::appDir() . '/.tmp/bootstrap.lock'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $holderPipes,
        );
        self::assertIsResource($holder);
        self::assertSame("locked\n", fgets($holderPipes[1]));
        unlink($record);

        try {
            $process = $this->startBoot($pipes);
            $pid = (int) proc_get_status($process)['pid'];
            $deadline = microtime(true) + 30;
            while (!self::waitsOnTheLock($pid) && proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(20_000);
            }
            self::assertTrue(self::waitsOnTheLock($pid), 'the boot waits on the lock');

            // that bootstrap completes for the same inputs
            file_put_contents($record, $settled);
        } finally {
            fwrite($holderPipes[0], "done\n");
            proc_close($holder);
        }

        self::assertSame('settled', $this->outcome($process, $pipes));
        self::assertSame($settled, file_get_contents($record), 'the record is kept');
    }
}

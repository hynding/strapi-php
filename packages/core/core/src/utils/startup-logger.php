<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/utils/startup-logger.ts (plain text, no chalk/cli-table). */
final class StartupLogger
{
    /** @var resource|null */
    private $stream;

    /** @param resource|null $stream defaults to STDOUT */
    public function __construct(private readonly Strapi $app, $stream = null)
    {
        $this->stream = $stream;
    }

    public static function createStartupLogger(Strapi $app): self
    {
        return new self($app);
    }

    private function line(string $text = ''): void
    {
        $stream = $this->stream ?? (defined('STDOUT') ? STDOUT : null);
        if ($stream === null) {
            return;
        }
        fwrite($stream, $text . PHP_EOL);
    }

    /** @param list<array{0: string, 1: mixed}> $rows */
    private function table(array $rows): string
    {
        $width = 0;
        foreach ($rows as [$label]) {
            $width = max($width, mb_strlen($label));
        }
        $valueWidth = 0;
        $lines = [];
        foreach ($rows as [$label, $value]) {
            $value = (string) (is_scalar($value) || $value === null ? $value : json_encode($value));
            $valueWidth = max($valueWidth, mb_strlen($value));
            $lines[] = [$label, $value];
        }
        $out = ['╭' . str_repeat('─', $width + 2) . '┬' . str_repeat('─', $valueWidth + 2) . '╮'];
        foreach ($lines as [$label, $value]) {
            $out[] = '│ ' . str_pad($label, $width) . ' │ ' . str_pad($value, $valueWidth) . ' │';
        }
        $out[] = '╰' . str_repeat('─', $width + 2) . '┴' . str_repeat('─', $valueWidth + 2) . '╯';

        return implode(PHP_EOL, $out);
    }

    public function logStats(): void
    {
        $this->line();
        $this->line(' Project information');
        $this->line();

        $dbInfo = $this->app->has('db') ? $this->app->db()->getInfo() : null;
        $config = $this->app->config();
        $launchedAt = (int) $config->get('launchedAt', 0);

        $rows = [
            ['Time', date(DATE_RFC2822)],
            ['Launched in', ((int) floor(microtime(true) * 1000) - $launchedAt) . ' ms'],
            ['Environment', $config->get('environment')],
            ['Process PID', getmypid()],
            ['Version', $config->get('info.strapiPhp', $config->get('info.strapi')) . ' (php ' . PHP_VERSION . ')'],
            ['Plan', 'Community'],
            ['Database', $dbInfo['client'] ?? null],
            ['Database name', $dbInfo['displayName'] ?? null],
        ];
        if (!empty($dbInfo['schema'])) {
            $rows[] = ['Database schema', $dbInfo['schema']];
        }

        $this->line($this->table($rows));
        $this->line();
        $this->line(' Actions available');
        $this->line();
    }

    public function logFirstStartupMessage(): void
    {
        if (!$this->app->config()->get('server.logger.startup.enabled')) {
            return;
        }

        $this->logStats();

        $this->line('One more thing...');
        $this->line('Create your first administrator 💻 by going to the administration panel at:');
        $this->line();
        $this->line((string) $this->app->config()->get('admin.absoluteUrl'));
        $this->line();
    }

    public function logDefaultStartupMessage(): void
    {
        if (!$this->app->config()->get('server.logger.startup.enabled')) {
            return;
        }
        $this->logStats();

        $this->line('Welcome back!');

        if ($this->app->config()->get('admin.serveAdminPanel') === true) {
            $this->line('To manage your project 🚀, go to the administration panel at:');
            $this->line((string) $this->app->config()->get('admin.absoluteUrl'));
            $this->line();
        }

        $this->line('To access the server ⚡️, go to:');
        $this->line((string) $this->app->config()->get('server.absoluteUrl'));
        $this->line();
    }

    /** @param array{isInitialized: bool} $options */
    public function logStartupMessage(array $options): void
    {
        if (!$this->app->config()->get('server.logger.startup.enabled')) {
            return;
        }
        if (!$options['isInitialized']) {
            $this->logFirstStartupMessage();
        } else {
            $this->logDefaultStartupMessage();
        }
    }
}

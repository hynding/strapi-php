<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Strapi\Cli\Cli\Cli;
use Strapi\Cli\Strapi;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Port of packages/core/strapi/src/cli/__tests__/index.test.ts (command registration) plus the project commands. */
final class CliTest extends TestCase
{
    private static string $appDir;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = dirname(__DIR__, 5) . '/examples/getstarted';
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('LOG_LEVEL=error');
        putenv('STRAPI_NO_EXIT=1');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';
    }

    public function testRegistersTheCommands(): void
    {
        $application = Cli::createCLI([], self::$appDir);

        foreach (['start', 'develop', 'build', 'console', 'version', 'routes:list', 'content-types:list', 'cron:run', 'migrations:run', 'configuration:dump', 'configuration:restore', 'admin:create-user', 'admin:reset-user-password', 'telemetry:enable', 'telemetry:disable'] as $name) {
            self::assertTrue($application->has($name), "command {$name} is registered");
        }

        self::assertSame('develop', $application->find('dev')->getName());
        self::assertSame('configuration:dump', $application->find('config:dump')->getName());
        self::assertSame(Strapi::version(), $application->getVersion());
    }

    public function testDeprecatedPluginCommandsWarn(): void
    {
        $application = Cli::createCLI([], self::$appDir);
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'plugin:init']), $output);

        self::assertSame(0, $code);
        self::assertStringContainsString('The command plugin:init has been deprecated', $output->fetch());
    }

    public function testVersionCommand(): void
    {
        $application = Cli::createCLI([], self::$appDir);
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'version']), $output);

        self::assertSame(0, $code);
        self::assertSame(Strapi::version() . "\n", $output->fetch());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Strapi::version());
    }

    public function testCommandsRefuseToRunOutsideAStrapiProject(): void
    {
        $application = Cli::createCLI([], sys_get_temp_dir());
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'routes:list']), $output);

        self::assertSame(1, $code);
        self::assertStringContainsString('in a Strapi project', $output->fetch());
    }

    public function testRoutesListPrintsTheTable(): void
    {
        $application = Cli::createCLI([], self::$appDir);
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'routes:list']), $output);
        $text = $output->fetch();

        self::assertSame(0, $code, $text);
        self::assertStringContainsString('| Method | Path', $text);
        self::assertStringContainsString('| GET    | /api/articles ', $text);
        self::assertStringContainsString('api::article.article.find', $text);
        self::assertStringContainsString('global::deny', $text);
        self::assertStringContainsString('| /api/temps/ping ', $text);
        self::assertStringContainsString('public', $text);
    }

    public function testContentTypesList(): void
    {
        $application = Cli::createCLI([], self::$appDir);
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'content-types:list']), $output);
        $text = $output->fetch();

        self::assertSame(0, $code, $text);
        self::assertStringContainsString('api::article.article', $text);
        self::assertStringContainsString('api::homepage.homepage', $text);
    }

    public function testCronRunAndMigrationsRun(): void
    {
        $application = Cli::createCLI([], self::$appDir);

        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'cron:run']), $output));
        self::assertStringContainsString('Ran 0 cron job(s)', $output->fetch());

        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'migrations:run']), $output));
        self::assertStringContainsString('Migrations are up to date', $output->fetch());
    }

    public function testConfigurationDumpAndRestore(): void
    {
        $application = Cli::createCLI([], self::$appDir);

        $file = tempnam(sys_get_temp_dir(), 'strapi-config-');
        file_put_contents($file, json_encode([
            ['key' => 'plugin_test_setting', 'value' => '{"a":1}', 'type' => 'object', 'environment' => null, 'tag' => null],
        ]));

        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'configuration:restore', '--file' => $file, '--strategy' => 'replace']), $output);
        $text = $output->fetch();
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('Successfully imported configuration with replace strategy. Statistics: 1 created, 0 replaced.', $text);

        // each command boots its own in-memory database, so the dump of a fresh instance is empty
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'configuration:dump']), $output);
        self::assertSame(0, $code);
        self::assertSame("[]\n", $output->fetch());

        unlink($file);
    }

    public function testAdminCommands(): void
    {
        $application = Cli::createCLI([], self::$appDir);
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'admin:create-user', '--email' => 'not-an-email']), $output);
        self::assertSame(1, $code);
        self::assertStringContainsString('Invalid email address', $output->fetch());

        // each command boots its own instance on an in-memory database
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'admin:create-user', '--email' => 'a@b.co', '--password' => 'Passw0rd', '--firstname' => 'A']), $output);
        self::assertSame(0, $code, $output->fetch());

        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'admin:reset-user-password', '--email' => 'a@b.co', '--password' => 'Passw0rd2']), $output);
        self::assertSame(1, $code);
        self::assertStringContainsString('User not found for email: a@b.co', $output->fetch());

        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'admin:reset-user-password', '--email' => 'a@b.co', '--password' => 'weak']), $output);
        self::assertSame(1, $code);
        self::assertStringContainsString('Password must be at least 8 characters long', $output->fetch());
    }
}

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

        foreach (['start', 'develop', 'build', 'console', 'version', 'routes:list', 'content-types:list', 'cron:run', 'migrations:run', 'configuration:dump', 'configuration:restore', 'admin:create-user', 'admin:reset-user-password', 'telemetry:enable', 'telemetry:disable', 'components:list', 'controllers:list', 'hooks:list', 'middlewares:list', 'policies:list', 'services:list', 'report', 'templates:generate', 'openapi:generate'] as $name) {
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

    /** components:list, controllers:list, hooks:list, middlewares:list, policies:list, services:list */
    public function testRegistryListCommands(): void
    {
        $expected = [
            'components:list' => ['basic.simple', 'blog.test-como'],
            'controllers:list' => ['api::article.article', 'plugin::upload.content-api'],
            'hooks:list' => ['strapi::content-types.beforeSync', 'strapi::content-types.afterSync'],
            'middlewares:list' => ['strapi::errors', 'strapi::body'],
            'policies:list' => ['global::deny', 'admin::isAuthenticatedAdmin'],
            'services:list' => ['api::article.article', 'plugin::upload.upload'],
        ];

        foreach ($expected as $command => $names) {
            $application = Cli::createCLI([], self::$appDir);
            $output = new BufferedOutput();
            $code = $application->run(new ArrayInput(['command' => $command]), $output);
            $text = $output->fetch();

            self::assertSame(0, $code, $text);
            self::assertStringContainsString('| Name', $text, $command);
            foreach ($names as $name) {
                self::assertStringContainsString("| {$name} ", $text, $command);
            }
        }
    }

    public function testReportCommand(): void
    {
        $application = Cli::createCLI([], self::$appDir);
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'report', '--all' => true]), $output);
        $text = $output->fetch();

        self::assertSame(0, $code, $text);
        self::assertMatchesRegularExpression('/^Launched In: \d+ ms$/m', $text);
        self::assertStringContainsString('Environment: ', $text);
        self::assertStringContainsString('Strapi Version: ' . \Strapi\Core\Configuration\Configuration::upstreamVersion(), $text);
        self::assertStringContainsString('PHP Version: ' . PHP_VERSION, $text);
        self::assertStringContainsString('Edition: Community', $text);
        self::assertStringContainsString('Database: sqlite', $text);
        self::assertStringContainsString('UUID: ', $text);
        self::assertStringContainsString('Dependencies: {', $text);

        $output = new BufferedOutput();
        $application->run(new ArrayInput(['command' => 'report']), $output);
        self::assertStringNotContainsString('UUID: ', $output->fetch());
    }

    public function testTemplatesGenerateIsDeprecated(): void
    {
        $application = Cli::createCLI([], sys_get_temp_dir());
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'templates:generate', 'directory' => 'tpl']), $output);

        self::assertSame(0, $code);
        self::assertSame("This command is deprecated and will be removed in the next major release.\nYou can now copy an existing app and use it as a template.\n", $output->fetch());
    }

    public function testCronRunAndMigrationsRun(): void
    {
        $application = Cli::createCLI([], self::$appDir);

        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'cron:run']), $output));
        // the upload plugin registers its weekly metrics job (`uploadWeekly`), scheduled 15s from boot
        self::assertMatchesRegularExpression('/Ran [01] cron job\(s\)/', $output->fetch());

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

        // each command boots its own in-memory database, so the dump of a fresh instance does not
        // hold the restored key (only what plugins store at bootstrap: upload settings, CM configurations)
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'configuration:dump']), $output);
        self::assertSame(0, $code);
        $dump = json_decode($output->fetch(), true);
        self::assertIsArray($dump);
        self::assertNotContains('plugin_test_setting', array_column($dump, 'key'));

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

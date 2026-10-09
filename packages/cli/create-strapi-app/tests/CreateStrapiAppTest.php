<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\CreateStrapiApp\CreateStrapi;
use Strapi\CreateStrapiApp\CreateStrapiApp;
use Strapi\CreateStrapiApp\Utils\Template;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The command end to end without installing dependencies (`--no-install --no-git-init`):
 * what `npx create-strapi-app` writes, PHP edition.
 */
final class CreateStrapiAppTest extends TestCase
{
    private string $dir;

    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->dir = sys_get_temp_dir() . '/csa-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        Template::removeDirectory($this->dir);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{int, string}
     */
    private function create(array $args, bool $interactive = false): array
    {
        $command = CreateStrapiApp::command();
        $command->setAutoExit(false);
        $input = new ArrayInput($args);
        $input->setInteractive($interactive);
        $output = new BufferedOutput();
        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        $json = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($json, $file);

        return $json;
    }

    public function testCreatesTheVanillaProject(): void
    {
        [$code, $out] = $this->create(['directory' => 'my-app', '--no-install' => true, '--no-git-init' => true]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Your application was created!', $out);
        $root = $this->dir . '/my-app';

        foreach ([
            '.env', '.env.example', '.gitignore', 'README.md', 'composer.json', 'package.json', 'favicon.png',
            'bin/strapi', 'public/index.php', 'public/robots.txt', 'public/uploads/.gitkeep', 'src/index.php',
            'src/api/.gitkeep', 'src/extensions/.gitkeep', 'src/admin/app.example.tsx', 'database/migrations/.gitkeep',
            'config/admin.php', 'config/api.php', 'config/database.php', 'config/middlewares.php', 'config/plugins.php', 'config/server.php',
        ] as $file) {
            self::assertFileExists("{$root}/{$file}");
        }
        self::assertTrue(is_executable("{$root}/bin/strapi"));
        self::assertDirectoryDoesNotExist("{$root}/src/api/article");

        $composer = self::json("{$root}/composer.json");
        $version = CreateStrapiApp::version();
        // strapi-php is one package (it replaces every strapi/* package)
        self::assertSame(['php', 'ext-pdo_sqlite', 'hynding/strapi-php'], array_keys($composer['require']));
        self::assertSame('*', $composer['require']['ext-pdo_sqlite']);
        self::assertSame('@php bin/strapi develop', $composer['scripts']['develop']);
        if (str_contains($version, '-')) {
            self::assertSame($version, $composer['require']['hynding/strapi-php']);
            self::assertSame('beta', $composer['minimum-stability']);
            self::assertTrue($composer['prefer-stable']);
        }

        $pkg = self::json("{$root}/package.json");
        $upstream = CreateStrapiApp::upstreamVersion();
        self::assertSame('my-app', $pkg['name']);
        self::assertSame($upstream, $pkg['dependencies']['@strapi/admin']);
        self::assertSame($upstream, $pkg['dependencies']['@strapi/strapi']);
        self::assertSame($upstream, $pkg['dependencies']['@strapi/plugin-users-permissions']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $pkg['strapi']['uuid']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36,64}$/', $pkg['strapi']['installId']);

        $env = (string) file_get_contents("{$root}/.env");
        self::assertStringContainsString("DATABASE_CLIENT=sqlite\n", $env);
        self::assertStringNotContainsString('tobemodified', $env);
        self::assertStringContainsString("\nvendor\n", (string) file_get_contents("{$root}/.gitignore"));
    }

    public function testTheFrontControllerIsStrapisTemplate(): void
    {
        $strapiTemplate = dirname(__DIR__, 3) . '/core/strapi/templates/index.php';
        if (!is_file($strapiTemplate)) {
            self::markTestSkipped('needs the monorepo');
        }

        foreach (['vanilla', 'example'] as $template) {
            self::assertFileEquals($strapiTemplate, CreateStrapi::templatesDir() . "/{$template}/public/index.php", "templates/{$template}/public/index.php drifted from strapi/strapi templates/index.php");
        }
    }

    public function testCreatesTheExampleProject(): void
    {
        [$code, $out] = $this->create(['directory' => 'blog', '--example' => true, '--no-install' => true, '--no-git-init' => true]);

        self::assertSame(0, $code, $out);
        $root = $this->dir . '/blog';
        foreach (['article', 'author', 'category', 'global', 'about'] as $api) {
            self::assertFileExists("{$root}/src/api/{$api}/content-types/{$api}/schema.json");
            self::assertFileExists("{$root}/src/api/{$api}/controllers/{$api}.php");
        }
        self::assertFileExists("{$root}/src/components/shared/media.json");
        self::assertFileExists("{$root}/data/data.json");
        self::assertFileExists("{$root}/scripts/seed.php");
        self::assertSame('php scripts/seed.php', self::json("{$root}/package.json")['scripts']['seed:example']);
        self::assertStringContainsString('scripts/seed.php', $out);
    }

    public function testWritesTheDatabaseFlagsToDotEnv(): void
    {
        [$code, $out] = $this->create([
            'directory' => 'pg-app', '--no-install' => true, '--no-git-init' => true,
            '--dbclient' => 'postgres', '--dbhost' => 'db.local', '--dbport' => '5432', '--dbname' => 'strapi',
            '--dbusername' => 'strapi', '--dbpassword' => 's3cret', '--dbssl' => 'false',
        ]);

        self::assertSame(0, $code, $out);
        $env = (string) file_get_contents($this->dir . '/pg-app/.env');
        self::assertStringContainsString("DATABASE_CLIENT=postgres\nDATABASE_HOST=db.local\nDATABASE_PORT=5432\nDATABASE_NAME=strapi\nDATABASE_USERNAME=strapi\nDATABASE_PASSWORD=s3cret\nDATABASE_SSL=false\n", $env);
        self::assertSame('*', self::json($this->dir . '/pg-app/composer.json')['require']['ext-pdo_pgsql']);
    }

    public function testCopiesALocalTemplate(): void
    {
        $template = $this->dir . '/tpl';
        mkdir($template . '/config', 0777, true);
        file_put_contents($template . '/composer.json', json_encode(['scripts' => ['hello' => 'echo hi']]));
        file_put_contents($template . '/config/custom.php', "<?php\n\nreturn ['ok' => true];\n");

        [$code, $out] = $this->create(['directory' => 'from-tpl', '--template' => $template, '--no-install' => true, '--no-git-init' => true]);

        self::assertSame(0, $code, $out);
        self::assertFileExists($this->dir . '/from-tpl/config/custom.php');
        self::assertSame('echo hi', self::json($this->dir . '/from-tpl/composer.json')['scripts']['hello']);
        self::assertSame($template, self::json($this->dir . '/from-tpl/package.json')['strapi']['template']);
    }

    public function testAFailedTemplateRemovesTheProject(): void
    {
        $template = $this->dir . '/not-a-project';
        mkdir($template);
        file_put_contents($template . '/README.md', 'nope');

        [$code, $out] = $this->create(['directory' => 'bad', '--template' => $template, '--no-install' => true]);

        self::assertSame(1, $code);
        self::assertStringContainsString('Missing composer.json in template', $out);
        self::assertDirectoryDoesNotExist($this->dir . '/bad');
    }

    public function testRefusesANonEmptyDirectory(): void
    {
        mkdir($this->dir . '/busy');
        touch($this->dir . '/busy/a');
        touch($this->dir . '/busy/b');

        [$code, $out] = $this->create(['directory' => 'busy', '--no-install' => true]);

        self::assertSame(1, $code);
        self::assertStringContainsString('You can only create a Strapi app in an empty directory', $out);
    }

    public function testValidatesTheFlags(): void
    {
        [$code, $out] = $this->create(['directory' => 'x', '--use-npm' => true, '--use-yarn' => true]);
        self::assertSame(1, $code);
        self::assertStringContainsString('You cannot specify multiple package managers', $out);

        [$code, $out] = $this->create(['directory' => 'x', '--example' => true, '--template' => 'blog']);
        self::assertSame(1, $code);
        self::assertStringContainsString('You cannot use --example with --template', $out);

        [$code, $out] = $this->create(['--non-interactive' => true]);
        self::assertSame(1, $code);
        self::assertStringContainsString('Please specify the <directory> of your project when using --non-interactive', $out);

        self::assertDirectoryDoesNotExist($this->dir . '/x');
    }

    public function testPromptsForTheProject(): void
    {
        $command = CreateStrapiApp::command();
        $command->setAutoExit(false);
        $input = new ArrayInput(['--no-install' => true]);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        // directory, default database, example (no), git init (no)
        fwrite($stream, "prompted\n\nn\nn\n");
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput();

        $code = $command->run($input, $output);

        $out = $output->fetch();
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('What is the name of your project?', $out);
        self::assertStringContainsString('Do you want to use the default database (sqlite) ?', $out);
        self::assertFileExists($this->dir . '/prompted/composer.json');
        self::assertDirectoryDoesNotExist($this->dir . '/prompted/.git');
    }

    public function testInPlaceReplacesTheBootstrapper(): void
    {
        // what `composer create-project hynding/create-strapi-app my-project` leaves behind
        $root = $this->dir . '/my-project';
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/vendor/acme', 0777, true);
        file_put_contents($root . '/composer.json', json_encode(['name' => 'hynding/create-strapi-app']));
        file_put_contents($root . '/composer.lock', '{}');
        file_put_contents($root . '/src/index.php', '<?php // bootstrapper');
        file_put_contents($root . '/vendor/acme/kept.txt', 'kept');
        chdir($root);

        [$code, $out] = $this->create(['--in-place' => true, '--no-install' => true, '--no-git-init' => true]);

        self::assertSame(0, $code, $out);
        self::assertFileDoesNotExist($root . '/composer.lock');
        self::assertFileExists($root . '/vendor/acme/kept.txt');
        self::assertStringContainsString("'bootstrap'", (string) file_get_contents($root . '/src/index.php'));
        self::assertSame('my-project', self::json($root . '/package.json')['name']);
        self::assertArrayHasKey('hynding/strapi-php', self::json($root . '/composer.json')['require']);
    }

    public function testInPlaceOnlyRunsInABootstrapperDirectory(): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['name' => 'acme/app']));
        touch($this->dir . '/precious.txt');

        [$code, $out] = $this->create(['--in-place' => true, '--no-install' => true]);

        self::assertSame(1, $code);
        self::assertStringContainsString('--in-place only runs in the directory', $out);
        self::assertFileExists($this->dir . '/precious.txt');
    }

    public function testSplitsTheArgumentsEnvironmentVariable(): void
    {
        self::assertSame(
            ['--quickstart', '--dbpassword', 'with space', '--dbname=x', "it's"],
            CreateStrapiApp::splitArgs('  --quickstart --dbpassword "with space"  --dbname=x "it\'s" '),
        );
    }

    public function testUpstreamVersion(): void
    {
        self::assertSame('5.56.0', CreateStrapiApp::upstreamVersion('5.56.0-beta.1'));
        self::assertSame('5.56.0', CreateStrapiApp::upstreamVersion('5.56.0.1'));
        self::assertSame('5.56.0', CreateStrapiApp::upstreamVersion('5.56.0'));
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Strapi\Cli\Cli\Cli;
use Strapi\Cli\Cli\Commands\Generate;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/** `strapi generate` (packages/core/strapi/src/cli/commands/generate.ts): the CLI around strapi/generators. */
final class GenerateTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/strapi-generate-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0o777, true);
        file_put_contents($this->project . '/composer.json', '{"require": {"strapi/strapi": "^5.56"}}');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->project);
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string}
     */
    private function runCli(array $argv): array
    {
        $application = Cli::createCLI($argv, $this->project);
        $output = new BufferedOutput();
        $code = $application->run(new ArgvInput(['strapi', ...$argv]), $output);

        return [$code, $output->fetch()];
    }

    public function testIsRegistered(): void
    {
        $application = Cli::createCLI([], $this->project);

        self::assertTrue($application->has('generate'));
        self::assertSame('Launch the interactive API generator', $application->find('generate')->getDescription());
    }

    public function testNamedAnswersAfterTheDoubleDash(): void
    {
        [$code, $output] = $this->runCli(['generate', 'controller', '-n', '--', '--id=hello', '--destination', 'new']);

        self::assertSame(0, $code, $output);
        self::assertSame("✔  ++ /src/api/hello/controllers/hello.php\n", $output);
        self::assertFileExists($this->project . '/src/api/hello/controllers/hello.php');
    }

    public function testPositionalAnswers(): void
    {
        [$code, $output] = $this->runCli(['generate', 'api', 'hello', 'false']);

        self::assertSame(0, $code, $output);
        self::assertFileExists($this->project . '/src/api/hello/routes/hello.php');
    }

    public function testInteractive(): void
    {
        $application = Cli::createCLI([], $this->project);
        $tester = new CommandTester($application->find('generate'));
        // generator menu, policy name, destination
        $tester->setInputs(['policy', 'is-owner', '0']);

        $code = $tester->execute(['command' => 'generate']);

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertFileExists($this->project . '/src/policies/is-owner.php');
        self::assertStringContainsString('Policy name', $tester->getDisplay());
    }

    public function testErrorsExitWithCodeOne(): void
    {
        [$code, $output] = $this->runCli(['generate', 'api', '-n']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Missing answer for "id"', $output);
    }

    public function testRefusesToRunOutsideAStrapiProject(): void
    {
        unlink($this->project . '/composer.json');

        [$code, $output] = $this->runCli(['generate', 'api', 'hello', 'false']);

        self::assertSame(1, $code);
        self::assertStringContainsString('You need to run strapi generate in a Strapi project', $output);
        self::assertDirectoryDoesNotExist($this->project . '/src');
    }

    public function testParseAnswers(): void
    {
        self::assertSame(
            [['hello', '_'], ['id' => 'a=b', 'destination' => 'new', 'bootstrapApi' => 'true', 'flag' => 'true']],
            Generate::parseAnswers(['hello', '_', '--', '--id=a=b', '--destination', 'new', '--bootstrapApi', '--flag']),
        );
    }
}

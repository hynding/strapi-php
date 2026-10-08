<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Cli;

use Strapi\Upgrade\Cli\Cli;
use Strapi\Upgrade\Cli\Errors;
use Strapi\Upgrade\Modules\Error\AbortedError;
use Strapi\Upgrade\Tests\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/** PHP-only: the `strapi-upgrade` program (upstream's cli/index.ts, commands and options). */
final class CliTest extends TestCase
{
    public function testNormalizesUpstreamsCodemodsSubcommands(): void
    {
        self::assertSame(['bin', 'codemods:run', 'uid', '-n'], Cli::normalizeArgv(['bin', 'codemods', 'run', 'uid', '-n']));
        self::assertSame(['bin', 'codemods:ls', '--range', '5'], Cli::normalizeArgv(['bin', 'codemods', 'ls', '--range', '5']));
        self::assertSame(['bin', 'list', 'codemods'], Cli::normalizeArgv(['bin', 'codemods']));
        self::assertSame(['bin', 'minor', '-y'], Cli::normalizeArgv(['bin', 'minor', '-y']));
    }

    public function testRegistersUpstreamsCommandsAndOptions(): void
    {
        $program = Cli::create();

        foreach (['latest', 'major', 'minor', 'patch', 'to', 'codemods:run', 'codemods:ls'] as $name) {
            self::assertTrue($program->has($name), $name);
        }

        $to = $program->get('to')->getDefinition();
        foreach (['project-path' => 'p', 'dry' => 'n', 'debug' => 'd', 'silent' => 's', 'yes' => 'y', 'codemods-target' => 'c'] as $option => $shortcut) {
            self::assertSame($shortcut, $to->getOption($option)->getShortcut());
        }
        self::assertSame('r', $program->get('codemods:ls')->getDefinition()->getOption('range')->getShortcut());
        self::assertFalse($program->get('codemods:ls')->getDefinition()->hasOption('dry'));
    }

    public function testValidatesArguments(): void
    {
        $program = Cli::create();
        $program->setAutoExit(false);
        $tester = new ApplicationTester($program);

        self::assertSame(1, $tester->run(['command' => 'to', 'target' => 'nope']));
        self::assertStringContainsString('Invalid target supplied, expected a valid semver but got "nope"', $tester->getDisplay());

        self::assertSame(1, $tester->run(['command' => 'to', 'target' => '5.57.0', '--codemods-target' => '5.57']));
        self::assertStringContainsString('Expected a version with the following format: "<number>.<number>.<number>"', $tester->getDisplay());

        self::assertSame(1, $tester->run(['command' => 'codemods:ls', '--range' => 'nope']));
        self::assertStringContainsString('Expected a valid semver range', $tester->getDisplay());
    }

    public function testListsCodemodsOfAProject(): void
    {
        $cwd = $this->volume(self::appTree('5.56.0'));
        $program = Cli::create();
        $program->setAutoExit(false);
        $tester = new ApplicationTester($program);

        ob_start();
        $code = $tester->run(['command' => 'codemods:ls', '--project-path' => $cwd, '--range' => '5.0.0', '--silent' => true]);
        ob_end_clean();

        self::assertSame(0, $code);
    }

    public function testHandleError(): void
    {
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);

        self::assertSame(0, Errors::handleError(new AbortedError(), false, $stderr));
        self::assertSame(1, Errors::handleError(new \RuntimeException('boom'), true, $stderr));
        self::assertSame('', self::read($stderr));
        self::assertSame(1, Errors::handleError(new \RuntimeException('boom'), false, $stderr));
        self::assertMatchesRegularExpression('/^\[ERROR\]\t\[[^\]]+\] boom\n$/', self::read($stderr));
    }
}

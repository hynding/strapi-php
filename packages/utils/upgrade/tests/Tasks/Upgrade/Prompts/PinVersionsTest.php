<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Tasks\Upgrade\Prompts;

use Strapi\Upgrade\Modules\Error\AbortedError;
use Strapi\Upgrade\Modules\Project\AppProject;
use Strapi\Upgrade\Modules\Version\Types;
use Strapi\Upgrade\Tasks\Upgrade\Prompts\PinVersions;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/tasks/upgrade/prompts/__tests__/pin-versions.test.ts (both manifests). */
final class PinVersionsTest extends TestCase
{
    private string $cwd;

    /**
     * @param array<string, string> $dependencies
     * @param array<string, string> $devDependencies
     */
    private function createProject(string $composerVersion, array $dependencies, array $devDependencies = []): AppProject
    {
        $this->cwd = $this->volume([
            'composer.json' => (string) json_encode(['name' => 'acme/test-app', 'require' => ['strapi/strapi' => $composerVersion]]),
            'package.json' => (string) json_encode(['name' => 'test-app', 'version' => '0.1.0', 'dependencies' => $dependencies, ...($devDependencies !== [] ? ['devDependencies' => $devDependencies] : [])]),
            'vendor' => ['composer' => ['installed.json' => '{"packages": [{"name": "strapi/strapi", "version": "4.26.2"}]}']],
        ]);

        return new AppProject($this->cwd);
    }

    public function testDoesNothingWhenAllStrapiDependenciesArePinned(): void
    {
        $project = $this->createProject('4.26.1', ['@strapi/strapi' => '4.26.1']);
        [$logger] = self::memoryLogger();
        $confirmed = false;

        PinVersions::pinVersions($project, ['logger' => $logger, 'target' => Types::MINOR, 'confirm' => static function () use (&$confirmed): bool {
            $confirmed = true;

            return true;
        }]);

        self::assertFalse($confirmed);
        self::assertSame(0, $logger->warnings());
    }

    public function testPinsRangedStrapiDependenciesAfterConfirmation(): void
    {
        $project = $this->createProject('^4.26.1', ['@strapi/strapi' => '^4.26.1'], ['@strapi/types' => '^4.26.1']);
        [$logger] = self::memoryLogger();

        PinVersions::pinVersions($project, ['logger' => $logger, 'target' => Types::MINOR, 'confirm' => static fn (): bool => true]);

        $packageJSON = self::readJson("{$this->cwd}/package.json");
        self::assertSame('4.26.1', $packageJSON['dependencies']['@strapi/strapi']);
        self::assertSame('4.26.1', $packageJSON['devDependencies']['@strapi/types']);
        self::assertSame('4.26.1', self::readJson("{$this->cwd}/composer.json")['require']['strapi/strapi']);
        self::assertSame('4.26.1', $project->strapiVersion->raw);
    }

    public function testPinsTheNpmSideToTheUpstreamReleaseOfABeta(): void
    {
        $project = $this->createProject('5.56.0-beta.1', ['@strapi/admin' => '^5.56.0']);
        [$logger] = self::memoryLogger();

        PinVersions::pinVersions($project, ['logger' => $logger, 'target' => Types::MINOR]);

        self::assertSame('5.56.0', self::readJson("{$this->cwd}/package.json")['dependencies']['@strapi/admin']);
        self::assertSame('5.56.0-beta.1', self::readJson("{$this->cwd}/composer.json")['require']['strapi/strapi']);
    }

    public function testAbortsWhenTheUserDeclinesPinning(): void
    {
        $project = $this->createProject('^4.26.1', ['@strapi/strapi' => '^4.26.1']);
        [$logger] = self::memoryLogger();

        $this->expectException(AbortedError::class);
        PinVersions::pinVersions($project, ['logger' => $logger, 'target' => Types::MINOR, 'confirm' => static fn (): bool => false]);
    }

    public function testPinsInMemoryOnlyDuringDryRuns(): void
    {
        $project = $this->createProject('^4.26.1', ['@strapi/strapi' => '^4.26.1']);
        [$logger] = self::memoryLogger();

        PinVersions::pinVersions($project, ['logger' => $logger, 'target' => Types::MINOR, 'confirm' => static fn (): bool => true, 'dry' => true]);

        self::assertSame('^4.26.1', self::readJson("{$this->cwd}/package.json")['dependencies']['@strapi/strapi']);
        self::assertSame('^4.26.1', self::readJson("{$this->cwd}/composer.json")['require']['strapi/strapi']);
        self::assertSame('4.26.1', $project->strapiVersion->raw);
        self::assertSame('4.26.1', $project->packageJSON['dependencies']['@strapi/strapi']);
    }
}

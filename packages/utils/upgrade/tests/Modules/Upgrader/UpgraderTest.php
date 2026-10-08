<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Upgrader;

use Strapi\Upgrade\Modules\CodemodRunner\CodemodRunner;
use Strapi\Upgrade\Modules\Error\NPMCandidateNotFoundError;
use Strapi\Upgrade\Modules\Npm\PackageInterface;
use Strapi\Upgrade\Modules\Project\AppProject;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Requirement\Requirement;
use Strapi\Upgrade\Modules\Upgrader\Upgrader;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Range;
use Strapi\Upgrade\Modules\Version\Types;
use Strapi\Upgrade\Tests\TestCase;

/**
 * Port of src/modules/upgrader/__tests__/upgrader.test.ts. The codemod runner and the installer
 * are replaced through the upgrader's seams instead of module mocks; versions are strapi-php's.
 */
final class UpgraderTest extends TestCase
{
    private string $cwd;

    private ?SemverRange $codemodRunnerRange = null;

    private ?bool $codemodRunnerDry = null;

    /** @var list<array{string, string}> */
    private array $installs = [];

    /**
     * @param array<string, string> $extraRequire
     * @param array<string, string> $extraDependencies
     */
    private function createProject(string $projectVersion, array $extraRequire = [], array $extraDependencies = [], ?string $npmVersion = null): AppProject
    {
        $tree = self::appTree($projectVersion, ['strapi/plugin-graphql' => $projectVersion, ...$extraRequire], $extraDependencies, ['package-lock.json' => '{}'], $npmVersion);
        $package = json_decode($tree['package.json'], true);
        $package['devDependencies'] = ['@strapi/types' => $package['dependencies']['@strapi/strapi']];
        $tree['package.json'] = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $this->cwd = $this->volume($tree);

        return new AppProject($this->cwd);
    }

    /**
     * @param (\Closure(NodeSemVer): ?array<string, mixed>)|null $findVersion
     * @param (\Closure(SemverRange): list<array<string, mixed>>)|null $findVersionsInRange
     */
    private static function npmPackageStub(?\Closure $findVersion = null, ?\Closure $findVersionsInRange = null): PackageInterface
    {
        return new class ($findVersion ?? static fn (NodeSemVer $v): array => ['version' => $v->raw], $findVersionsInRange ?? static fn (): array => []) implements PackageInterface {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private \Closure $findVersion, private \Closure $findVersionsInRange)
            {
            }

            public function name(): string
            {
                return 'strapi/strapi';
            }

            public function isLoaded(): bool
            {
                return true;
            }

            public function refresh(): static
            {
                return $this;
            }

            public function versionExists(NodeSemVer $version): bool
            {
                return $this->findVersion($version) !== null;
            }

            public function getVersionsDict(): array
            {
                return [];
            }

            public function getVersionsAsList(): array
            {
                return [];
            }

            public function findVersion(NodeSemVer $version): ?array
            {
                $this->calls[] = $version->raw;

                return ($this->findVersion)($version);
            }

            public function findVersionsInRange(SemverRange $range): array
            {
                return ($this->findVersionsInRange)($range);
            }
        };
    }

    private function prepare(Upgrader $upgrader): Upgrader
    {
        return $upgrader
            ->setCodemodRunnerFactory(function (Project $project, SemverRange $range): CodemodRunner {
                $this->codemodRunnerRange = $range;

                return new class ($project, $range, $this) extends CodemodRunner {
                    public function __construct(Project $project, SemverRange $range, private UpgraderTest $test)
                    {
                        parent::__construct($project, $range);
                    }

                    public function dry(bool $enabled = true): static
                    {
                        $this->test->recordDry($enabled);

                        return parent::dry($enabled);
                    }

                    public function run(?string $codemodsDirectory = null): array
                    {
                        return ['success' => true, 'error' => null];
                    }
                };
            })
            ->setInstaller(function (string $tool, string $cwd): void {
                $this->installs[] = [$tool, $cwd];
            });
    }

    public function recordDry(bool $dry): void
    {
        $this->codemodRunnerDry = $dry;
    }

    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        return self::readJson("{$this->cwd}/composer.json");
    }

    /** @return array<string, mixed> */
    private function packageJson(): array
    {
        return self::readJson("{$this->cwd}/package.json");
    }

    public function testResolvesAnExactSemverTarget(): void
    {
        $project = $this->createProject('5.8.1');
        $stub = self::npmPackageStub();

        $upgrader = Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), $stub);

        self::assertSame('5.9.0', $upgrader->getTarget()->raw);
        self::assertSame(['5.9.0'], $stub->calls ?? null);
    }

    public function testResolvesPreReleaseAndFourthNumberTargets(): void
    {
        $project = $this->createProject('5.8.1');

        self::assertSame('5.9.0-beta.1', Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0-beta.1'), self::npmPackageStub())->getTarget()->raw);
        self::assertSame('5.8.1.1', Upgrader::upgraderFactory($project, new NodeSemVer('5.8.1.1'), self::npmPackageStub())->getTarget()->raw);
    }

    public function testResolvesTheLatestVersionInRangeForReleaseTypes(): void
    {
        $project = $this->createProject('5.8.1');

        self::assertSame('6.0.1', Upgrader::upgraderFactory($project, Types::MAJOR, self::npmPackageStub(null, static fn (): array => [['version' => '6.0.0'], ['version' => '6.0.1']]))->getTarget()->raw);
        self::assertSame('5.9.0', Upgrader::upgraderFactory($project, Types::MINOR, self::npmPackageStub(null, static fn (): array => [['version' => '5.8.2'], ['version' => '5.9.0']]))->getTarget()->raw);
    }

    public function testThrowsWhenTheTargetVersionIsNotAvailable(): void
    {
        $project = $this->createProject('5.8.1');

        $this->expectException(NPMCandidateNotFoundError::class);
        Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub(static fn (): ?array => null));
    }

    public function testThrowsWhenNoVersionsMatchAReleaseType(): void
    {
        $project = $this->createProject('5.8.1');

        $this->expectExceptionMessage('The project is already up-to-date (patch)');
        Upgrader::upgraderFactory($project, Types::PATCH, self::npmPackageStub());
    }

    public function testThrowsWhenTheProjectIsAlreadyOnTheTargetVersion(): void
    {
        $project = $this->createProject('5.8.1');

        $this->expectExceptionMessage('The project is already using v5.8.1');
        Upgrader::upgraderFactory($project, new NodeSemVer('5.8.1'), self::npmPackageStub());
    }

    public function testThrowsWhenTheTargetVersionIsOlder(): void
    {
        $project = $this->createProject('5.8.1');

        $this->expectExceptionMessage('The target version v5.7.0 must be greater than the current version v5.8.1');
        Upgrader::upgraderFactory($project, new NodeSemVer('5.7.0'), self::npmPackageStub());
    }

    public function testUpdatesBothManifestsInLockstep(): void
    {
        $project = $this->createProject('5.8.1');
        $upgrader = $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()));

        $report = $upgrader->upgrade();

        self::assertTrue($report['success']);
        self::assertSame('5.9.0', $this->composerJson()['require']['strapi/strapi']);
        self::assertSame('5.9.0', $this->composerJson()['require']['strapi/plugin-graphql']);
        self::assertSame('>=8.3', $this->composerJson()['require']['php']);
        self::assertSame('5.9.0', $this->packageJson()['dependencies']['@strapi/strapi']);
        self::assertSame('5.9.0', $this->packageJson()['dependencies']['@strapi/admin']);
        self::assertSame('5.9.0', $this->packageJson()['devDependencies']['@strapi/types']);
    }

    public function testOnlyUpdatesPackagesThatMatchTheCurrentVersion(): void
    {
        $project = $this->createProject('5.8.1', ['strapi/provider-upload-aws-s3' => '5.8.0'], ['@strapi/plugin-users-permissions' => '5.8.0']);

        $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()))->upgrade();

        self::assertSame('5.9.0', $this->composerJson()['require']['strapi/strapi']);
        self::assertSame('5.8.0', $this->composerJson()['require']['strapi/provider-upload-aws-s3']);
        self::assertSame('5.8.0', $this->packageJson()['dependencies']['@strapi/plugin-users-permissions']);
    }

    public function testABetaToStableUpgradeKeepsTheNpmPins(): void
    {
        $project = $this->createProject('5.56.0-beta.1');
        $before = (string) file_get_contents("{$this->cwd}/package.json");

        $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.56.0'), self::npmPackageStub()))->upgrade();

        self::assertSame('5.56.0', $this->composerJson()['require']['strapi/strapi']);
        self::assertSame($before, file_get_contents("{$this->cwd}/package.json"));
    }

    public function testAPreReleaseTargetLowersMinimumStability(): void
    {
        $project = $this->createProject('5.56.0');

        $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.57.0-beta.1'), self::npmPackageStub()))->upgrade();

        self::assertSame('5.57.0-beta.1', $this->composerJson()['require']['strapi/strapi']);
        self::assertSame('beta', $this->composerJson()['minimum-stability']);
        self::assertTrue($this->composerJson()['prefer-stable']);
        self::assertSame('5.57.0', $this->packageJson()['dependencies']['@strapi/admin']);
    }

    public function testRunsCodemodsFromTheCurrentVersionToTheTarget(): void
    {
        $project = $this->createProject('5.8.1');
        $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()))->upgrade();

        self::assertSame(Range::rangeFromVersions($project->strapiVersion, new NodeSemVer('5.9.0'))->raw, $this->codemodRunnerRange?->raw);
    }

    public function testUsesTheMajorMinorPatchPortionOfPreReleaseTargetsForCodemods(): void
    {
        $project = $this->createProject('5.8.1');
        $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0-beta.1'), self::npmPackageStub()))->upgrade();

        self::assertSame('>5.8.1 <=5.9.0', $this->codemodRunnerRange?->raw);
    }

    public function testUsesAnOverriddenCodemodsTarget(): void
    {
        $project = $this->createProject('5.8.1');
        $upgrader = $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0-beta.1'), self::npmPackageStub()));
        $upgrader->overrideCodemodsTarget(new NodeSemVer('5.0.0'));

        $upgrader->upgrade();

        self::assertSame('>5.8.1 <=5.0.0', $this->codemodRunnerRange?->raw);
    }

    public function testDryModeWritesNothingInstallsNothingAndIsPassedToTheCodemodRunner(): void
    {
        $project = $this->createProject('5.8.1');
        $composer = (string) file_get_contents("{$this->cwd}/composer.json");
        $package = (string) file_get_contents("{$this->cwd}/package.json");

        $report = $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()))->dry(true)->upgrade();

        self::assertTrue($report['success']);
        self::assertSame($composer, file_get_contents("{$this->cwd}/composer.json"));
        self::assertSame($package, file_get_contents("{$this->cwd}/package.json"));
        self::assertSame([], $this->installs);
        self::assertTrue($this->codemodRunnerDry);
    }

    public function testInstallsDependenciesAfterUpdatingTheManifests(): void
    {
        $project = $this->createProject('5.8.1');

        $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()))->upgrade();

        self::assertSame([['composer', $this->cwd], ['npm', $this->cwd]], $this->installs);
    }

    public function testReturnsAnErrorReportWhenARequiredRequirementFails(): void
    {
        $project = $this->createProject('5.8.1');
        $upgrader = $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()))
            ->addRequirement(Requirement::requirementFactory('REQUIRE_CLEAN_GIT', static function (): void {
                throw new \RuntimeException('Working tree is dirty');
            }));

        $report = $upgrader->upgrade();

        self::assertFalse($report['success']);
        self::assertStringContainsString('Working tree is dirty', $report['error']->getMessage());
        self::assertSame('5.8.1', $this->composerJson()['require']['strapi/strapi']);
    }

    public function testOptionalRequirements(): void
    {
        $failing = Requirement::requirementFactory('OPTIONAL_CHECK', static function (): void {
            throw new \RuntimeException('Optional check failed');
        })->asOptional();

        $project = $this->createProject('5.8.1');
        $report = $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()))
            ->addRequirement($failing)->onConfirm(static fn (): bool => false)->upgrade();
        self::assertFalse($report['success']);
        self::assertStringContainsString('Optional check failed', $report['error']->getMessage());

        $project = $this->createProject('5.8.1');
        $report = $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()))
            ->addRequirement($failing)->onConfirm(static fn (): bool => true)->upgrade();
        self::assertTrue($report['success']);
        self::assertSame('5.9.0', $this->composerJson()['require']['strapi/strapi']);
    }

    public function testAdminViteCacheIsRemovedOnlyWhenConfirmed(): void
    {
        foreach ([[static fn (): bool => true, false], [static fn (): bool => false, true], [null, true]] as [$confirm, $kept]) {
            $project = $this->createProject('5.8.1');
            self::write($this->cwd, ['node_modules' => ['.strapi' => ['vite' => ['deps' => ['x.js' => '']]]]]);

            $this->prepare(Upgrader::upgraderFactory($project, new NodeSemVer('5.9.0'), self::npmPackageStub()))->onConfirm($confirm)->upgrade();

            self::assertSame($kept, is_dir("{$this->cwd}/node_modules/.strapi/vite"));
        }
    }
}

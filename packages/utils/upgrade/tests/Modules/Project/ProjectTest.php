<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Project;

use Strapi\Upgrade\Modules\Project\AppProject;
use Strapi\Upgrade\Modules\Project\PluginProject;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Project\Utils;
use Strapi\Upgrade\Tests\TestCase;

/**
 * Port of src/modules/project/__tests__/project.test.ts. A strapi-php application's version is
 * its `hynding/strapi-php` Composer requirement; its files include PHP sources and composer.json.
 */
final class ProjectTest extends TestCase
{
    private const DEFAULT_FILES = [
        'a.ts' => 'console.log("a.ts")',
        'b.ts' => 'console.log("b.ts")',
        'c.js' => 'console.log("c.js")',
        'd.json' => '{ "foo": "bar", "bar": 123 }',
        'e.jsx' => "console.log('e.jsx')",
        'f.tsx' => "console.log('f.tsx')",
        'g.php' => '<?php return [];',
    ];

    /** @return array<string, mixed> */
    private static function appVolume(string $version = '1.2.3'): array
    {
        return self::appTree($version, extra: ['src' => self::DEFAULT_FILES]);
    }

    /** @return array<string, mixed> */
    private static function pluginVolume(): array
    {
        return [
            'package.json' => '{ "name": "test", "version": "1.0.0", "strapi": { "kind": "plugin" } }',
            'server' => self::DEFAULT_FILES,
        ];
    }

    /** @return list<string> */
    private static function prefixed(string $cwd, string $dir): array
    {
        return array_map(static fn (string $f): string => "{$cwd}/{$dir}/{$f}", array_keys(self::DEFAULT_FILES));
    }

    public function testFailsOnInvalidProjectPath(): void
    {
        $this->expectExceptionMessage("ENOENT: no such file or directory, access 'unknown-path'");

        Project::projectFactory('unknown-path');
    }

    public function testFailsOnProjectWithoutPackageJsonFile(): void
    {
        $cwd = $this->volume(['src' => self::DEFAULT_FILES]);

        $this->expectExceptionMessage("Could not find a package.json file in {$cwd}");
        Project::projectFactory($cwd);
    }

    public function testFailsWithoutComposerJsonFile(): void
    {
        $cwd = $this->volume(['package.json' => '{ "name": "test", "dependencies": { "@strapi/strapi": "5.56.0" } }']);

        $this->expectExceptionMessage("Could not find a composer.json file in {$cwd}");
        Project::projectFactory($cwd);
    }

    public function testFailsWhenNotAPluginAndNoStrapiDependencyFound(): void
    {
        $cwd = $this->volume(['package.json' => '{ "name": "test", "version": "1.2.3" }', 'composer.json' => '{ "name": "acme/test", "require": {} }', 'src' => self::DEFAULT_FILES]);

        $this->expectExceptionMessage('No version of hynding/strapi-php was found in acme/test. Are you in a valid Strapi project?');
        Project::projectFactory($cwd);
    }

    public function testInstalledVersionFallbackFailsWhenNoVersionIsInstalled(): void
    {
        $cwd = $this->volume(self::appTree('^5.56', extra: ['src' => self::DEFAULT_FILES]));

        $this->expectExceptionMessage("Cannot resolve package \"hynding/strapi-php\" from paths [{$cwd}]");
        Project::projectFactory($cwd);
    }

    public function testInstalledVersionFallbackSucceeds(): void
    {
        $cwd = $this->volume(self::appTree('^5.56', extra: ['vendor' => ['composer' => ['installed.json' => '{"packages": [{"name": "hynding/strapi-php", "version": "v5.56.0-beta.1"}]}']]]));

        $project = Project::projectFactory($cwd);

        Utils::assertAppProject($project);
        self::assertSame('5.56.0-beta.1', $project->strapiVersion->raw);
    }

    public function testSucceedsForValidAppProject(): void
    {
        $cwd = $this->volume(self::appVolume('5.56.0-beta.1'));

        $project = Project::projectFactory($cwd);

        Utils::assertAppProject($project);
        self::assertSame('application', $project->type());
        self::assertCount(9, $project->files);
        self::assertEqualsCanonicalizing(["{$cwd}/composer.json", "{$cwd}/package.json", ...self::prefixed($cwd, 'src')], $project->files);
        self::assertSame($cwd, $project->cwd);
        self::assertSame('5.56.0-beta.1', $project->strapiVersion->raw);
        self::assertSame('5.56.0', $project->upstreamVersion());
    }

    public function testSucceedsForValidPluginProject(): void
    {
        $cwd = $this->volume(self::pluginVolume());

        $project = Project::projectFactory($cwd);

        Utils::assertPluginProject($project);
        self::assertSame('plugin', $project->type());
        self::assertInstanceOf(PluginProject::class, $project);
        self::assertCount(8, $project->files);
        self::assertEqualsCanonicalizing(["{$cwd}/package.json", ...self::prefixed($cwd, 'server')], $project->files);
    }

    public function testComposerExtraDeclaresAPhpPlugin(): void
    {
        $cwd = $this->volume(['package.json' => '{ "name": "x" }', 'composer.json' => '{ "extra": { "strapi": { "kind": "plugin" } } }']);

        self::assertTrue(Utils::isPluginProject(Project::projectFactory($cwd)));
    }

    public function testRefresh(): void
    {
        $cwd = $this->volume(self::appVolume());
        $project = Project::projectFactory($cwd);
        self::assertInstanceOf(AppProject::class, $project);

        file_put_contents("{$cwd}/src/new.php", '<?php');
        $project->refresh();

        self::assertCount(10, $project->files);
        self::assertSame('1.2.3', $project->strapiVersion->raw);
    }

    public function testGetFilesByExtensions(): void
    {
        $cwd = $this->volume(self::appVolume());
        $project = Project::projectFactory($cwd);
        $src = static fn (string ...$files): array => array_map(static fn (string $f): string => "{$cwd}/src/{$f}", $files);

        self::assertSame($src('c.js'), $project->getFilesByExtensions(['.js']));
        self::assertSame($src('a.ts', 'b.ts'), $project->getFilesByExtensions(['.ts']));
        self::assertSame($src('e.jsx'), $project->getFilesByExtensions(['.jsx']));
        self::assertSame($src('f.tsx'), $project->getFilesByExtensions(['.tsx']));
        self::assertSame($src('g.php'), $project->getFilesByExtensions(['.php']));
        self::assertSame($src('a.ts', 'b.ts', 'c.js'), $project->getFilesByExtensions(['.ts', '.js']));
        self::assertSame(["{$cwd}/composer.json", "{$cwd}/package.json", ...$src('a.ts', 'b.ts', 'd.json')], $project->getFilesByExtensions(['.ts', '.json']));
    }
}

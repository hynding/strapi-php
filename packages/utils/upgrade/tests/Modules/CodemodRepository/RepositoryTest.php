<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\CodemodRepository;

use Strapi\Upgrade\Modules\CodemodRepository\CodemodRepository;
use Strapi\Upgrade\Modules\CodemodRepository\Constants;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/codemod-repository/__tests__/repository.test.ts. */
final class RepositoryTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        parent::setUp();
        $srcFiles = ['a.code.php' => '<?php return null;', 'b.code.php' => '<?php return null;', 'not-a-codemod.php' => ''];
        $this->cwd = $this->volume(['1.0.0' => $srcFiles, '2.0.0' => $srcFiles, 'not-a-version' => $srcFiles]);
    }

    public function testConstructorInitializesWithAValidDirectory(): void
    {
        self::assertSame($this->cwd, (new CodemodRepository($this->cwd))->cwd);
    }

    public function testConstructorFailsWithAnInvalidDirectory(): void
    {
        $this->expectExceptionMessage('Invalid codemods directory provided "/invalid/path"');

        new CodemodRepository('/invalid/path');
    }

    public function testRefreshUpdatesTheRepositoryAndCount(): void
    {
        $version = new SemVer('1.0.0');
        $repo = new CodemodRepository($this->cwd);

        self::assertSame(0, $repo->count($version));
        $repo->refresh();
        self::assertSame(2, $repo->count($version));
    }

    public function testFindByVersion(): void
    {
        $version = new SemVer('1.0.0');
        $repo = new CodemodRepository($this->cwd);

        self::assertCount(0, $repo->findByVersion($version));

        $repo->refresh();
        $codemods = $repo->findByVersion($version);
        self::assertCount(2, $codemods);
        foreach ($codemods as $codemod) {
            self::assertSame('1.0.0', $codemod->version->raw);
        }
    }

    public function testParseCodemodKindFromFilename(): void
    {
        self::assertSame('code', CodemodRepository::parseCodemodKindFromFilename('test.code.js'));
        self::assertSame('json', CodemodRepository::parseCodemodKindFromFilename('test.json.js'));
    }

    public function testParseCodemodKindFromFilenameThrowsForInvalidFilename(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        CodemodRepository::parseCodemodKindFromFilename('test.js');
    }

    public function testParseCodemodKindFromFilenameThrowsWithoutKind(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        CodemodRepository::parseCodemodKindFromFilename('invalid.file');
    }

    public function testVersionExists(): void
    {
        $repo = new CodemodRepository($this->cwd);

        self::assertFalse($repo->versionExists(new SemVer('1.0.0')));
        $repo->refresh();
        self::assertTrue($repo->versionExists(new SemVer('1.0.0')));
        self::assertFalse($repo->versionExists(new SemVer('3.0.0')));
    }

    public function testFindByRangeAndUid(): void
    {
        $repo = (new CodemodRepository($this->cwd))->refresh();

        $groups = $repo->find(['range' => new Range('>1.0.0 <=2.0.0')]);
        self::assertCount(1, $groups);
        self::assertSame('2.0.0', $groups[0]['version']->raw);

        self::assertTrue($repo->has('1.0.0-a-code'));
        self::assertFalse($repo->has('1.0.0-c-code'));
        self::assertCount(2, $repo->findAll());
    }

    public function testCodemodRepositoryFactoryDefaultsToTheBundledCodemods(): void
    {
        $repo = CodemodRepository::codemodRepositoryFactory();

        self::assertSame(Constants::internalCodemodsDirectory(), $repo->cwd);
        self::assertTrue($repo->refresh()->has('5.0.0-entity-service-document-service-code'));
    }
}

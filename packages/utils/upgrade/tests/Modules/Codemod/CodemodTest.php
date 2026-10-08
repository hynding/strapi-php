<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Codemod;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Codemod\Constants;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/codemod/__tests__/codemod.test.ts. */
final class CodemodTest extends TestCase
{
    private SemVer $version;

    private string $filename;

    private Codemod $codemod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->version = new SemVer('1.0.0');
        $this->filename = 'example.' . Constants::CODEMOD_CODE_SUFFIX . '.' . Constants::CODEMOD_EXTENSION;
        $this->codemod = new Codemod(['kind' => 'code', 'version' => $this->version, 'baseDirectory' => '/base/directory', 'filename' => $this->filename]);
    }

    public function testConstructorSetsPropertiesCorrectly(): void
    {
        self::assertSame('code', $this->codemod->kind);
        self::assertSame($this->version, $this->codemod->version);
        self::assertSame('/base/directory', $this->codemod->baseDirectory);
        self::assertSame($this->filename, $this->codemod->filename);
        self::assertSame('/base/directory/1.0.0/' . $this->filename, $this->codemod->path);
    }

    public function testFormatReturnsTheCorrectlyFormattedFilename(): void
    {
        self::assertSame('example', $this->codemod->format());
    }

    public function testCodemodFactory(): void
    {
        $codemod = Codemod::codemodFactory(['kind' => 'code', 'version' => $this->version, 'baseDirectory' => '/base/directory', 'filename' => $this->filename]);

        self::assertInstanceOf(Codemod::class, $codemod);
        self::assertSame('code', $codemod->kind);
        self::assertSame($this->version, $codemod->version);
    }

    public function testUidAndFormatOptions(): void
    {
        $codemod = Codemod::codemodFactory(['kind' => 'json', 'version' => new SemVer('5.0.0'), 'baseDirectory' => '/x', 'filename' => 'dependency-upgrade-react.json.php']);

        self::assertSame('5.0.0-dependency-upgrade-react-json', $codemod->uid);
        self::assertSame('dependency upgrade react', $codemod->format());
        self::assertSame('dependency-upgrade-react.json.php', $codemod->format(['stripExtension' => false, 'stripKind' => false, 'stripHyphens' => false]));
    }
}

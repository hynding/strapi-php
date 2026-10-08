<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Json;

use Strapi\Upgrade\Modules\Json\File;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/json/__tests__/file.test.ts. */
final class FileTest extends TestCase
{
    private const OBJ = ['foo' => 'bar', 'bar' => ['baz' => 42]];

    public function testReadingAJSONFileReturnsAValidJSONObject(): void
    {
        $cwd = $this->volume(['a.json' => json_encode(self::OBJ)]);

        self::assertSame(self::OBJ, File::readJSON("{$cwd}/a.json"));
    }

    public function testReadingAJSONFileWhichDoesntExist(): void
    {
        $this->expectException(\RuntimeException::class);

        File::readJSON('unknown-path.json');
    }

    public function testSaveJSONCanUpdateAnExistingFile(): void
    {
        $cwd = $this->volume(['a.json' => json_encode(self::OBJ)]);
        $updated = [...self::OBJ, 'other' => 'foobar'];

        $current = File::readJSON("{$cwd}/a.json");
        File::saveJSON("{$cwd}/a.json", $updated);

        self::assertSame($updated, File::readJSON("{$cwd}/a.json"));
        self::assertNotSame($updated, $current);
    }

    public function testSaveJSONCanCreateANewFile(): void
    {
        $cwd = $this->volume();

        File::saveJSON("{$cwd}/a.json", self::OBJ);

        self::assertSame(self::OBJ, File::readJSON("{$cwd}/a.json"));
        // JSON.stringify(json, null, 2) + "\n"
        self::assertSame("{\n  \"foo\": \"bar\",\n  \"bar\": {\n    \"baz\": 42\n  }\n}\n", file_get_contents("{$cwd}/a.json"));
    }

    public function testRoundTripKeepsEmptyObjectsAndIndentation(): void
    {
        $composer = "{\n    \"require\": {},\n    \"list\": [],\n    \"url\": \"https://x/y\",\n    \"n\": 1.0\n}\n";
        $cwd = $this->volume(['composer.json' => $composer]);

        File::saveJSON("{$cwd}/composer.json", File::readJSON("{$cwd}/composer.json"));

        self::assertSame($composer, file_get_contents("{$cwd}/composer.json"));
    }
}

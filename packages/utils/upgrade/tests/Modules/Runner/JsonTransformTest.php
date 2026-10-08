<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Runner;

use Strapi\Upgrade\Modules\Runner\Json\Transform;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/runner/__tests__/json-transform.test.ts. */
final class JsonTransformTest extends TestCase
{
    private string $cwd;

    /** @var list<string> */
    private array $paths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cwd = $this->volume([
            'no-return.json.php' => '<?php return static function (array $file): ?array { return null; };',
            'no-update.json.php' => '<?php return static fn (array $file): array => $file["json"];',
            'update.json.php' => '<?php return static fn (array $file): array => ["unknown" => "object"];',
            'throw.json.php' => '<?php return static function (): array { throw new RuntimeException(); };',
            'not-a-function.json.php' => '<?php return 42;',
            'a.json' => '{ "foo": "bar", "nested": { "bar": 42 } }',
            'b.json' => '{ "foo": "baz", "nb": 42 }',
        ]);
        $this->paths = ["{$this->cwd}/a.json", "{$this->cwd}/b.json"];
    }

    /** @return array{int, int, int, int} */
    private function transform(string $codemod, bool $dry = true): array
    {
        $report = Transform::transformJSON("{$this->cwd}/{$codemod}", $this->paths, ['dry' => $dry, 'cwd' => $this->cwd]);

        return [$report['ok'], $report['nochange'], $report['skip'], $report['error']];
    }

    public function testCodemodsThatReturnNothingAreErrored(): void
    {
        self::assertSame([0, 0, 0, 2], $this->transform('no-return.json.php'));
    }

    public function testCodemodsThatThrowAreErrored(): void
    {
        self::assertSame([0, 0, 0, 2], $this->transform('throw.json.php'));
    }

    public function testLeavingTheJsonObjectAsIsIsUnchanged(): void
    {
        self::assertSame([0, 2, 0, 0], $this->transform('no-update.json.php'));
    }

    public function testMakingUpdatesIsOk(): void
    {
        self::assertSame([2, 0, 0, 0], $this->transform('update.json.php'));
        self::assertSame(['foo' => 'bar', 'nested' => ['bar' => 42]], self::readJson("{$this->cwd}/a.json"));
    }

    public function testMakingUpdatesInNonDryModeChangesTheFiles(): void
    {
        $this->transform('update.json.php', false);

        foreach ($this->paths as $path) {
            self::assertSame(['unknown' => 'object'], self::readJson($path));
        }
    }

    public function testTheCodemodMustBeAFunction(): void
    {
        $this->expectExceptionMessage('Codemod must be a function. Found int');

        $this->transform('not-a-function.json.php');
    }
}

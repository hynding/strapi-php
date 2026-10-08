<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Runner;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Runner\Json\JSONRunner;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/runner/__tests__/json.test.ts. */
final class JsonTest extends TestCase
{
    public function testValidAndRun(): void
    {
        $cwd = $this->volume([
            'codemods' => ['1.2.3' => ['foo.json.php' => '<?php return static fn (array $file, array $params): array => [...$file["json"], "touched" => true];', 'foo.code.php' => '<?php return null;']],
            'a.json' => '{"a": 1}',
            'b.json' => '{"b": 2}',
        ]);
        $json = Codemod::codemodFactory(['kind' => 'json', 'baseDirectory' => "{$cwd}/codemods", 'filename' => 'foo.json.php', 'version' => new SemVer('1.2.3')]);
        $code = Codemod::codemodFactory(['kind' => 'code', 'baseDirectory' => "{$cwd}/codemods", 'filename' => 'foo.code.php', 'version' => new SemVer('1.2.3')]);

        $runner = JSONRunner::jsonRunnerFactory(["{$cwd}/a.json", "{$cwd}/b.json"], ['dry' => true, 'cwd' => $cwd]);

        self::assertTrue($runner->valid($json));
        self::assertFalse($runner->valid($code));
        self::assertSame(2, $runner->run($json)['ok']);

        $this->expectExceptionMessage('Invalid codemod provided to the runner: foo.code.php');
        $runner->run($code);
    }
}

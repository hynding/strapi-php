<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\FileScanner;

use PHPUnit\Framework\Attributes\DataProvider;
use Strapi\Upgrade\Modules\FileScanner\FileScanner;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/file-scanner/__tests__/scanner.test.ts. */
final class ScannerTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        parent::setUp();
        $file = 'console.log("a.ts");';
        $this->cwd = $this->volume(['a.ts' => $file, 'b.mjs' => $file, 'c.js' => $file, 'd.js' => $file, 'e.json' => $file, '.gitignore' => $file]);
    }

    public function testScanReturnsAnEmptyListForEmptyPatterns(): void
    {
        self::assertSame([], FileScanner::fileScannerFactory($this->cwd)->scan([]));
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function patterns(): iterable
    {
        yield '*.js' => [['*.js'], ['c.js', 'd.js']];
        yield '*.ts' => [['*.ts'], ['a.ts']];
        yield '*.{js,json}' => [['*.{js,json}'], ['c.js', 'd.js', 'e.json']];
        yield 'with .gitignore' => [['*.{js,json}', '.gitignore'], ['.gitignore', 'c.js', 'd.js', 'e.json']];
    }

    /**
     * @param list<string> $patterns
     * @param list<string> $expected
     */
    #[DataProvider('patterns')]
    public function testScanReturnsAListOfFilesMatching(array $patterns, array $expected): void
    {
        $files = FileScanner::fileScannerFactory($this->cwd)->scan($patterns);

        self::assertSame(array_map(fn (string $f): string => "{$this->cwd}/{$f}", $expected), $files);
    }

    public function testNestedPatternsAndNegations(): void
    {
        $cwd = $this->volume([
            'src' => ['a.php' => '', 'admin' => ['app.tsx' => ''], 'node_modules' => ['x.js' => ''], '.hidden' => ['y.js' => '']],
            'config' => ['plugins.php' => '', 'vendor' => ['z.php' => '']],
            'vendor' => ['w.php' => ''],
        ]);

        $files = FileScanner::fileScannerFactory($cwd)->scan(['./{src,config}/**/*.{php,tsx,js}', '!./**/node_modules/**/*', '!./**/vendor/**/*']);

        self::assertSame(["{$cwd}/config/plugins.php", "{$cwd}/src/a.php", "{$cwd}/src/admin/app.tsx"], $files);
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests\Plops\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Generators\Plops\Utils\ExtendPluginIndexFiles;

/**
 * Port of src/plops/utils/__tests__/extend-plugin-index.files.test.ts.
 *
 * Upstream's cases are about JS module shapes (ESM `export default`, CJS `module.exports`,
 * imports/requires). The PHP index files return arrays, so the same scenarios — empty file,
 * empty export, existing entries, routes array, no duplicates, fallback — run on PHP sources.
 */
final class ExtendPluginIndexFilesTest extends TestCase
{
    private static function assertParses(string $source): void
    {
        self::assertNotSame([], token_get_all($source, TOKEN_PARSE));
    }

    // Empty file handling

    public function testShouldHandleCompletelyEmptyFileWithContentType(): void
    {
        $result = ExtendPluginIndexFiles::appendToFile('', ['singularName' => 'user', 'type' => 'content-type']);

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'user' => [
                    'schema' => json_decode((string) file_get_contents(__DIR__ . '/user/schema.json'), true, flags: JSON_THROW_ON_ERROR),
                ],
            ];

            PHP, $result);
        self::assertParses($result);
    }

    public function testShouldHandleCompletelyEmptyFileWithIndex(): void
    {
        $result = ExtendPluginIndexFiles::appendToFile('', ['singularName' => 'product', 'type' => 'index']);

        self::assertStringContainsString("'product' => require __DIR__ . '/product.php',", $result);
        self::assertStringContainsString('return [', $result);
        self::assertParses($result);
    }

    public function testShouldHandleWhitespaceOnlyFile(): void
    {
        $result = ExtendPluginIndexFiles::appendToFile("   \n\n  ", ['singularName' => 'category', 'type' => 'routes']);

        self::assertStringContainsString('return static fn (Strapi $strapi): array => [', $result);
        self::assertStringContainsString("'type' => 'content-api',", $result);
        self::assertStringContainsString("...(require __DIR__ . '/category.php')['routes'],", $result);
        self::assertParses($result);
    }

    // Index files (the plop plugin.index template)

    public function testShouldAddContentTypeToAnEmptyIndex(): void
    {
        $template = "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n";
        $result = ExtendPluginIndexFiles::appendToFile($template, ['singularName' => 'article', 'type' => 'content-type']);

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'article' => [
                    'schema' => json_decode((string) file_get_contents(__DIR__ . '/article/schema.json'), true, flags: JSON_THROW_ON_ERROR),
                ],
            ];

            PHP, $result);
    }

    public function testShouldAddIndexToAnExistingExportWithOtherProperties(): void
    {
        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'existing' => require __DIR__ . '/existing.php'
            ];

            PHP;
        $result = ExtendPluginIndexFiles::appendToFile($template, ['singularName' => 'newItem', 'type' => 'index']);

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'existing' => require __DIR__ . '/existing.php',
                'newItem' => require __DIR__ . '/newItem.php',
            ];

            PHP, $result);
        self::assertParses($result);
    }

    public function testShouldAddRoutesToAnEmptyRouter(): void
    {
        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            use Strapi\Core\Strapi;

            return static fn (Strapi $strapi): array => [
                'type' => 'content-api',
                'routes' => [],
            ];

            PHP;
        $result = ExtendPluginIndexFiles::appendToFile($template, ['singularName' => 'order', 'type' => 'routes']);

        self::assertSame(<<<'PHP'
            <?php

            declare(strict_types=1);

            use Strapi\Core\Strapi;

            return static fn (Strapi $strapi): array => [
                'type' => 'content-api',
                'routes' => [
                    ...(require __DIR__ . '/order.php')['routes'],
                ],
            ];

            PHP, $result);
    }

    public function testShouldAddRoutesToAnExistingRoutesArray(): void
    {
        $template = <<<'PHP'
            <?php

            return static fn (Strapi $strapi): array => [
                'type' => 'content-api',
                'routes' => [
                    ...(require __DIR__ . '/existing.php')['routes'],
                    [
                        'method' => 'GET',
                        'path' => '/',
                        'handler' => 'controller.index', // trailing comment
                    ]
                    // a comment after the last route
                ],
            ];
            PHP;
        $result = ExtendPluginIndexFiles::appendToFile($template, ['singularName' => 'newRoute', 'type' => 'routes', 'router' => 'core']);

        self::assertStringContainsString("...(require __DIR__ . '/existing.php')['routes'],", $result);
        self::assertStringContainsString("        ],\n        // a comment after the last route\n        ...(require __DIR__ . '/newRoute.php')->routes(\$strapi),\n    ],", $result);
        self::assertParses($result);
    }

    public function testShouldNotDuplicateExistingEntries(): void
    {
        $template = <<<'PHP'
            <?php

            return [
                'user' => require __DIR__ . '/user.php',
            ];
            PHP;

        self::assertSame($template, ExtendPluginIndexFiles::appendToFile($template, ['singularName' => 'user', 'type' => 'index']));

        $routes = ExtendPluginIndexFiles::appendToFile('', ['singularName' => 'user', 'type' => 'routes']);
        self::assertSame($routes, ExtendPluginIndexFiles::appendToFile($routes, ['singularName' => 'user', 'type' => 'routes']));
    }

    public function testDoubleQuotedKeysCountAsExisting(): void
    {
        $template = "<?php\n\nreturn [\n    \"user\" => require __DIR__ . '/user.php',\n];\n";

        self::assertSame($template, ExtendPluginIndexFiles::appendToFile($template, ['singularName' => 'user', 'type' => 'index']));
    }

    public function testNestedKeysAreNotTopLevelEntries(): void
    {
        $template = "<?php\n\nreturn [\n    'other' => ['user' => true],\n];\n";
        $result = ExtendPluginIndexFiles::appendToFile($template, ['singularName' => 'user', 'type' => 'index']);

        self::assertStringContainsString("'user' => require __DIR__ . '/user.php',", $result);
        self::assertParses($result);
    }

    public function testKebabCaseNamesAreQuotedKeys(): void
    {
        $result = ExtendPluginIndexFiles::appendToFile("<?php\n\nreturn [];\n", ['singularName' => 'my-item', 'type' => 'index']);

        self::assertStringContainsString("'my-item' => require __DIR__ . '/my-item.php',", $result);
    }

    // Fallbacks (upstream replaces an export it cannot extend)

    public function testAFileWithoutAReturnedArrayIsReplaced(): void
    {
        $result = ExtendPluginIndexFiles::appendToFile("<?php\n\nreturn \$routes;\n", ['singularName' => 'item', 'type' => 'index']);

        self::assertStringContainsString("'item' => require __DIR__ . '/item.php',", $result);
        self::assertParses($result);
    }

    public function testARouterWithoutARoutesArrayIsReplaced(): void
    {
        $result = ExtendPluginIndexFiles::appendToFile("<?php\n\nreturn [];\n", ['singularName' => 'item', 'type' => 'routes']);

        self::assertStringContainsString("'type' => 'content-api',", $result);
        self::assertStringContainsString("...(require __DIR__ . '/item.php')['routes'],", $result);
    }

    public function testReturnsInsideClosuresAreIgnored(): void
    {
        $template = <<<'PHP'
            <?php

            $helper = static function (): array {
                return ['nope' => true];
            };

            return [
                'a' => require __DIR__ . '/a.php',
            ];
            PHP;
        $result = ExtendPluginIndexFiles::appendToFile($template, ['singularName' => 'b', 'type' => 'index']);

        self::assertStringContainsString("return ['nope' => true];", $result);
        self::assertStringContainsString("    'a' => require __DIR__ . '/a.php',\n    'b' => require __DIR__ . '/b.php',\n];", $result);
    }

    public function testRequiresASingularName(): void
    {
        $this->expectExceptionMessage('Invalid config: singularName and type are required');

        ExtendPluginIndexFiles::appendToFile('', ['singularName' => '', 'type' => 'index']);
    }
}

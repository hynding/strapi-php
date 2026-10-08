<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Utils\Pluralize;

/** Not an upstream test: `pluralize` 8.0.0 outputs, as used for component collection names. */
final class PluralizeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function words(): iterable
    {
        foreach ([
            ['somecomponent', 'somecomponents'],
            ['compo', 'compos'],
            ['category', 'categories'],
            ['Category', 'Categories'],
            ['box', 'boxes'],
            ['person', 'people'],
            ['media', 'media'],
            ['news', 'news'],
            ['hero section', 'hero sections'],
            ['Address', 'Addresses'],
            ['quiz', 'quizzes'],
            ['knife', 'knives'],
            ['status', 'statuses'],
            ['FOO', 'FOOS'],
            ['sheep', 'sheep'],
            ['schema', 'schemata'],
            ['', ''],
        ] as [$singular, $plural]) {
            yield "{$singular} => {$plural}" => [$singular, $plural];
        }
    }

    #[DataProvider('words')]
    public function testPlural(string $singular, string $plural): void
    {
        self::assertSame($plural, Pluralize::plural($singular));
    }
}

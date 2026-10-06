<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Utils;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Database\Utils\LodashWords;

/** Expectations generated with lodash 4.17 `_.words` / `_.snakeCase`. */
final class LodashWordsTest extends TestCase
{
    /** @return iterable<array{string, list<string>, string}> */
    public static function cases(): iterable
    {
        yield ['components_default_l807d8', ['components', 'default', 'l', '807', 'd', '8'], 'components_default_l_807_d_8'];
        yield ['componen56bca', ['componen', '56', 'bca'], 'componen_56_bca'];
        yield ['abc123def', ['abc', '123', 'def'], 'abc_123_def'];
        yield ['foo2bar', ['foo', '2', 'bar'], 'foo_2_bar'];
        yield ['2abc', ['2', 'abc'], '2_abc'];
        yield ['Foo1Bar', ['Foo', '1', 'Bar'], 'foo_1_bar'];
        yield ['1st place', ['1st', 'place'], '1st_place'];
        yield ['fooBar2', ['foo', 'Bar', '2'], 'foo_bar_2'];
        yield ['XMLHttpRequest', ['XML', 'Http', 'Request'], 'xml_http_request'];
        yield ['ABCdef', ['AB', 'Cdef'], 'ab_cdef'];
        yield ['foo__bar', ['foo', 'bar'], 'foo_bar'];
        yield ['Über', ['Über'], 'über'];
        yield ['hello123World', ['hello', '123', 'World'], 'hello_123_world'];
        yield ['v1.2.3', ['v', '1', '2', '3'], 'v_1_2_3'];
        yield ['foo-bar-baz', ['foo', 'bar', 'baz'], 'foo_bar_baz'];
        yield ['FOO_BAR', ['FOO', 'BAR'], 'foo_bar'];
        yield ['HTML5Parser', ['HTML', '5', 'Parser'], 'html_5_parser'];
        yield ["it's done", ['its', 'done'], 'its_done'];
        yield ['2nd 3RD 11th', ['2nd', '3RD', '11', 'th'], '2nd_3rd_11_th'];
        yield ['enable 24H format', ['enable', '24', 'H', 'format'], 'enable_24_h_format'];
        yield ['tooLegit2Quit', ['too', 'Legit', '2', 'Quit'], 'too_legit_2_quit'];
        yield ['createdById', ['created', 'By', 'Id'], 'created_by_id'];
        yield ['document_id', ['document', 'id'], 'document_id'];
        yield ['admin::user', ['admin', 'user'], 'admin_user'];
        yield ['complexeshasandbelongstomanycomplexes', ['complexeshasandbelongstomanycomplexes'], 'complexeshasandbelongstomanycomplexes'];
    }

    #[DataProvider('cases')]
    public function testWordsAndSnakeCase(string $input, array $words, string $snake): void
    {
        self::assertSame($words, LodashWords::words($input));
        self::assertSame($snake, LodashWords::snakeCase($input));
    }

    public function testCamelAndKebab(): void
    {
        self::assertSame('componentDefaultLongComponentName', LodashWords::camelCase('component_default.long-component-name'));
        self::assertSame('ComponentDefaultLongComponentName', LodashWords::upperFirst(LodashWords::camelCase('component_default.long-component-name')));
        self::assertSame('relation-locale', LodashWords::kebabCase('relationLocale'));
    }
}

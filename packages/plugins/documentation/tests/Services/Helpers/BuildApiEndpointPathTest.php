<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Services\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Documentation\Services\Helpers\BuildApiEndpointPath;

/**
 * PHP-port addition: the inline port of path-to-regexp 8.4.2's `parse()`, against outputs of the
 * npm package itself.
 */
final class BuildApiEndpointPathTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        yield 'param' => ['/articles/:id', '[{"type":"text","value":"/articles/"},{"type":"param","name":"id"}]'];
        yield 'wildcard' => ['/a/:id/b/*rest', '[{"type":"text","value":"/a/"},{"type":"param","name":"id"},{"type":"text","value":"/b/"},{"type":"wildcard","name":"rest"}]'];
        yield 'group' => ['/x/{:opt}', '[{"type":"text","value":"/x/"},{"type":"group","tokens":[{"type":"param","name":"opt"}]}]'];
        yield 'regex param' => ['/v:major(\d+)', 'ERR Unexpected ( at index 8: /v:major(\d+); visit https://git.new/pathToRegexpError for info'];
        yield 'quoted' => ['/q/:"quoted name"', '[{"type":"text","value":"/q/"},{"type":"param","name":"quoted name"}]'];
        yield 'escaped' => ['/esc\:not', '[{"type":"text","value":"/esc:not"}]'];
        yield 'missing name' => ['/:', 'ERR Missing parameter name at index 2: /:; visit https://git.new/pathToRegexpError for info'];
        yield 'dot' => ['/a.:b', '[{"type":"text","value":"/a."},{"type":"param","name":"b"}]'];
    }

    #[DataProvider('cases')]
    public function testParseMatchesPathToRegexp(string $path, string $expected): void
    {
        try {
            $actual = json_encode(BuildApiEndpointPath::parse($path), JSON_UNESCAPED_SLASHES);
        } catch (\TypeError $e) {
            $actual = 'ERR ' . $e->getMessage();
        }

        self::assertSame($expected, $actual);
    }
}

<?php

declare(strict_types=1);

namespace StrapiPlugin\Announcements\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Core;
use Strapi\Core\Registries\ActionMap;
use Strapi\Core\Strapi;
use StrapiPlugin\Announcements\Generated\RouteHandlers;

/**
 * Runs conformance/fixtures/*.json against strapi-php with this plugin enabled. The Node twin is
 * conformance/run-strapi.mjs; both apply the same rules (see the header there):
 * seed through the document service, `$keys` for exact key sets, subset matching otherwise.
 */
final class ConformanceTest extends TestCase
{
    /** Fixture `requires` this backend can satisfy. The admin API is not ported yet. */
    private const array SUPPORTS = [];

    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['LOG_LEVEL'] = 'error';

        self::$strapi = Core::createStrapi(['appDir' => __DIR__ . '/app'])->load();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('Strapi is not booted');
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/conformance/fixtures/*.json') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function testFixture(string $file): void
    {
        $fixture = json_decode((string) file_get_contents($file), false, flags: JSON_THROW_ON_ERROR);
        \assert($fixture instanceof \stdClass);

        $missing = array_diff($fixture->requires ?? [], self::SUPPORTS);
        if ($missing !== []) {
            self::markTestSkipped('needs ' . implode(', ', $missing) . ' (not ported to strapi-php yet)');
        }

        $strapi = self::strapi();
        $seed = (array) ($fixture->seed ?? new \stdClass());
        foreach (array_keys($seed) as $uid) {
            $strapi->db()->query($uid)->deleteMany(['where' => []]);
        }
        foreach ($seed as $uid => $rows) {
            foreach ($rows as $row) {
                $strapi->documents($uid)->create(['data' => json_decode((string) json_encode($row), true)]);
            }
        }

        $factory = new Psr17Factory();
        $problems = [];
        foreach ($fixture->steps as $i => $step) {
            $request = $factory->createServerRequest($step->request->method, 'http://localhost:1337' . $step->request->path)
                ->withHeader('Accept', 'application/json');
            parse_str((string) parse_url($step->request->path, PHP_URL_QUERY), $query);
            $request = $request->withQueryParams($query);
            if (isset($step->request->body)) {
                $request = $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($factory->createStream((string) json_encode($step->request->body)));
            }

            $response = $strapi->server()->handle($request);
            $text = (string) $response->getBody();
            $body = $text === '' ? null : json_decode($text, false, flags: JSON_THROW_ON_ERROR);

            $label = sprintf('step %d (%s %s)', $i + 1, $step->request->method, $step->request->path);
            if ($response->getStatusCode() !== $step->expect->status) {
                $problems[] = "{$label}: expected status {$step->expect->status}, got {$response->getStatusCode()} " . substr($text, 0, 300);
            }
            if (property_exists($step->expect, 'body')) {
                foreach (self::match($step->expect->body, $body) as $problem) {
                    $problems[] = "{$label}: {$problem}";
                }
            }
        }

        self::assertSame([], $problems, $fixture->description);
    }

    /** The PHP side of the generated controller contract (TS checks it at compile time with `satisfies`). */
    public function testEveryRouteHandlerIsImplemented(): void
    {
        $plugin = self::strapi()->plugin('announcements');
        foreach (RouteHandlers::CONTROLLERS as $controller => $actions) {
            foreach ($actions as $action) {
                self::assertTrue(ActionMap::hasAction($plugin->controller($controller), $action), "{$controller}.{$action} is routed but not implemented");
            }
        }
    }

    /** @return list<string> */
    private static function match(mixed $expected, mixed $actual, string $path = 'body'): array
    {
        if ($expected instanceof \stdClass) {
            if (!$actual instanceof \stdClass) {
                return ["{$path}: expected an object, got " . json_encode($actual)];
            }
            $problems = [];
            $expectedKeys = get_object_vars($expected);
            if (isset($expectedKeys['$keys'])) {
                $want = $expectedKeys['$keys'];
                $got = array_keys(get_object_vars($actual));
                sort($want);
                sort($got);
                if ($want !== $got) {
                    $problems[] = "{$path}: expected keys " . implode(',', $want) . ', got ' . implode(',', $got);
                }
                unset($expectedKeys['$keys']);
            }
            foreach ($expectedKeys as $key => $value) {
                if (!property_exists($actual, (string) $key)) {
                    $problems[] = "{$path}.{$key}: missing";
                } else {
                    array_push($problems, ...self::match($value, $actual->{$key}, "{$path}.{$key}"));
                }
            }

            return $problems;
        }
        if (is_array($expected)) {
            if (!is_array($actual)) {
                return ["{$path}: expected an array, got " . json_encode($actual)];
            }
            if (count($expected) !== count($actual)) {
                return ["{$path}: expected " . count($expected) . ' items, got ' . count($actual)];
            }
            $problems = [];
            foreach ($expected as $i => $item) {
                array_push($problems, ...self::match($item, $actual[$i], "{$path}[{$i}]"));
            }

            return $problems;
        }

        return $expected === $actual ? [] : ["{$path}: expected " . json_encode($expected) . ', got ' . json_encode($actual)];
    }
}

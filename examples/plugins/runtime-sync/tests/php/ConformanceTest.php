<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Core;
use Strapi\Core\Strapi;

/**
 * Runs conformance/fixtures/*.json against strapi-php with this plugin enabled. The Node twin is
 * conformance/run-strapi.mjs; both apply the same rules (see the header there): `$keys` for exact
 * key sets, subset matching otherwise, `"auth": "admin"` sends a super-admin token.
 */
final class ConformanceTest extends TestCase
{
    /** Fixture `requires` this backend can satisfy. */
    private const array SUPPORTS = ['admin'];

    private static ?Strapi $strapi = null;

    private static ?string $adminToken = null;

    private static string $db = '';

    public static function setUpBeforeClass(): void
    {
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['LOG_LEVEL'] = 'error';

        // a file database a Node Strapi already ran the .js twin of the app's migration on (see ServerTest)
        self::$db = sys_get_temp_dir() . '/runtime-sync-conformance-' . uniqid() . '.db';
        putenv('RUNTIME_SYNC_TEST_DB=' . self::$db);
        $_ENV['RUNTIME_SYNC_TEST_DB'] = self::$db;
        $pdo = new \PDO('sqlite:' . self::$db);
        $pdo->exec('CREATE TABLE strapi_migrations (id integer primary key autoincrement, name varchar(255), time datetime)');
        $pdo->exec("INSERT INTO strapi_migrations (name, time) VALUES ('2024.01.01T00.00.00.noop.js', '2024-01-01 00:00:00')");
        unset($pdo);

        self::$strapi = Core::createStrapi(['appDir' => __DIR__ . '/app'])->load();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
        self::$adminToken = null;
        putenv('RUNTIME_SYNC_TEST_DB');
        unset($_ENV['RUNTIME_SYNC_TEST_DB']);
        @unlink(self::$db);
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
            self::markTestSkipped('needs ' . implode(', ', $missing));
        }

        $strapi = self::strapi();
        $factory = new Psr17Factory();
        $problems = [];
        foreach ($fixture->steps as $i => $step) {
            $request = $factory->createServerRequest($step->request->method, 'http://localhost:1337' . $step->request->path)
                ->withHeader('Accept', 'application/json');
            if (($step->auth ?? null) === 'admin') {
                $request = $request->withHeader('Authorization', 'Bearer ' . self::adminToken());
            }
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

    /** Registers the first admin (as conformance/run-strapi.mjs does) and keeps its token. */
    private static function adminToken(): string
    {
        if (self::$adminToken !== null) {
            return self::$adminToken;
        }
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'http://localhost:1337/admin/register-admin')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream((string) json_encode(['email' => 'admin@example.com', 'firstname' => 'Ad', 'lastname' => 'Min', 'password' => 'Conformance1!'])));
        $body = json_decode((string) self::strapi()->server()->handle($request)->getBody(), true);
        $token = $body['data']['accessToken'] ?? $body['data']['token'] ?? null;
        if (!is_string($token)) {
            throw new \RuntimeException('could not register an admin: ' . json_encode($body));
        }

        return self::$adminToken = $token;
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

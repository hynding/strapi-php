<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Graphql\Mutations\Auth;

require_once __DIR__ . '/../../../BootedApp.php';

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth\ChangePassword;
use Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth\ForgotPassword;
use Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth\Login;
use Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth\RateLimit;
use Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth\Register;
use Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth\ResetPassword;
use Strapi\Plugin\UsersPermissions\Tests\BootedApp;

/**
 * Port of server/src/graphql/mutations/auth/__tests__/rate-limit.test.js. Upstream mocks
 * `strapi`; here the booted app's `plugin::users-permissions.rateLimit` middleware and `auth`
 * controller are replaced by recorders.
 */
final class RateLimitTest extends TestCase
{
    private const string RATE_LIMIT_UID = 'plugin::users-permissions.rateLimit';

    private static Strapi $strapi;

    private static mixed $originalMiddleware;

    private static ?object $originalController;

    /** @var list<string> event log: `factory`, `rateLimit:<path>`, `controller:<action>` */
    private array $events = [];

    /** @var (callable(Context, callable): mixed)|null */
    private $rateLimitImplementation = null;

    /** @var array<string, callable(Context): mixed> */
    private array $controllerImplementations = [];

    /** @var list<array{action: string, ctx: Context}> */
    private array $controllerCalls = [];

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedApp::boot();
        self::$originalMiddleware = self::$strapi->get('middlewares')->get(self::RATE_LIMIT_UID);
        self::$originalController = self::$strapi->get('controllers')->get('plugin::users-permissions.auth');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_callable(self::$originalMiddleware)) {
            self::$strapi->get('middlewares')->set(self::RATE_LIMIT_UID, self::$originalMiddleware);
        }
        self::$strapi->get('controllers')->set('plugin::users-permissions.auth', self::$originalController);
    }

    protected function setUp(): void
    {
        $test = $this;
        self::$strapi->get('middlewares')->set(self::RATE_LIMIT_UID, static function (array $config, Strapi $strapi) use ($test): \Closure {
            $test->events[] = 'factory';
            $test->assertSame([], $config);

            return static function (Context $ctx, callable $next) use ($test): mixed {
                $test->events[] = 'rateLimit:' . $ctx->path();

                return $test->rateLimitImplementation !== null ? ($test->rateLimitImplementation)($ctx, $next) : $next();
            };
        });

        $controller = new class ($test) {
            public function __construct(private readonly RateLimitTest $test)
            {
            }

            /** @param array<int, mixed> $args */
            public function __call(string $action, array $args): mixed
            {
                $ctx = $args[0];
                \assert($ctx instanceof Context);

                return $this->test->runController($action, $ctx);
            }
        };
        self::$strapi->get('controllers')->set('plugin::users-permissions.auth', $controller);
    }

    /** @internal called by the recording controller */
    public function runController(string $action, Context $ctx): mixed
    {
        $this->events[] = "controller:{$action}";
        $this->controllerCalls[] = ['action' => $action, 'ctx' => $ctx];

        $implementation = $this->controllerImplementations[$action] ?? null;
        if ($implementation !== null) {
            return $implementation($ctx);
        }

        $ctx->setBody($action === 'forgotPassword' ? ['ok' => true] : ['jwt' => 'jwt-token', 'user' => ['id' => 1]]);

        return null;
    }

    private static function createKoaContext(): Context
    {
        $ctx = new Context(new ServerRequest('POST', 'http://localhost/graphql', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.1']));
        $ctx->setRequestBody([]);

        return $ctx;
    }

    /** @return array{nexus: Nexus, strapi: Strapi} */
    private static function context(): array
    {
        return ['nexus' => new Nexus(), 'strapi' => self::$strapi];
    }

    /** @param array<string, mixed> $config */
    private static function resolve(array $config, mixed ...$args): mixed
    {
        $resolve = $config['resolve'];
        \assert(is_callable($resolve));

        return $resolve(...$args);
    }

    /** @return iterable<array{string, string, string}> */
    public static function prefixes(): iterable
    {
        yield ['/api', '/auth/forgot-password', '/api/auth/forgot-password'];
        yield ['/custom/', '/auth/local', '/custom/auth/local'];
        yield ['custom', 'auth/reset-password', '/custom/auth/reset-password'];
        yield ['/', '/auth/change-password', '/auth/change-password'];
    }

    #[DataProvider('prefixes')]
    public function testUsesTheRestPrefixToBuildTheRoutePath(string $restPrefix, string $routeSuffix, string $expectedPath): void
    {
        $previous = BootedApp::setConfig(self::$strapi, 'api.rest.prefix', $restPrefix);
        try {
            self::assertSame($expectedPath, RateLimit::getRateLimitPath(self::$strapi, $routeSuffix));
        } finally {
            self::$strapi->config()->set('api.rest.prefix', $previous);
        }
    }

    public function testRunsUsersPermissionsRateLimitBeforeForgotPasswordController(): void
    {
        $mutation = ForgotPassword::create(self::context());
        $koaContext = self::createKoaContext();

        self::resolve($mutation, null, ['email' => 'victim@example.com'], ['koaContext' => $koaContext]);

        self::assertSame(['factory', 'rateLimit:/api/auth/forgot-password', 'controller:forgotPassword'], $this->events);
        self::assertSame($koaContext, $this->controllerCalls[0]['ctx']);
    }

    public function testReusesTheForgotPasswordRateLimitMiddlewareAcrossResolverCalls(): void
    {
        $mutation = ForgotPassword::create(self::context());

        self::resolve($mutation, null, ['email' => 'victim@example.com'], ['koaContext' => self::createKoaContext()]);
        self::resolve($mutation, null, ['email' => 'victim@example.com'], ['koaContext' => self::createKoaContext()]);

        self::assertCount(1, array_keys($this->events, 'factory', true));
        self::assertCount(2, array_keys($this->events, 'rateLimit:/api/auth/forgot-password', true));
    }

    public function testRunsUsersPermissionsRateLimitBeforeResetPasswordController(): void
    {
        $mutation = ResetPassword::create(self::context());
        $koaContext = self::createKoaContext();

        self::resolve($mutation, null, ['code' => 'reset-token', 'password' => 'Strapi1234', 'passwordConfirmation' => 'Strapi1234'], ['koaContext' => $koaContext]);

        self::assertSame(['factory', 'rateLimit:/api/auth/reset-password', 'controller:resetPassword'], $this->events);
        self::assertSame($koaContext, $this->controllerCalls[0]['ctx']);
    }

    public function testSerializesOperationsSharingAGraphqlKoaContext(): void
    {
        $mutation = ForgotPassword::create(self::context());
        $koaContext = self::createKoaContext();
        $controllerEmails = [];

        $this->controllerImplementations['forgotPassword'] = static function (Context $ctx) use (&$controllerEmails): mixed {
            $body = $ctx->requestBody();
            $controllerEmails[] = is_array($body) ? ($body['email'] ?? null) : null;
            $ctx->setBody(['ok' => true]);

            return null;
        };

        foreach (['decoy-one@example.com', 'decoy-two@example.com', 'target@example.com'] as $email) {
            self::resolve($mutation, null, ['email' => $email], ['koaContext' => $koaContext]);
        }

        self::assertSame(['decoy-one@example.com', 'decoy-two@example.com', 'target@example.com'], $controllerEmails);
        self::assertSame([], $koaContext->requestBody());
        self::assertSame('/graphql', $koaContext->path());
    }

    public function testRestoresKoaRequestAndResponseStateWhenAControllerThrows(): void
    {
        $mutation = ForgotPassword::create(self::context());
        $originalBody = ['preserved' => true];
        $originalParams = ['route' => 'graphql'];
        $originalResponseBody = ['response' => 'preserved'];
        $koaContext = self::createKoaContext();
        $koaContext->setRequestBody($originalBody);
        $koaContext->setParams($originalParams);
        $koaContext->setBody($originalResponseBody);
        $this->controllerImplementations['forgotPassword'] = static fn (): never => throw new \RuntimeException('controller failure');

        try {
            self::resolve($mutation, null, ['email' => 'victim@example.com'], ['koaContext' => $koaContext]);
            self::fail('expected the controller failure');
        } catch (\RuntimeException $e) {
            self::assertSame('controller failure', $e->getMessage());
        }

        self::assertSame($originalBody, $koaContext->requestBody());
        self::assertSame('/graphql', $koaContext->path());
        self::assertSame($originalParams, $koaContext->params());
        self::assertSame($originalResponseBody, $koaContext->body());
    }

    public function testUsesTheGraphqlSuccessStatusWhenAControllerThrows(): void
    {
        $mutation = ForgotPassword::create(self::context());
        $koaContext = self::createKoaContext();
        $koaContext->setStatus(404);
        $this->controllerImplementations['forgotPassword'] = static fn (): never => throw new \RuntimeException('controller failure');

        try {
            self::resolve($mutation, null, ['email' => 'victim@example.com'], ['koaContext' => $koaContext]);
            self::fail('expected the controller failure');
        } catch (\RuntimeException) {
        }

        self::assertSame(200, $koaContext->status());
    }

    public function testPreservesTheControllerResponseStatusAfterClearingAResponseBody(): void
    {
        $mutation = ForgotPassword::create(self::context());
        // Koa's implicit 404 (upstream's mock context switches to 200 when a body is set, as Koa
        // does while the status is implicit)
        $koaContext = self::createKoaContext();
        self::assertSame(404, $koaContext->status());

        self::resolve($mutation, null, ['email' => 'victim@example.com'], ['koaContext' => $koaContext]);

        self::assertNull($koaContext->body());
        self::assertSame(200, $koaContext->status());
    }

    /** @return iterable<string, array{callable, array<string, mixed>, string, string}> */
    public static function mutations(): iterable
    {
        yield 'login' => [Login::create(...), ['input' => ['identifier' => 'user@example.com', 'password' => 'Strapi1234']], 'callback', '/api/auth/local'];
        yield 'register' => [Register::create(...), ['input' => ['username' => 'user', 'email' => 'user@example.com', 'password' => 'Strapi1234']], 'register', '/api/auth/local/register'];
        yield 'changePassword' => [ChangePassword::create(...), ['currentPassword' => 'Strapi1234', 'password' => 'Strapi12345', 'passwordConfirmation' => 'Strapi12345'], 'changePassword', '/api/auth/change-password'];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('mutations')]
    public function testRunsUsersPermissionsRateLimitBeforeTheController(callable $createMutation, array $args, string $controller, string $routePath): void
    {
        $mutation = $createMutation(self::context());
        \assert(is_array($mutation));
        $koaContext = self::createKoaContext();

        self::resolve($mutation, null, $args, ['koaContext' => $koaContext]);

        self::assertSame(['factory', "rateLimit:{$routePath}", "controller:{$controller}"], $this->events);
        self::assertSame($koaContext, $this->controllerCalls[0]['ctx']);
        self::assertSame('/graphql', $koaContext->path());
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Core;

use Psr\Log\LoggerInterface;
use Strapi\Core\Configuration\Configuration;
use Strapi\Core\Configuration\ServerConfig;
use Strapi\Core\Providers\Provider;
use Strapi\Core\Providers\Providers;
use Strapi\Core\Services\Auth\Auth;
use Strapi\Core\Services\Config;
use Strapi\Core\Services\ContentApi\ContentApi;
use Strapi\Core\Services\CoreStore;
use Strapi\Core\Services\Cron;
use Strapi\Core\Services\CustomFields;
use Strapi\Core\Services\DocumentService\DocumentService;
use Strapi\Core\Services\EntityService\EntityService;
use Strapi\Core\Services\EntityValidator\EntityValidator;
use Strapi\Core\Services\EventHub;
use Strapi\Core\Services\Features;
use Strapi\Core\Services\Fs;
use Strapi\Core\Services\Localization;
use Strapi\Core\Services\QueryParams;
use Strapi\Core\Services\Reloader;
use Strapi\Core\Services\RequestContext;
use Strapi\Core\Services\Server\Server;
use Strapi\Core\Services\SessionManager;
use Strapi\Core\Utils\ConvertCustomFieldType;
use Strapi\Core\Utils\Fetch;
use Strapi\Core\Utils\IsInitialized;
use Strapi\Core\Utils\StartupLogger;
use Strapi\Database\Database;
use Strapi\Logger\Logger;
use Strapi\Types\Core\StrapiDirectories;
use Strapi\Types\Core\Strapi as StrapiContract;
use Strapi\Types\Modules\Documents\Repository;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\AuthScope;
use Strapi\Utils\ContentTypes as ContentTypesUtils;
use Strapi\Utils\EnvHelper;
use Strapi\Utils\Hooks\Hook;

/**
 * Port of packages/core/core/src/Strapi.ts: the `strapi` object.
 *
 * Lifecycle (same order as upstream): `new Strapi($options)` → providers `init` →
 * `register()` (providers register: loaders + hooks; plugins/apis register; `src/index.php` register;
 * custom field type conversion) → `bootstrap()` (db init with every content type, component and raw
 * model; `beforeSync` hook; schema sync; repairs; `afterSync`; store the schema; middlewares; routing;
 * content-API actions; plugins/apis bootstrap; providers bootstrap; user bootstrap) → `load()` =
 * register + bootstrap → `start()` = load + listen → `destroy()`.
 *
 * Upstream getters (`strapi.db`, `strapi.config`...) are methods here (`db()`, `config()`).
 *
 * @phpstan-type StrapiOptions array{appDir: string, distDir?: string, autoReload?: bool, serveAdminPanel?: bool}
 */
final class Strapi extends Container implements StrapiContract
{
    /** `src/index.php` contents: `['register' => fn, 'bootstrap' => fn, 'destroy' => fn]`. @var array<string, callable|null> */
    public array $app = [];

    private bool $loaded = false;

    /** @var array<string, mixed> */
    public array $internal_config = [];

    private readonly EnvHelper $env;

    /** @var list<Provider> */
    private readonly array $providers;

    /** @var array<string, Schema|null> */
    private array $modelCache = [];

    /**
     * `Core::createStrapi()` shortcut (upstream `createStrapi` lives in index.ts).
     *
     * @param array{appDir?: string|null, distDir?: string|null, autoReload?: bool, serveAdminPanel?: bool} $options
     */
    public static function createStrapi(array $options = []): self
    {
        return Core::createStrapi($options);
    }

    /** @param StrapiOptions $opts */
    public function __construct(array $opts)
    {
        $this->internal_config = Configuration::loadConfiguration($opts);
        $this->env = EnvHelper::fromProcess();
        $this->providers = Providers::all();

        $this->registerInternalServices();

        foreach ($this->providers as $provider) {
            $provider->init($this);
        }
    }

    // --- container shortcuts -----------------------------------------------------------------

    public function env(): EnvHelper
    {
        return $this->env;
    }

    public function admin(): mixed
    {
        return $this->get('admin');
    }

    public function ai(): Services\Ai
    {
        return $this->get('ai');
    }

    public function EE(): bool
    {
        return Utils\Ee::isEE();
    }

    public function dirs(): StrapiDirectories
    {
        return $this->config()->get('dirs');
    }

    public function reload(): Reloader
    {
        return $this->get('reload');
    }

    /**
     * Upstream resolves a relative SQLite filename with `path.resolve()`, i.e. against the process
     * working directory, which for Node Strapi is always the project root. Under PHP's web SAPIs
     * (FPM, FrankenPHP, the built-in server) the working directory is `public/`, so the same
     * relative path would put the database inside the web root, where it can be downloaded. Resolve
     * it against the project root instead, which is what upstream gets.
     *
     * @param array<string, mixed> $databaseConfig
     * @return array<string, mixed>
     */
    public static function resolveSqliteFilename(array $databaseConfig, string $root): array
    {
        $client = $databaseConfig['connection']['client'] ?? null;
        $filename = $databaseConfig['connection']['connection']['filename'] ?? null;
        if (!in_array($client, ['sqlite', 'sqlite3', 'better-sqlite3'], true) || !is_string($filename)) {
            return $databaseConfig;
        }
        if ($filename === '' || $filename === ':memory:' || str_starts_with($filename, 'file:') || str_starts_with($filename, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $filename) === 1) {
            return $databaseConfig;
        }

        $databaseConfig['connection']['connection']['filename'] = rtrim($root, '/') . '/' . $filename;

        return $databaseConfig;
    }

    public function db(): Database
    {
        return $this->get('db');
    }

    public function requestContext(): RequestContext
    {
        return $this->get('requestContext');
    }

    public function customFields(): CustomFields
    {
        return $this->get('customFields');
    }

    public function entityValidator(): EntityValidator
    {
        return $this->get('entityValidator');
    }

    /** @deprecated `strapi.entityService` will be removed in the next major version */
    public function entityService(): EntityService
    {
        return $this->get('entityService');
    }

    /** `strapi.documents(uid)`; `documentService()` is the factory (`.use()` for middlewares). */
    public function documents(string $uid): Repository
    {
        return $this->documentService()($uid);
    }

    public function documentService(): DocumentService
    {
        return $this->get('documents');
    }

    public function localization(): Localization
    {
        return $this->get('localization');
    }

    public function features(): Features
    {
        return $this->get('features');
    }

    public function fetch(): Fetch
    {
        return $this->get('fetch');
    }

    public function cron(): Cron
    {
        return $this->get('cron');
    }

    public function log(): LoggerInterface
    {
        return $this->get('logger');
    }

    public function startupLogger(): StartupLogger
    {
        return $this->get('startupLogger');
    }

    public function eventHub(): EventHub
    {
        return $this->get('eventHub');
    }

    public function fs(): Fs
    {
        return $this->get('fs');
    }

    public function server(): Server
    {
        return $this->get('server');
    }

    public function telemetry(): Services\Metrics\Metrics
    {
        return $this->get('telemetry');
    }

    public function sessionManager(): SessionManager
    {
        return $this->get('sessionManager');
    }

    public function store(): CoreStore
    {
        return $this->get('coreStore');
    }

    public function config(): Config
    {
        return $this->get('config');
    }

    /** @return array<string, object> */
    public function services(): array
    {
        return $this->get('services')->getAll();
    }

    public function service(string $uid): object
    {
        $service = $this->get('services')->get($uid);
        if ($service === null) {
            throw new \RuntimeException("Service {$uid} not found");
        }

        return $service;
    }

    /** @return array<string, object> */
    public function controllers(): array
    {
        return $this->get('controllers')->getAll();
    }

    public function controller(string $uid): object
    {
        $controller = $this->get('controllers')->get($uid);
        if ($controller === null) {
            throw new \RuntimeException("Controller {$uid} not found");
        }

        return $controller;
    }

    public function contentTypes(): array
    {
        return $this->get('content-types')->getAll();
    }

    public function contentType(string $uid): Schema
    {
        $contentType = $this->get('content-types')->get($uid);
        if ($contentType === null) {
            throw new \RuntimeException("Content type {$uid} not found");
        }

        return $contentType;
    }

    public function components(): array
    {
        return $this->get('components')->getAll();
    }

    /** @return array<string, callable|\Strapi\Utils\Policy\PolicyDefinition> */
    public function policies(): array
    {
        return $this->get('policies')->getAll();
    }

    public function policy(string $uid): callable
    {
        $policy = $this->get('policies')->get($uid);
        if ($policy === null) {
            throw new \RuntimeException("Policy {$uid} not found");
        }

        return $policy instanceof \Strapi\Utils\Policy\PolicyDefinition ? $policy->handler : $policy;
    }

    /** @return array<string, callable> */
    public function middlewares(): array
    {
        return $this->get('middlewares')->getAll();
    }

    public function middleware(string $uid): callable
    {
        $middleware = $this->get('middlewares')->get($uid);
        if ($middleware === null) {
            throw new \RuntimeException("Middleware {$uid} not found");
        }

        return $middleware;
    }

    public function plugins(): array
    {
        return $this->get('plugins')->getAll();
    }

    public function plugin(string $name): Domain\Module\Module
    {
        $plugin = $this->get('plugins')->get($name);
        if ($plugin === null) {
            throw new \RuntimeException("Plugin {$name} not found");
        }

        return $plugin;
    }

    /** upstream `strapi.plugin('x')` returns undefined for unknown plugins; use this for that check */
    public function hasPlugin(string $name): bool
    {
        return $this->get('plugins')->get($name) !== null;
    }

    /** @return array<string, Hook> */
    public function hooks(): array
    {
        return $this->get('hooks')->getAll();
    }

    public function hook(string $name): Hook
    {
        $hook = $this->get('hooks')->get($name);
        if ($hook === null) {
            throw new \RuntimeException("Hook {$name} not found");
        }

        return $hook;
    }

    public function apis(): array
    {
        return $this->get('apis')->getAll();
    }

    public function api(string $name): Domain\Module\Module
    {
        $api = $this->get('apis')->get($name);
        if ($api === null) {
            throw new \RuntimeException("API {$name} not found");
        }

        return $api;
    }

    public function auth(): Auth
    {
        return $this->get('auth');
    }

    public function contentAPI(): ContentApi
    {
        return $this->get('content-api');
    }

    public function sanitizers(): Registries\Sanitizers
    {
        return $this->get('sanitizers');
    }

    public function validators(): Registries\Validators
    {
        return $this->get('validators');
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    // --- lifecycle ---------------------------------------------------------------------------

    public function start(): static
    {
        try {
            if (!$this->loaded) {
                $this->load();
            }

            $this->listen();

            return $this;
        } catch (\Throwable $error) {
            $this->stopWithError($error);
        }
    }

    private function registerInternalServices(): void
    {
        $config = Config::createConfigProvider($this->internal_config, fn (): ?LoggerInterface => $this->has('logger') ? $this->log() : null);

        $loggerConfig = array_merge(
            ['level' => 'http'], // Strapi defaults to level 'http'
            is_array($config->get('logger')) ? $config->get('logger') : [], // DEPRECATED
            is_array($config->get('server.logger.config')) ? $config->get('server.logger.config') : [],
        );
        $logger = Logger::createLogger($loggerConfig);

        ServerConfig::warnDeprecatedServerConfig($config, $logger);

        // Instantiate the Strapi container
        $this->add('config', static fn (): Config => $config)
            ->add('query-params', fn (): QueryParams => QueryParams::createQueryParamService($this))
            ->add('content-api', fn (): ContentApi => ContentApi::createContentAPI($this))
            ->add('auth', static fn (): Auth => Auth::createAuthentication())
            ->add('server', fn (): Server => Server::createServer($this))
            ->add('fs', fn (): Fs => Fs::createStrapiFs($this))
            ->add('eventHub', static fn (): EventHub => EventHub::createEventHub())
            ->add('startupLogger', fn (): StartupLogger => StartupLogger::createStartupLogger($this))
            ->add('logger', static fn (): LoggerInterface => $logger)
            ->add('fetch', fn (): Fetch => Fetch::createStrapiFetch($this))
            ->add('features', fn (): Features => Features::createFeaturesService($this))
            ->add('requestContext', static fn (): RequestContext => new RequestContext())
            ->add('customFields', fn (): CustomFields => CustomFields::createCustomFields($this))
            ->add('entityValidator', fn (): EntityValidator => new EntityValidator($this))
            ->add('entityService', fn (): EntityService => EntityService::createEntityService($this))
            ->add('documents', fn (): DocumentService => DocumentService::createDocumentService($this))
            ->add('localization', static fn (): Localization => Localization::createLocalizationService())
            ->add('db', function () use ($logger): Database {
                $databaseConfig = $this->config()->get('database');
                $databaseConfig = is_array($databaseConfig) ? $databaseConfig : [];
                $databaseConfig = self::resolveSqliteFilename($databaseConfig, $this->dirs()->root);

                return new Database(\Strapi\Utils\Primitives\Objects::merge($databaseConfig, [
                    'logger' => $logger,
                    'settings' => ['migrations' => ['dir' => $this->dirs()->root . '/database/migrations']],
                ]));
            })
            ->add('reload', fn (): Reloader => Reloader::createReloader($this));

        // the restricted-relation sanitizers consult `strapi.auth.verify`
        AuthScope::setVerifier(function (mixed $auth, string $scope): bool {
            $this->auth()->verify(is_array($auth) ? $auth : null, ['scope' => [$scope]]);

            return true;
        });
        AuthScope::setRegisteredContentTypes(fn (): array => array_keys($this->contentTypes()));
    }

    private function postListen(): void
    {
        $isInitialized = IsInitialized::isInitialized($this);

        $this->startupLogger()->logStartupMessage(['isInitialized' => $isInitialized]);

        $this->log()->info('Strapi started successfully');
    }

    /** Serve HTTP (blocking) with the configured host/port. */
    public function listen(): void
    {
        $host = (string) $this->config()->get('server.host', '0.0.0.0');
        $port = (int) $this->config()->get('server.port', 1337);

        $this->server()->listen($host, $port, fn () => $this->postListen());
    }

    public function stopWithError(\Throwable $err, ?string $customMessage = null): never
    {
        $this->log()->debug("⛔️ Server wasn't able to start properly.");
        if ($customMessage !== null) {
            $this->log()->error($customMessage);
        }

        $this->log()->error($err->getMessage(), ['exception' => $err]);

        $this->stop(1, $err);
    }

    public function stop(int $exitCode = 1, ?\Throwable $error = null): never
    {
        try {
            $this->destroy();
        } catch (\Throwable) {
            // ignore
        }

        if (PHP_SAPI !== 'cli' && $error !== null) {
            throw $error;
        }
        if ($error !== null && getenv('STRAPI_NO_EXIT') === '1') {
            throw $error;
        }

        exit($exitCode);
    }

    public function load(): static
    {
        $this->register();
        $this->bootstrap();

        $this->loaded = true;

        return $this;
    }

    public function register(): static
    {
        foreach ($this->providers as $provider) {
            $provider->register($this);
        }

        $this->runPluginsLifecycles(Utils\Lifecycles::REGISTER);
        $this->runUserLifecycles(Utils\Lifecycles::REGISTER);

        // NOTE: Swap type customField for underlying data type
        ConvertCustomFieldType::convertCustomFieldType($this);
        $this->modelCache = [];
        $this->contentAPI()->refresh();

        return $this;
    }

    public function bootstrap(): static
    {
        // PHP port: upstream boots one process. FrankenPHP and FPM boot several at once on the same
        // project, and bootstrap writes to the database (schema sync, migrations, the default
        // roles and permissions plugins create), so concurrent first boots race. Serialize them.
        $lock = $this->acquireBootstrapLock();

        try {
            return $this->doBootstrap();
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return resource|null */
    private function acquireBootstrapLock()
    {
        $dir = $this->dirs()->root . '/.tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return null;
        }
        $handle = @fopen($dir . '/bootstrap.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function doBootstrap(): static
    {
        // content types + components (Schema objects) and the raw models (core store, webhooks)
        $models = [...array_values($this->contentTypes()), ...array_values($this->components()), ...$this->get('models')->get()];
        $db = $this->db();
        $db->init($models);

        $oldContentTypes = null;
        if ($db->getSchemaConnection()->tablesExist([CoreStore::coreStoreModel()['tableName']])) {
            $oldContentTypes = $this->store()->get(['type' => 'strapi', 'name' => 'content_types', 'key' => 'schema']);
        }

        $this->hook('strapi::content-types.beforeSync')->call(['oldContentTypes' => $oldContentTypes, 'contentTypes' => $this->contentTypes()]);

        $status = $db->schema->sync();

        // if schemas have changed, run repairs
        if ($status === 'CHANGED') {
            $db->repair->removeOrphanMorphType(['pivot' => 'component_type']);
            $db->repair->removeOrphanMorphType(['pivot' => 'related_type']);
        }

        $alreadyRanComponentRepair = $this->store()->get(['type' => 'strapi', 'key' => 'unidirectional-join-table-repair-ran']);

        if (!$alreadyRanComponentRepair) {
            $db->repair->processUnidirectionalJoinTables(Services\DocumentService\Utils\CleanComponentJoinTable::create($this));
            $this->store()->set(['type' => 'strapi', 'key' => 'unidirectional-join-table-repair-ran', 'value' => true]);
        }

        $this->hook('strapi::content-types.afterSync')->call(['oldContentTypes' => $oldContentTypes, 'contentTypes' => $this->contentTypes()]);

        $this->store()->set([
            'type' => 'strapi',
            'name' => 'content_types',
            'key' => 'schema',
            'value' => array_map(static fn (Schema $schema): array => $schema->toArray(), $this->contentTypes()),
        ]);

        $this->server()->initMiddlewares();
        $this->server()->initRouting();

        $this->contentAPI()->permissions->registerActions();

        $this->runPluginsLifecycles(Utils\Lifecycles::BOOTSTRAP);

        foreach ($this->providers as $provider) {
            $provider->bootstrap($this);
        }

        $this->runUserLifecycles(Utils\Lifecycles::BOOTSTRAP);

        return $this;
    }

    public function destroy(): void
    {
        $this->log()->info('Shutting down Strapi');
        $this->runPluginsLifecycles(Utils\Lifecycles::DESTROY);

        foreach ($this->providers as $provider) {
            $provider->destroy($this);
        }

        $this->runUserLifecycles(Utils\Lifecycles::DESTROY);

        $this->server()->destroy();

        $this->eventHub()->destroy();

        if ($this->has('db')) {
            $this->db()->destroy();
        }

        AuthScope::setVerifier(null);
        AuthScope::setRegisteredContentTypes(null);

        $this->log()->info('Strapi has been shut down');
    }

    private function runPluginsLifecycles(string $lifecycleName): void
    {
        $this->get('modules')->{$lifecycleName}();
    }

    private function runUserLifecycles(string $lifecycleName): void
    {
        $userLifecycleFunction = $this->app[$lifecycleName] ?? null;
        if (is_callable($userLifecycleFunction)) {
            $userLifecycleFunction($this);
        }
    }

    /** A content type or component schema by uid (null when unknown). Memoized until `register()` completes. */
    public function getModel(string $uid): ?Schema
    {
        if (array_key_exists($uid, $this->modelCache) && $this->loaded) {
            return $this->modelCache[$uid];
        }

        $model = $this->get('content-types')->get($uid) ?? $this->get('components')->get($uid);

        if ($this->loaded) {
            $this->modelCache[$uid] = $model;
        }

        return $model;
    }

    /** @deprecated Use `strapi.db.query` instead */
    public function query(string $uid): \Strapi\Database\EntityManager\EntityRepository
    {
        return $this->db()->query($uid);
    }
}

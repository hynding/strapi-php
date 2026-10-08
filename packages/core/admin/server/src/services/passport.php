<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Admin\Services\Passport\LocalStrategy;
use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/services/passport.ts (`admin::passport`).
 *
 * koa-passport is replaced by a small strategy registry: `init()` registers the strategies
 * returned by `getPassportStrategies()` and returns the (no-op) `passport.initialize()`
 * middleware; `authenticate($name, $ctx)` runs a strategy and returns the arguments passport
 * passes to its callback, `[error, user|false, info]`.
 */
final class Passport
{
    /** @var array<string, string> */
    public array $authEventsMapper = [
        'onConnectionSuccess' => 'admin.auth.success',
        'onConnectionError' => 'admin.auth.error',
    ];

    /** @var array<string, object> */
    private array $strategies = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return list<object> */
    public function getPassportStrategies(): array
    {
        return [LocalStrategy::createLocalStrategy($this->strapi)];
    }

    public function registerAuthEvents(): void
    {
        $auth = $this->strapi->config()->get('admin.auth', []);
        $events = is_array($auth) && is_array($auth['events'] ?? null) ? $auth['events'] : [];
        $authEventsMapper = Utils::getService($this->strapi, 'passport')->authEventsMapper;

        foreach ($events as $eventName => $handler) {
            if (!array_key_exists((string) $eventName, $authEventsMapper) || !is_callable($handler)) {
                continue;
            }

            $this->strapi->eventHub()->on($authEventsMapper[$eventName], $handler);
        }
    }

    /** Registers a strategy (`passport.use(strategy)`), keyed by its `name`. */
    public function use(object $strategy): void
    {
        $name = property_exists($strategy, 'name') ? (string) $strategy->name : $strategy::class;
        $this->strategies[$name] = $strategy;
    }

    /** @return callable(Context, callable): mixed */
    public function init(): callable
    {
        foreach (Utils::getService($this->strapi, 'passport')->getPassportStrategies() as $strategy) {
            $this->use($strategy);
        }

        $this->registerAuthEvents();

        // passport.initialize()
        return static fn (Context $ctx, callable $next): mixed => $next();
    }

    /** @return array{0: mixed, 1: mixed, 2?: mixed} */
    public function authenticate(string $name, Context $ctx): array
    {
        $strategy = $this->strategies[$name] ?? null;
        if ($strategy === null || !method_exists($strategy, 'authenticate')) {
            return [new \RuntimeException("Unknown authentication strategy \"{$name}\""), false];
        }

        return $strategy->authenticate($ctx);
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Core\Domain\Module;

use Strapi\Core\Registries\Namespace_;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Module as ModuleContract;
use Strapi\Types\Core\Strapi as StrapiContract;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Errors\YupValidationError;

/**
 * Port of packages/core/core/src/domain/module/index.ts (`createModule`): a loaded API or plugin.
 *
 * The raw module is the loader output:
 * `['config' => [...], 'routes' => [...], 'controllers' => [...], 'services' => [...], 'contentTypes' => [...],
 *   'policies' => [...], 'middlewares' => [...], 'register' => fn, 'bootstrap' => fn, 'destroy' => fn]`.
 *
 * @phpstan-type RawModule array<string, mixed>
 */
final class Module implements ModuleContract
{
    /** @var array{register?: bool, bootstrap?: bool, destroy?: bool} */
    private array $called = [];

    /** @var RawModule */
    private array $rawModule;

    /** @param RawModule $rawModule */
    private function __construct(private readonly string $namespace, array $rawModule, private readonly Strapi $strapi)
    {
        $this->rawModule = [
            ...['config' => [], 'routes' => [], 'controllers' => [], 'services' => [], 'contentTypes' => [], 'policies' => [], 'middlewares' => []],
            ...$rawModule,
        ];
    }

    /** @param RawModule $rawModule */
    public static function createModule(string $namespace, array $rawModule, Strapi $strapi): self
    {
        $module = new self($namespace, $rawModule, $strapi);

        try {
            Validation::validateModule($module->rawModule);
        } catch (YupValidationError $e) {
            $messages = implode("\n", array_map(static fn (array $err): string => $err['message'], $e->errors()));

            throw new \RuntimeException("strapi-server.php is invalid for '{$namespace}'.\n{$messages}", 0, $e);
        }

        return $module;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function bootstrap(?StrapiContract $strapi = null): void
    {
        if ($this->called['bootstrap'] ?? false) {
            throw new \RuntimeException("Bootstrap for {$this->namespace} has already been called");
        }
        $this->called['bootstrap'] = true;
        $this->runLifecycle('bootstrap');
    }

    public function register(?StrapiContract $strapi = null): void
    {
        if ($this->called['register'] ?? false) {
            throw new \RuntimeException("Register for {$this->namespace} has already been called");
        }
        $this->called['register'] = true;
        $this->runLifecycle('register');
    }

    public function destroy(?StrapiContract $strapi = null): void
    {
        if ($this->called['destroy'] ?? false) {
            throw new \RuntimeException("Destroy for {$this->namespace} has already been called");
        }
        $this->called['destroy'] = true;
        $this->runLifecycle('destroy');
    }

    private function runLifecycle(string $name): void
    {
        $fn = $this->rawModule[$name] ?? null;
        if (is_callable($fn)) {
            $fn($this->strapi);
        }
    }

    public function load(): void
    {
        $this->strapi->get('content-types')->add($this->namespace, $this->rawModule['contentTypes']);
        $this->strapi->get('services')->add($this->namespace, $this->rawModule['services']);
        $this->strapi->get('policies')->add($this->namespace, $this->rawModule['policies']);
        $this->strapi->get('middlewares')->add($this->namespace, $this->rawModule['middlewares']);
        $this->strapi->get('controllers')->add($this->namespace, $this->rawModule['controllers']);
        $this->strapi->get('config')->set($this->namespace, $this->rawModule['config']);
    }

    /** @return array<string, mixed>|list<array<string, mixed>> */
    public function routes(): array
    {
        return $this->rawModule['routes'] ?? [];
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $routes */
    public function setRoutes(array $routes): void
    {
        $this->rawModule['routes'] = $routes;
    }

    public function config(?string $path = null, mixed $default = null): mixed
    {
        $fullPath = $path === null || $path === '' ? $this->namespace : "{$this->namespace}.{$path}";

        return $this->strapi->get('config')->get($fullPath, $default);
    }

    public function contentType(string $ctName): Schema
    {
        $ct = $this->strapi->get('content-types')->get("{$this->namespace}.{$ctName}");
        if ($ct === null) {
            throw new \RuntimeException("Content-type {$this->namespace}.{$ctName} not found");
        }

        return $ct;
    }

    public function contentTypes(): array
    {
        return $this->removeNamespacedKeys($this->strapi->get('content-types')->getAll($this->namespace));
    }

    public function service(string $serviceName): object
    {
        $service = $this->strapi->get('services')->get("{$this->namespace}.{$serviceName}");
        if ($service === null) {
            throw new \RuntimeException("Service {$this->namespace}.{$serviceName} not found");
        }

        return $service;
    }

    public function services(): array
    {
        return $this->removeNamespacedKeys($this->strapi->get('services')->getAll($this->namespace));
    }

    public function policy(string $policyName): callable
    {
        $policy = $this->strapi->get('policies')->get("{$this->namespace}.{$policyName}");
        if ($policy === null) {
            throw new \RuntimeException("Policy {$this->namespace}.{$policyName} not found");
        }

        return $policy instanceof \Strapi\Utils\Policy\PolicyDefinition ? $policy->handler : $policy;
    }

    public function policies(): array
    {
        return array_map(
            static fn (mixed $p): callable => $p instanceof \Strapi\Utils\Policy\PolicyDefinition ? $p->handler : $p,
            $this->removeNamespacedKeys($this->strapi->get('policies')->getAll($this->namespace)),
        );
    }

    public function middleware(string $middlewareName): callable
    {
        $middleware = $this->strapi->get('middlewares')->get("{$this->namespace}.{$middlewareName}");
        if ($middleware === null) {
            throw new \RuntimeException("Middleware {$this->namespace}.{$middlewareName} not found");
        }

        return $middleware;
    }

    public function middlewares(): array
    {
        return $this->removeNamespacedKeys($this->strapi->get('middlewares')->getAll($this->namespace));
    }

    public function controller(string $controllerName): object
    {
        $controller = $this->strapi->get('controllers')->get("{$this->namespace}.{$controllerName}");
        if ($controller === null) {
            throw new \RuntimeException("Controller {$this->namespace}.{$controllerName} not found");
        }

        return $controller;
    }

    public function controllers(): array
    {
        return $this->removeNamespacedKeys($this->strapi->get('controllers')->getAll($this->namespace));
    }

    /**
     * @template T
     * @param array<string, T> $map
     * @return array<string, T>
     */
    private function removeNamespacedKeys(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[Namespace_::removeNamespace((string) $key, $this->namespace)] = $value;
        }

        return $out;
    }
}

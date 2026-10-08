<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalSource;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Types\Providers\ISourceProvider;
use Strapi\DataTransfer\Utils\Diagnostic;
use Strapi\DataTransfer\Utils\Providers;
use Strapi\DataTransfer\Utils\Schema;

/**
 * Port of src/strapi/providers/local-source/index.ts: `createLocalStrapiSourceProvider()`, the
 * source that reads a Strapi instance (entities through the database with their components
 * populated, links from the relation tables, the upload files, core-store and webhooks).
 *
 * Options: `getStrapi` (callable returning the instance), `autoDestroy` (destroy it on close,
 * default true).
 *
 * @phpstan-type LocalSourceOptions array{getStrapi: callable(): Strapi, autoDestroy?: bool|null}
 */
final class LocalSource implements ISourceProvider
{
    public string $name = 'source::local-strapi';

    public string $type = 'source';

    /** @var LocalSourceOptions */
    public array $options;

    public ?Strapi $strapi = null;

    /** @var array<string, mixed>|null */
    public ?array $results = null;

    private ?Diagnostic $diagnostics = null;

    /** @param LocalSourceOptions $options */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    /** @param LocalSourceOptions $options */
    public static function createLocalStrapiSourceProvider(array $options): self
    {
        return new self($options);
    }

    /** `assertValidStrapi(this.strapi, msg)`, returning the instance. */
    private function requireStrapi(string $msg = ''): Strapi
    {
        $strapi = $this->strapi;
        Providers::assertValidStrapi($strapi, $msg);

        return $strapi;
    }

    public function bootstrap(?Diagnostic $diagnostics = null): void
    {
        $this->diagnostics = $diagnostics;
        $this->strapi = ($this->options['getStrapi'])();
        $this->strapi->db()->lifecycles->disable();
    }

    private function reportInfo(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'local-source-provider',
            ],
            'kind' => 'info',
        ]);
    }

    private function reportWarning(string $message): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'origin' => 'local-source-provider',
            ],
            'kind' => 'warning',
        ]);
    }

    /**
     * Reports an error to the diagnostic reporter.
     */
    private function reportError(string $message, \Throwable $error): void
    {
        $this->diagnostics?->report([
            'details' => [
                'createdAt' => Diagnostic::now(),
                'message' => $message,
                'error' => $error,
                'severity' => 'fatal',
                'name' => 'Error',
            ],
            'kind' => 'error',
        ]);
    }

    /**
     * Handles errors that occur in read streams.
     */
    private function handleStreamError(string $streamType, \Throwable $err): void
    {
        $errorMessage = "[Data transfer] Error in {$streamType} read stream: {$err->getMessage()}";

        $this->strapi?->log()->error($errorMessage, ['stack' => $err->getTraceAsString(), 'timestamp' => Diagnostic::now()]);
        $this->reportError($errorMessage, $err);
    }

    public function close(): void
    {
        $autoDestroy = $this->options['autoDestroy'] ?? null;
        $strapi = $this->requireStrapi();
        $strapi->db()->lifecycles->enable();
        // Basically `!== false` but more deterministic
        if ($autoDestroy === null || $autoDestroy === true) {
            $strapi->destroy();
        }
    }

    /** @return array{createdAt: string, strapi: array{version: string}} */
    public function getMetadata(): array
    {
        $this->reportInfo('getting metadata');
        $strapi = $this->requireStrapi();
        $strapiVersion = (string) $strapi->config()->get('info.strapi');
        $createdAt = Diagnostic::now();

        return [
            'createdAt' => $createdAt,
            'strapi' => [
                'version' => $strapiVersion,
            ],
        ];
    }

    /** @return \Generator<int, array{type: string, id: mixed, data: array<string, mixed>}> */
    public function createEntitiesReadStream(): \Generator
    {
        $strapi = $this->requireStrapi('Not able to stream entities');
        $this->reportInfo('creating entities read stream');

        $transform = Entities::createEntitiesTransformStream();

        return (function () use ($strapi, $transform): \Generator {
            $stream = Entities::createEntitiesStream($strapi, [
                'onWarning' => function (string $message) use ($strapi): void {
                    $strapi->log()->warning($message);
                    $this->reportWarning($message);
                },
            ]);

            foreach ($stream as $item) {
                yield $transform($item);
            }
        })();
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function createLinksReadStream(): \Generator
    {
        $strapi = $this->requireStrapi('Not able to stream links');
        $this->reportInfo('creating links read stream');

        return Links::createLinksStream($strapi, [
            'onWarning' => function (string $message) use ($strapi): void {
                $strapi->log()->warning($message);
                $this->reportWarning($message);
            },
        ]);
    }

    /** @return \Generator<int, array{type: string, value: mixed}> */
    public function createConfigurationReadStream(): \Generator
    {
        $strapi = $this->requireStrapi('Not able to stream configuration');
        $this->reportInfo('creating configuration read stream');

        return Configuration::createConfigurationStream($strapi);
    }

    /** @return array<string, array<string, mixed>> */
    public function getSchemas(): array
    {
        $strapi = $this->requireStrapi('Not able to get Schemas');
        $this->reportInfo('getting schemas');
        $schemas = Schema::schemasToValidJSON([
            ...$strapi->contentTypes(),
            ...$strapi->components(),
        ]);

        return Schema::mapSchemasValues($schemas);
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function createSchemasReadStream(): \Generator
    {
        return (function (): \Generator {
            foreach ($this->getSchemas() as $schema) {
                yield $schema;
            }
        })();
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function createAssetsReadStream(): \Generator
    {
        $strapi = $this->requireStrapi('Not able to stream assets');
        $this->reportInfo('creating assets read stream');

        return (function () use ($strapi): \Generator {
            try {
                yield from Assets::createAssetsStream($strapi, [
                    'onWarning' => fn (string $message) => $this->reportWarning($message),
                ]);
            } catch (\Throwable $err) {
                $this->handleStreamError('assets', $err);
                throw $err;
            }
        })();
    }

    /** @return array{totalBytes: int, totalCount: int}|null */
    public function getStageTotals(string $stage): ?array
    {
        if ($stage !== 'assets') {
            return null;
        }
        $strapi = $this->requireStrapi('Not able to estimate asset totals');

        return EstimateAssetTotals::estimateAssetTotals($strapi);
    }
}

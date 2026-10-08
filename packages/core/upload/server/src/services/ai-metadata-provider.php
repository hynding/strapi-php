<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Zod as z;

/**
 * Port of server/src/services/ai-metadata-provider.ts.
 *
 * `strapi.ai.admin` is the `ai.admin` container entry (the admin package's AI service); while
 * it is not registered, AI is reported unavailable and no provider is resolved — as upstream
 * when `strapi.ai.admin.isAvailable()` is false.
 *
 * A provider is an object with a `name` and `generateMetadata(['images' => list<Blob>])`
 * returning `['results' => list<['altText' => string, 'caption' => string]>]`; an image ("Blob")
 * is `['data' => string, 'type' => string|null]`.
 *
 * @phpstan-type GenerateMetadataResult array{results: list<array{altText: string, caption: string}>}
 */
final class AiMetadataProvider
{
    private ?object $registeredProvider = null;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** `strapi.ai.admin`, or null when the AI admin service is not available */
    public static function aiAdmin(Strapi $strapi): ?object
    {
        if (!$strapi->has('ai.admin')) {
            return null;
        }
        $admin = $strapi->get('ai.admin');

        return is_object($admin) ? $admin : null;
    }

    public static function aiAdminCall(Strapi $strapi, string $method): mixed
    {
        $admin = self::aiAdmin($strapi);

        return $admin !== null && method_exists($admin, $method) ? $admin->{$method}() : false;
    }

    private function resolveProvider(): ?object
    {
        if (!self::aiAdminCall($this->strapi, 'isAvailable')) {
            return null;
        }

        return $this->registeredProvider;
    }

    private static function nameOf(object $provider): string
    {
        $name = property_exists($provider, 'name') ? $provider->name : (method_exists($provider, 'name') ? $provider->name() : null);

        return is_string($name) ? $name : 'undefined';
    }

    public function hasProvider(): bool
    {
        return $this->resolveProvider() !== null;
    }

    /** @param array{provider: object} $params */
    public function registerProvider(array $params): void
    {
        $provider = $params['provider'];

        if (!self::aiAdminCall($this->strapi, 'authorizeCustomProvider')) {
            return;
        }

        if ($this->registeredProvider !== null) {
            throw new \RuntimeException('The AI metadata provider "' . self::nameOf($this->registeredProvider) . '" is already registered, "' . self::nameOf($provider) . '" cannot replace it.');
        }

        $this->registeredProvider = $provider;
    }

    public function registerStrapiManagedProvider(): void
    {
        if (!self::aiAdminCall($this->strapi, 'isStrapiManagedAiEnabled')) {
            $this->strapi->log()->warning('The Strapi-managed AI metadata provider was ignored: the Strapi license does not include the "cms-ai" feature.');

            return;
        }

        $provider = AiMetadataStrapiManaged::createStrapiManagedAiMetadataProvider($this->strapi);

        if ($this->registeredProvider !== null) {
            throw new \RuntimeException('The AI metadata provider "' . self::nameOf($this->registeredProvider) . '" is already registered, "' . self::nameOf($provider) . '" cannot replace it.');
        }

        $this->registeredProvider = $provider;
    }

    /**
     * @param array{images: list<array{data: string, type: string|null}>} $params
     * @return GenerateMetadataResult
     */
    public function generateMetadata(array $params): array
    {
        $provider = $this->resolveProvider();

        if ($provider === null) {
            throw new \RuntimeException('No AI metadata provider is registered.');
        }

        if (!method_exists($provider, 'generateMetadata')) {
            throw new \RuntimeException('The AI metadata provider does not implement generateMetadata.');
        }

        $raw = $provider->generateMetadata($params);

        $resultSchema = z::object([
            'results' => z::array(
                z::object([
                    'altText' => z::string(),
                    'caption' => z::string(),
                ]),
            ),
        ]);

        /** @var GenerateMetadataResult $result */
        $result = $resultSchema->parse($raw);

        $fileCount = count($result['results']);

        $this->strapi->log()->info('AI generated metadata successfully for ' . $fileCount . ' file' . ($fileCount === 1 ? '' : 's'));

        return $result;
    }
}

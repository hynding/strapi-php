<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Strapi;

/**
 * Port of server/src/services/ai-translations.ts (`createAITranslationsService`).
 *
 * This service mirrors upload's ai-metadata-provider service on purpose. Keep the two in
 * sync, and only extract the shared logic once a third feature needs it.
 *
 * A provider is an object with a `name` and
 * `generateTranslations(['sourceLocale', 'targetLocales', 'content', 'contentTypeSchema'])`
 * returning `['localizations' => list<['content' => array, 'locale' => string]>]`.
 * `strapi.ai.admin` is the `ai.admin` container entry; while it is absent AI is unavailable.
 *
 * @phpstan-type GenerateTranslationsParams array{sourceLocale: string, targetLocales: list<string>, content: array<string, mixed>, contentTypeSchema: array<string, array<string, mixed>>}
 * @phpstan-type GenerateTranslationsResult array{localizations: list<array{content: array<string, mixed>, locale: string}>}
 */
final class AiTranslations
{
    private ?object $registeredProvider = null;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** `strapi.ai.admin.<method>()`, false when the AI admin service is absent */
    public static function aiAdminCall(Strapi $strapi, string $method): mixed
    {
        if (!$strapi->has('ai.admin')) {
            return false;
        }
        $admin = $strapi->get('ai.admin');

        return is_object($admin) && method_exists($admin, $method) ? $admin->{$method}() : false;
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
        $name = property_exists($provider, 'name') ? $provider->name : null;

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
            throw new \RuntimeException('The AI translations provider "' . self::nameOf($this->registeredProvider) . '" is already registered, "' . self::nameOf($provider) . '" cannot replace it.');
        }

        $this->registeredProvider = $provider;
    }

    public function registerStrapiManagedProvider(): void
    {
        if (!self::aiAdminCall($this->strapi, 'isStrapiManagedAiEnabled')) {
            $this->strapi->log()->warning('The Strapi-managed AI translations provider was ignored: the Strapi license does not include the "cms-ai" feature.');

            return;
        }

        $provider = AiTranslationsStrapiManaged::createStrapiManagedAiTranslationsProvider($this->strapi);

        if ($this->registeredProvider !== null) {
            throw new \RuntimeException('The AI translations provider "' . self::nameOf($this->registeredProvider) . '" is already registered, "' . self::nameOf($provider) . '" cannot replace it.');
        }

        $this->registeredProvider = $provider;
    }

    /**
     * @param GenerateTranslationsParams $params
     * @return GenerateTranslationsResult
     */
    public function generateTranslations(array $params): array
    {
        $provider = $this->resolveProvider();

        if ($provider === null) {
            throw new \RuntimeException('No AI translations provider is registered.');
        }

        if (!method_exists($provider, 'generateTranslations')) {
            throw new \RuntimeException('The AI translations provider does not implement generateTranslations.');
        }

        /** @var GenerateTranslationsResult $result */
        $result = $provider->generateTranslations($params);

        return $result;
    }
}

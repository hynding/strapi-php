<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services\AiTranslations;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/**
 * Port of server/src/services/__tests__/ai-translations.test.ts. `strapi.ai.admin` is a stub set
 * as the `ai.admin` container entry. The cases that stub the global `fetch` to check the
 * Strapi-managed provider's HTTP call are not ported (core's `Fetch` is a final stream client).
 */
final class AiTranslationsTest extends TestCase
{
    private const array PARAMS = [
        'sourceLocale' => 'en',
        'targetLocales' => ['fr'],
        'content' => ['title' => 'Some title'],
        'contentTypeSchema' => ['title' => ['type' => 'string']],
    ];

    private Strapi $strapi;

    private object $aiAdmin;

    private object $logger;

    private function service(bool $isAvailable = true, bool $authorizeCustomProvider = true, bool $isStrapiManagedAiEnabled = true): AiTranslations
    {
        $this->strapi = I18nTestApp::create();
        $this->aiAdmin = new class ($isAvailable, $authorizeCustomProvider, $isStrapiManagedAiEnabled) {
            public int $authorizeCalls = 0;

            public ?\Throwable $tokenError = null;

            public function __construct(public bool $available, private bool $authorize, private bool $managed)
            {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function authorizeCustomProvider(): bool
            {
                ++$this->authorizeCalls;

                return $this->authorize;
            }

            public function isStrapiManagedAiEnabled(): bool
            {
                return $this->managed;
            }

            /** @return array{token: string} */
            public function getAiToken(): array
            {
                if ($this->tokenError !== null) {
                    throw $this->tokenError;
                }

                return ['token' => 'test-token'];
            }
        };
        $this->strapi->set('ai.admin', $this->aiAdmin);
        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{mixed, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $this->strapi->set('logger', $this->logger);

        return new AiTranslations($this->strapi);
    }

    private static function provider(string $name = 'byok'): object
    {
        return new class ($name) {
            public int $calls = 0;

            public function __construct(public string $name)
            {
            }

            /** @return array{localizations: list<mixed>} */
            public function generateTranslations(array $params): array
            {
                ++$this->calls;

                return ['localizations' => []];
            }
        };
    }

    public function testNothingIsRegisteredUntilAProviderIs(): void
    {
        $service = $this->service();

        self::assertFalse($service->hasProvider());
        $this->expectExceptionMessage('No AI translations provider is registered.');
        $service->generateTranslations(self::PARAMS);
    }

    public function testRegisterStrapiManagedProviderInstallsTheStrapiManagedProvider(): void
    {
        $service = $this->service();
        $service->registerStrapiManagedProvider();

        self::assertTrue($service->hasProvider());
    }

    public function testTheStrapiManagedProviderWrapsAiTokenRetrievalFailures(): void
    {
        $service = $this->service();
        $this->aiAdmin->tokenError = new \RuntimeException('license expired');
        $service->registerStrapiManagedProvider();

        $this->expectExceptionMessage('Failed to retrieve AI token');
        $service->generateTranslations(self::PARAMS);
    }

    public function testRegisterProviderAsksCoreToAuthorizeACustomProvider(): void
    {
        $service = $this->service();
        $service->registerProvider(['provider' => self::provider()]);

        self::assertSame(1, $this->aiAdmin->authorizeCalls);
        self::assertTrue($service->hasProvider());
    }

    public function testARejectedProviderIsNotRegistered(): void
    {
        $service = $this->service(authorizeCustomProvider: false);
        $provider = self::provider();
        $service->registerProvider(['provider' => $provider]);

        self::assertFalse($service->hasProvider());
        try {
            $service->generateTranslations(self::PARAMS);
            self::fail('expected an error');
        } catch (\RuntimeException $e) {
            self::assertSame('No AI translations provider is registered.', $e->getMessage());
        }
        self::assertSame(0, $provider->calls);
        self::assertSame([], array_filter($this->logger->records, static fn (array $r): bool => $r[0] === 'warning'));
    }

    public function testARegisteredProviderFollowsTheAiAvailabilitySwitchAtRuntime(): void
    {
        $service = $this->service();
        $service->registerProvider(['provider' => self::provider()]);
        self::assertTrue($service->hasProvider());

        $this->aiAdmin->available = false;

        self::assertFalse($service->hasProvider());
        $this->expectExceptionMessage('No AI translations provider is registered.');
        $service->generateTranslations(self::PARAMS);
    }

    public function testARegisteredProviderGeneratesTranslations(): void
    {
        $service = $this->service();
        $provider = self::provider();
        $service->registerProvider(['provider' => $provider]);

        self::assertSame(['localizations' => []], $service->generateTranslations(self::PARAMS));
        self::assertSame(1, $provider->calls);
    }

    public function testThrowsWhenASecondProviderIsRegistered(): void
    {
        $service = $this->service();
        $service->registerProvider(['provider' => self::provider()]);

        $this->expectExceptionMessage('The AI translations provider "byok" is already registered, "other-byok" cannot replace it.');
        $service->registerProvider(['provider' => self::provider('other-byok')]);
    }

    public function testThrowsWhenACustomProviderIsRegisteredAfterTheStrapiManagedOne(): void
    {
        $service = $this->service();
        $service->registerStrapiManagedProvider();

        $this->expectExceptionMessage('The AI translations provider "strapi-managed" is already registered, "byok" cannot replace it.');
        $service->registerProvider(['provider' => self::provider()]);
    }

    public function testRegisterStrapiManagedProviderThrowsWhenACustomProviderIsAlreadyRegistered(): void
    {
        $service = $this->service();
        $service->registerProvider(['provider' => self::provider()]);

        $this->expectExceptionMessage('The AI translations provider "byok" is already registered, "strapi-managed" cannot replace it.');
        $service->registerStrapiManagedProvider();
    }

    public function testRegisterStrapiManagedProviderIsIgnoredWithoutTheCmsAiFeature(): void
    {
        $service = $this->service(isStrapiManagedAiEnabled: false);
        $service->registerStrapiManagedProvider();

        self::assertFalse($service->hasProvider());
        $warnings = array_values(array_filter($this->logger->records, static fn (array $r): bool => $r[0] === 'warning'));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('cms-ai', $warnings[0][1]);
    }
}

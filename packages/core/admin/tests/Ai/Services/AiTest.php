<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Ai\Services;

require_once __DIR__ . '/../../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Ai\Services\Ai;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Admin\Tests\RecordingLogger;
use Strapi\Core\Strapi;

/**
 * Port of server/src/ai/services/__tests__/ai.test.ts. The PHP port has no Enterprise license
 * (`ee/` is not ported), so the cases upstream runs with `strapi.ee.isEE = false` (or features
 * missing) are the ones that apply: the AI server is never contacted.
 */
final class AiTest extends TestCase
{
    private static ?Strapi $strapi = null;

    private RecordingLogger $logs;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected function setUp(): void
    {
        $this->logs = BootedAdminApp::recordLogs(self::strapi());
        self::strapi()->config()->set('admin.ai.enabled', true);
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    private function service(): Ai
    {
        $service = Ai::createAiAdminService(self::strapi());
        $service->fetch = static fn (): never => throw new \LogicException('the AI server must not be contacted');

        return $service;
    }

    public function testTheServiceIsRegisteredAsAiAdmin(): void
    {
        self::assertInstanceOf(Ai::class, self::strapi()->get('ai.admin'));
        self::assertSame(self::strapi()->get('ai.admin'), self::strapi()->ai()->admin());
    }

    public function testGetAiUsageShouldThrowWhenEeFeaturesAreNotEnabled(): void
    {
        try {
            $this->service()->getAiUsage();
            self::fail('expected an exception');
        } catch (\RuntimeException $e) {
            self::assertSame('AI usage data request failed. Check server logs for details.', $e->getMessage());
        }
        self::assertContains(['error', 'AI usage data request failed: AI is not enabled'], $this->logs->records);
    }

    public function testGetAiTokenShouldThrowErrorWhenEeFeaturesAreNotEnabled(): void
    {
        try {
            $this->service()->getAiToken();
            self::fail('expected an exception');
        } catch (\RuntimeException $e) {
            self::assertSame('AI token request failed. Check server logs for details.', $e->getMessage());
        }
        self::assertContains(['error', 'AI token request failed: AI is not enabled'], $this->logs->records);
    }

    public function testIsAvailableReturnsFalseWithoutAnEnterpriseLicense(): void
    {
        self::assertFalse($this->service()->isAvailable());
    }

    public function testIsAvailableReturnsFalseWhenConfigExplicitlyDisablesAi(): void
    {
        self::strapi()->config()->set('admin.ai.enabled', false);

        self::assertFalse($this->service()->isAvailable());
        self::assertFalse($this->service()->isStrapiManagedAiEnabled());
    }

    public function testIsStrapiManagedAiEnabledReturnsFalseWithoutTheCmsAiFeature(): void
    {
        self::assertFalse($this->service()->isStrapiManagedAiEnabled());
    }

    public function testAuthorizeCustomProviderReturnsFalseAndWarnsWhenTheCmsByokAiFeatureIsMissing(): void
    {
        $service = $this->service();

        self::assertFalse($service->authorizeCustomProvider());
        self::assertContains(['warning', 'A custom AI provider was rejected: the Strapi license does not include the "cms-byok-ai" feature. All AI features are disabled.'], $this->logs->records);
        self::assertFalse($service->isAvailable());
        self::assertFalse($service->isStrapiManagedAiEnabled());
    }

    public function testAuthorizeCustomProviderReturnsFalseLogsInfoAndDoesNotWarnWhenAiIsDisabled(): void
    {
        self::strapi()->config()->set('admin.ai.enabled', false);

        self::assertFalse($this->service()->authorizeCustomProvider());
        self::assertContains(['info', 'A custom AI provider was ignored: AI is disabled by the "admin.ai.enabled" config.'], $this->logs->records);
        self::assertSame([], array_values(array_filter($this->logs->records, static fn (array $e): bool => $e[0] === 'warning')));
    }

    public function testGetAiFeatureConfigSkipsThePluginsWithoutAnEnterpriseLicense(): void
    {
        self::assertSame(['isAiI18nConfigured' => false, 'isAiMediaLibraryConfigured' => false], $this->service()->getAiFeatureConfig());
    }
}

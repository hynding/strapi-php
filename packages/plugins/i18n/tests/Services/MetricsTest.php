<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests\Services;

require_once __DIR__ . '/../I18nTestApp.php';

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Strapi\Plugin\I18n\Services\Metrics;
use Strapi\Plugin\I18n\Tests\I18nTestApp;

/**
 * Port of server/src/services/__tests__/metrics.test.ts. Telemetry is a final class that only
 * logs (`Telemetry is disabled: event … was not sent`): the events are read from the log.
 */
final class MetricsTest extends TestCase
{
    public function testSendDidInitializeEventCountsLocalizedContentTypes(): void
    {
        $strapi = I18nTestApp::create();
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $strapi->set('logger', $logger);
        foreach ($strapi->get('content-types')->keys() as $uid) {
            $strapi->get('content-types')->set($uid, I18nTestApp::schema($uid, []));
        }
        I18nTestApp::addContentTypes($strapi, [
            'withI18n' => ['pluginOptions' => ['i18n' => ['localized' => true]]],
            'withoutI18n' => ['pluginOptions' => ['i18n' => ['localized' => false]]],
            'withNoOption' => ['pluginOptions' => []],
        ]);

        $localized = array_filter($strapi->contentTypes(), [$strapi->service('plugin::i18n.content-types'), 'isLocalizedContentType']);
        self::assertSame(['withI18n'], array_keys($localized));

        (new Metrics($strapi))->sendDidInitializeEvent();

        self::assertContains('Telemetry is disabled: event didInitializeI18n was not sent', $logger->messages);
    }
}

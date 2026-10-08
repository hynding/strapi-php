<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Tests;

require_once __DIR__ . '/BootedApp.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Bootstrap;

/**
 * Port of server/src/__tests__/bootstrap.test.ts. Upstream mocks `ApolloServer` to check the
 * warning is logged before the server is built; here `bootstrap()` runs on a booted app with a
 * recording logger.
 */
final class BootstrapTest extends TestCase
{
    private const string RECOMMENDATION = 'defaultLimit: 25, maxLimit: 100, depthLimit: 10';

    private const string CUSTOM_RULES_NOTE = 'Custom Apollo validation rules may independently enforce limits.';

    private const string DOCUMENTATION_URL = 'https://docs.strapi.io/cms/configurations/plugins';

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unboundedLimits(): iterable
    {
        yield 'maxLimit -1' => [['depthLimit' => 10, 'maxLimit' => -1], 'maxLimit'];
        yield 'maxLimit undefined' => [['depthLimit' => 10], 'maxLimit'];
        yield 'maxLimit null' => [['depthLimit' => 10, 'maxLimit' => null], 'maxLimit'];
        yield 'maxLimit NaN' => [['depthLimit' => 10, 'maxLimit' => NAN], 'maxLimit'];
        yield 'maxLimit Infinity' => [['depthLimit' => 10, 'maxLimit' => INF], 'maxLimit'];
        yield 'maxLimit -Infinity' => [['depthLimit' => 10, 'maxLimit' => -INF], 'maxLimit'];
        yield 'maxLimit 0' => [['depthLimit' => 10, 'maxLimit' => 0], 'maxLimit'];
        yield 'maxLimit -2' => [['depthLimit' => 10, 'maxLimit' => -2], 'maxLimit'];
        yield 'depthLimit undefined' => [['maxLimit' => 100], 'depthLimit'];
        yield 'depthLimit null' => [['depthLimit' => null, 'maxLimit' => 100], 'depthLimit'];
        yield 'depthLimit NaN' => [['depthLimit' => NAN, 'maxLimit' => 100], 'depthLimit'];
        yield 'depthLimit Infinity' => [['depthLimit' => INF, 'maxLimit' => 100], 'depthLimit'];
        yield 'depthLimit -Infinity' => [['depthLimit' => -INF, 'maxLimit' => 100], 'depthLimit'];
        yield 'depthLimit 0' => [['depthLimit' => 0, 'maxLimit' => 100], 'depthLimit'];
        yield 'depthLimit -1' => [['depthLimit' => -1, 'maxLimit' => 100], 'depthLimit'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('unboundedLimits')]
    public function testWarnsForUnboundedOrInvalidBuiltInLimits(array $config, string $keys): void
    {
        $warning = (string) Bootstrap::getOperationLimitsWarning($config);

        self::assertStringContainsString("unbounded or invalid for: {$keys}.", $warning);
        self::assertStringContainsString(self::RECOMMENDATION, $warning);
        self::assertStringContainsString(self::CUSTOM_RULES_NOTE, $warning);
        self::assertStringContainsString(self::DOCUMENTATION_URL, $warning);
    }

    /** @return iterable<array{int}> */
    public static function boundedMaxLimits(): iterable
    {
        yield [1];
        yield [100];
        yield [9007199254740991];
    }

    #[DataProvider('boundedMaxLimits')]
    public function testDoesNotWarnWhenMaxLimitIsABoundedPositiveValue(int $maxLimit): void
    {
        self::assertNull(Bootstrap::getOperationLimitsWarning(['depthLimit' => 10, 'maxLimit' => $maxLimit]));
    }

    public function testStillWarnsWhenCustomApolloValidationRulesArePresent(): void
    {
        $configWithCustomRules = [
            'depthLimit' => null,
            'maxLimit' => -1,
            'apolloServer' => ['validationRules' => [static fn (): array => []]],
        ];
        $warning = (string) Bootstrap::getOperationLimitsWarning($configWithCustomRules);

        self::assertStringContainsString('depthLimit, maxLimit', $warning);
        self::assertStringContainsString(self::RECOMMENDATION, $warning);
        self::assertStringContainsString(self::CUSTOM_RULES_NOTE, $warning);
    }

    /**
     * @param array<string, mixed> $limits
     * @return list<string> the operation-limit warnings `bootstrap()` logged
     */
    private static function bootstrapWith(array $limits): array
    {
        $strapi = BootedApp::shared();
        $logger = $strapi->log();
        $previous = [
            'depthLimit' => $strapi->config()->get('plugin::graphql.depthLimit'),
            'maxLimit' => $strapi->config()->get('plugin::graphql.maxLimit'),
            'landingPage' => $strapi->config()->get('plugin::graphql.landingPage'),
        ];

        $records = BootedApp::recordLogs($strapi);
        try {
            $strapi->config()->set('plugin::graphql.depthLimit', $limits['depthLimit'] ?? null);
            $strapi->config()->set('plugin::graphql.maxLimit', $limits['maxLimit'] ?? null);
            $strapi->config()->set('plugin::graphql.landingPage', false);

            Bootstrap::bootstrap($strapi);
        } finally {
            foreach ($previous as $key => $value) {
                $strapi->config()->set("plugin::graphql.{$key}", $value);
            }
            $strapi->set('logger', $logger);
            self::destroyServer($strapi);
        }

        $warnings = [];
        foreach ($records as $record) {
            if ($record['level'] === 'warning') {
                $warnings[] = $record['message'];
            }
        }

        return $warnings;
    }

    private static function destroyServer(Strapi $strapi): void
    {
        Bootstrap::destroy($strapi);
    }

    public function testLogsOneWarningWhenBothBuiltInLimitsAreUnbounded(): void
    {
        $warnings = self::bootstrapWith(['depthLimit' => null, 'maxLimit' => -1]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('depthLimit, maxLimit', $warnings[0]);
    }

    public function testDoesNotLogAnOperationLimitWarningWhenBothBuiltInLimitsAreBounded(): void
    {
        $warnings = self::bootstrapWith(['depthLimit' => 10, 'maxLimit' => 100]);

        self::assertSame([], array_values(array_filter(
            $warnings,
            static fn (string $warning): bool => str_contains($warning, 'Built-in GraphQL operation limits'),
        )));
    }

    /** @return iterable<array{array<string, mixed>}> */
    public static function oneUnboundedLimit(): iterable
    {
        yield [['depthLimit' => null, 'maxLimit' => 100]];
        yield [['depthLimit' => 10, 'maxLimit' => 0]];
    }

    /** @param array<string, mixed> $limits */
    #[DataProvider('oneUnboundedLimit')]
    public function testLogsOneOperationLimitWarningWhenExactlyOneControlIsUnboundedOrInvalid(array $limits): void
    {
        $warnings = self::bootstrapWith($limits);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Built-in GraphQL operation limits', $warnings[0]);
    }
}

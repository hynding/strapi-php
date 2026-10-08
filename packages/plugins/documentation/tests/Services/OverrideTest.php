<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Documentation\Services\Override;
use Strapi\Plugin\Documentation\Tests\Mocks\StrapiMock;

/** Port of server/src/services/__tests__/override.test.ts. */
final class OverrideTest extends TestCase
{
    private StrapiMock $strapi;

    protected function setUp(): void
    {
        $this->strapi = new StrapiMock();
        $this->strapi->configGet = static fn (string $path): array => [
            'x-strapi-config' => [
                'plugins' => null,
            ],
        ];
    }

    public function testShouldRegisterAnOverride(): void
    {
        $mockOverride = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'My API',
                'version' => '1.0.0',
            ],
        ];

        $overrideService = new Override($this->strapi);
        $overrideService->registerOverride($mockOverride);

        self::assertSame([$mockOverride], $overrideService->registeredOverrides);
    }

    public function testShouldNotRegisterAnOverrideFromAPluginThatIsNotInTheConfig(): void
    {
        $mockOverride = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'My API',
                'version' => '1.0.0',
            ],
        ];

        $overrideService = new Override($this->strapi);
        $overrideService->registerOverride($mockOverride, ['pluginOrigin' => 'test']);

        self::assertSame([], $overrideService->registeredOverrides);
    }

    public function testShouldRegisterAnOverrideFromAPluginThatIsInTheConfigAndExcludeItFromGeneration(): void
    {
        $mockOverride = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'My API',
                'version' => '1.0.0',
            ],
        ];

        $this->strapi->configGet = static fn (string $path): array => [
            'x-strapi-config' => [
                'plugins' => ['test'],
            ],
        ];

        $overrideService = new Override($this->strapi);
        $overrideService->registerOverride($mockOverride, [
            'pluginOrigin' => 'test',
            'excludeFromGeneration' => ['test', 'some-other-api-to-exclude'],
        ]);

        self::assertSame([$mockOverride], $overrideService->registeredOverrides);
        self::assertSame(['test', 'some-other-api-to-exclude'], $overrideService->excludedFromGeneration);
    }

    public function testShouldRegisterAnApiOrPluginToExcludeFromGeneration(): void
    {
        $overrideService = new Override($this->strapi);
        $overrideService->excludeFromGeneration('my-api');
        $overrideService->excludeFromGeneration(['my-other-api', 'my-plugin', 'my-other-plugin']);

        self::assertSame([
            'my-api',
            'my-other-api',
            'my-plugin',
            'my-other-plugin',
        ], $overrideService->excludedFromGeneration);
    }

    /** PHP port: a YAML override (users-permissions' content-api.yaml) is parsed like the `yaml` package. */
    public function testParsesAYamlOverrideKeepingTimestampsAsStrings(): void
    {
        $overrideService = new Override($this->strapi);
        $overrideService->registerOverride(<<<'YAML'
paths:
  /test:
    get:
      responses:
        '200':
          description: OK
          content:
            application/json:
              example:
                createdAt: 2022-05-19T17:35:35.097Z
                list:
                  - 2022-05-19
                empty: {}
YAML);

        $override = $overrideService->registeredOverrides[0];
        $example = $override['paths']['/test']['get']['responses'][200]['content']['application/json']['example'];
        self::assertSame('2022-05-19T17:35:35.097Z', $example['createdAt']);
        self::assertSame(['2022-05-19'], $example['list']);
        self::assertEquals(new \stdClass(), $example['empty']);
    }
}

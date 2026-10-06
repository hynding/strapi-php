<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Registries;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Registries\Policies;

/** Port of packages/core/core/src/registries/__tests__/policies.test.ts. */
final class PoliciesTest extends TestCase
{
    public function testGetPolicy(): void
    {
        $registry = new Policies();

        self::assertNull($registry->get('undefined'));

        $policyFn = static fn (): bool => true;
        $registry->set('global::test-policy', $policyFn);
        self::assertSame($policyFn, $registry->get('global::test-policy'));

        $pluginFn = static fn (): bool => true;
        $registry->set('plugin::test-plugin.test-policy', $pluginFn);
        self::assertNull($registry->get('test-plugin.test-policy'));
        self::assertSame($pluginFn, $registry->get('plugin::test-plugin.test-policy'));
        self::assertSame($pluginFn, $registry->get('test-policy', ['pluginName' => 'test-plugin']));

        $apiFn = static fn (): bool => false;
        $registry->set('api::test-api.test-policy', $apiFn);
        self::assertSame($apiFn, $registry->get('test-policy', ['apiName' => 'test-api']));
    }

    public function testKeys(): void
    {
        $registry = new Policies();
        $registry->set('plugin::test-plugin.test-policy', static fn (): bool => true);

        self::assertSame(['plugin::test-plugin.test-policy'], $registry->keys());
    }

    public function testResolve(): void
    {
        $registry = new Policies();
        $fn = static fn (): bool => true;
        $registry->add('global::', ['deny' => $fn]);
        $registry->add('api::test-api', ['local' => $fn]);

        $resolved = $registry->resolve(['global::deny', ['name' => 'local', 'config' => ['x' => 1]], $fn], ['apiName' => 'test-api']);

        self::assertCount(3, $resolved);
        self::assertSame($fn, $resolved[0]['handler']);
        self::assertSame(['x' => 1], $resolved[1]['config']);
        self::assertSame($fn, $resolved[2]['handler']);
    }

    public function testResolveUnknownPolicyThrows(): void
    {
        $registry = new Policies();

        $this->expectException(\RuntimeException::class);
        $registry->resolve(['global::missing']);
    }
}

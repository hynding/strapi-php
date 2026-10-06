<?php

declare(strict_types=1);

namespace Strapi\Database\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Lifecycles\Event;
use Strapi\Database\Tests\Support\GetstartedDatabase;
use Strapi\Database\Utils\SchemaFactory;

/** Port of __tests__/lifecycles.test.ts. */
final class LifecyclesTest extends TestCase
{
    public function testFunctionAndMapSubscribersWithStateAndModels(): void
    {
        $db = GetstartedDatabase::create();
        $calls = [];

        $db->lifecycles->subscribe(function (Event $event) use (&$calls): void {
            $calls[] = 'fn:' . $event->action . ':' . $event->model['uid'];
            $event->state['n'] = ($event->state['n'] ?? 0) + 1;
        });
        $db->lifecycles->subscribe([
            'models' => ['api::tag.tag'],
            'beforeCreate' => function (Event $event) use (&$calls): void {
                $calls[] = 'map:' . $event->action;
            },
        ]);

        $props = ['params' => ['data' => ['name' => 'x']]];
        $states = $db->lifecycles->run('beforeCreate', 'api::tag.tag', $props);
        self::assertArrayHasKey('createdAt', $props['params']['data'], 'timestamps subscriber ran');
        self::assertSame(['fn:beforeCreate:api::tag.tag', 'map:beforeCreate'], $calls);

        $props['result'] = ['id' => 1];
        $db->lifecycles->run('afterCreate', 'api::tag.tag', $props, $states);
        self::assertSame(['fn:beforeCreate:api::tag.tag', 'map:beforeCreate', 'fn:afterCreate:api::tag.tag'], $calls);

        $db->lifecycles->run('beforeCreate', 'api::article.article', $props);
        self::assertCount(4, $calls, 'the map subscriber is restricted to its models');

        $db->lifecycles->disable();
        $db->lifecycles->run('beforeCreate', 'api::tag.tag', $props);
        self::assertCount(4, $calls);
        $db->lifecycles->enable();

        $db->lifecycles->clear();
        $db->lifecycles->run('beforeCreate', 'api::tag.tag', $props);
        self::assertCount(4, $calls);
    }

    public function testModelLifecyclesFromSchemaConfig(): void
    {
        $seen = [];
        $schema = SchemaFactory::contentType([
            'collectionName' => 'things',
            'info' => ['singularName' => 'thing', 'pluralName' => 'things'],
            'attributes' => ['name' => ['type' => 'string']],
        ], 'api::thing.thing', [
            'beforeCreate' => function (Event $event) use (&$seen): void {
                $seen[] = 'beforeCreate';
                $event->params['data']['name'] = strtoupper((string) $event->params['data']['name']);
            },
            'afterCreate' => function (Event $event) use (&$seen): void {
                $seen[] = 'afterCreate:' . $event->result['name'];
            },
        ]);

        $db = GetstartedDatabase::create([], ['api::thing.thing' => $schema] + SchemaFactory::builtinSchemas());
        $db->schema->sync();

        $thing = $db->query('api::thing.thing')->create(['data' => ['name' => 'abc']]);
        self::assertSame('ABC', $thing['name']);
        self::assertSame(['beforeCreate', 'afterCreate:ABC'], $seen);
    }
}

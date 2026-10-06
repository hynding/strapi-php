<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

/**
 * Shared fixtures for visitor tests (port of __tests__/test-fixtures.ts).
 *
 * @phpstan-type Model array<string, mixed>
 */
final class TestFixtures
{
    /** @var Model */
    public const ADMIN_USER_MODEL = [
        'uid' => 'admin::user',
        'modelType' => 'contentType',
        'kind' => 'collectionType',
        'info' => ['singularName' => 'user', 'pluralName' => 'users', 'displayName' => 'User'],
        'options' => [],
        'attributes' => [
            'id' => ['type' => 'integer'],
            'firstname' => ['type' => 'string'],
            'lastname' => ['type' => 'string'],
            'email' => ['type' => 'email', 'private' => true],
            'password' => ['type' => 'password', 'private' => true],
            'resetPasswordToken' => ['type' => 'string', 'private' => true],
            'registrationToken' => ['type' => 'string', 'private' => true],
            'isActive' => ['type' => 'boolean', 'private' => true],
            'blocked' => ['type' => 'boolean', 'private' => true],
        ],
    ];

    /** @var Model */
    public const ARTICLE_MODEL = [
        'uid' => 'api::article.article',
        'modelType' => 'contentType',
        'kind' => 'collectionType',
        'info' => ['singularName' => 'article', 'pluralName' => 'articles', 'displayName' => 'Article'],
        'options' => [],
        'attributes' => [
            'id' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'createdBy' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'],
            'updatedBy' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user'],
        ],
    ];

    /** @var array<string, Model> */
    public const MODELS = [
        'admin::user' => self::ADMIN_USER_MODEL,
        'api::article.article' => self::ARTICLE_MODEL,
    ];

    /** @return Model|null */
    public static function getModel(string $uid): ?array
    {
        return self::MODELS[$uid] ?? null;
    }

    /** @return \Closure(string): (Model|null) */
    public static function getModelFn(): \Closure
    {
        return static fn (string $uid): ?array => self::getModel($uid);
    }
}

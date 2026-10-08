<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Mocks;

/**
 * `contentType: () => ({ info: {}, attributes: { test: { type: 'string' } } })` of the upstream mock,
 * delegating everything else to the mocked instance.
 */
final class ContentTypeOverride
{
    public function __construct(private readonly StrapiMock $strapi)
    {
    }

    public function contentType(string $uid): \Strapi\Types\Schema\Schema
    {
        return StrapiMock::schema($uid, ['info' => [], 'attributes' => ['test' => ['type' => 'string']]]);
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return $this->strapi->{$name}(...$args);
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Types\Modules\CoreStore;

/** strapi.store — key/value settings in the strapi_core_store_settings table. */
interface CoreStore
{
    /** @param array{key: string, type?: string, environment?: string|null, name?: string|null, tag?: string|null} $params */
    public function get(array $params): mixed;

    /** @param array{key: string, value: mixed, type?: string, environment?: string|null, name?: string|null, tag?: string|null} $params */
    public function set(array $params): void;

    /** @param array{key: string, type?: string, environment?: string|null, name?: string|null, tag?: string|null} $params */
    public function delete(array $params): void;
}

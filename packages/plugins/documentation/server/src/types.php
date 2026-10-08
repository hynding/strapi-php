<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation;

/**
 * Port of server/src/types.ts.
 *
 * `PluginConfig` is the OpenAPI 3.0 document the plugin's config describes, plus `x-strapi-config`
 * (`plugins`: the plugins to document, `mutateDocumentation`: a callable receiving the draft
 * document, by reference or returning the new one).
 *
 * @phpstan-type Config array{restrictedAccess: bool, password?: string}
 * @phpstan-type PluginConfig array<string, mixed>
 * @phpstan-type ApiInfo array{name: string, getter: string, ctNames: list<string>, routeInfo: array<string, mixed>, attributes: array<string, array<string, mixed>>, uniqueName: string, contentTypeInfo: array<string, mixed>, kind: string|null}
 * @phpstan-type Api array{getter: string, name: string, ctNames: list<string>}
 */
final class Types
{
}

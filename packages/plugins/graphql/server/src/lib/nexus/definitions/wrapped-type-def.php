<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Definitions;

/**
 * nexus `nonNull(type)`, `nullable(type)`, `list(type)`: `$kind` is `NonNull`, `Null` or `List`,
 * `$ofType` a type name, a named type def, another wrapper, an `ArgDef` or a graphql type.
 */
final class WrappedTypeDef
{
    public const string NON_NULL = 'NonNull';

    public const string NULL = 'Null';

    public const string LIST = 'List';

    public function __construct(public readonly string $kind, public readonly mixed $ofType)
    {
    }
}

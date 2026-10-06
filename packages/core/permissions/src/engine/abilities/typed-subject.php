<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Abilities;

/** A non-stdClass object tagged with a subject type by {@see Subject::subject()}. */
final class TypedSubject
{
    public function __construct(public readonly string $type, public readonly object $object)
    {
    }
}

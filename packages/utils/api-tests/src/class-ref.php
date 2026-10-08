<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Core\Strapi;

/** A class reached from a bridge chain (`{"class": ...}`); see {@see Bridge}. */
final readonly class ClassRef
{
    /** @param class-string $class */
    public function __construct(public string $class, private Strapi $strapi)
    {
    }

    /** @param list<mixed> $args */
    public function call(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($this->class, $method);
        if (!$reflection->isPublic()) {
            throw new \BadMethodCallException("{$this->class}::{$method} is not public");
        }
        if ($reflection->isStatic()) {
            return $reflection->invokeArgs(null, $args);
        }

        return $reflection->invokeArgs(new ($this->class)($this->strapi), $args);
    }
}

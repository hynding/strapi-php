<?php

declare(strict_types=1);

namespace Strapi\Types\Schema\Attribute;

/** Relation kinds as written in schema.json ("relation": "oneToMany"). */
enum RelationKind: string
{
    case OneToOne = 'oneToOne';
    case OneToMany = 'oneToMany';
    case ManyToOne = 'manyToOne';
    case ManyToMany = 'manyToMany';
    case MorphOne = 'morphOne';
    case MorphMany = 'morphMany';
    case MorphToOne = 'morphToOne';
    case MorphToMany = 'morphToMany';

    public function isMorph(): bool
    {
        return str_starts_with($this->value, 'morph');
    }

    public function isToMany(): bool
    {
        return in_array($this, [self::OneToMany, self::ManyToMany, self::MorphMany, self::MorphToMany], true);
    }

    public function isToOne(): bool
    {
        return !$this->isToMany();
    }
}

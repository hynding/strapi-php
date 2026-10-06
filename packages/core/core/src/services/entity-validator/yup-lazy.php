<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** `yup.lazy(value => schema)` */
final class YupLazy extends Yup
{
    /** @var \Closure(mixed): Yup */
    private \Closure $resolver;

    /** @param callable(mixed): Yup $resolver */
    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver(...);
    }

    public function resolve(mixed $value): Yup
    {
        $schema = ($this->resolver)($value instanceof Undefined ? null : $value);
        if ($this->tests !== [] || $this->nullable || !$this->optional) {
            $schema = $schema->concat($this);
        }

        return $schema;
    }
}

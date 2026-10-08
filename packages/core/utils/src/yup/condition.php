<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/** yup's `Condition` behind `schema.when()`. */
final class Condition
{
    /** @var \Closure(mixed...): (Yup|null) */
    private \Closure $fn;

    /**
     * @param list<Reference> $refs
     * @param array<string, mixed>|\Closure $options `['is' => value|fn(...values): bool, 'then' => Yup|fn(Yup): Yup, 'otherwise' => ...]` or `fn (...values, Yup $schema, array $options): ?Yup`
     */
    public function __construct(public readonly array $refs, array|\Closure $options)
    {
        if ($options instanceof \Closure) {
            $this->fn = $options;

            return;
        }
        if (!array_key_exists('is', $options)) {
            throw new \TypeError('`is:` is required for `when()` conditions');
        }
        $then = $options['then'] ?? null;
        $otherwise = $options['otherwise'] ?? null;
        if ($then === null && $otherwise === null) {
            throw new \TypeError('either `then:` or `otherwise:` is required for `when()` conditions');
        }
        $is = $options['is'];
        $check = $is instanceof \Closure
            ? $is
            : static function (mixed ...$values) use ($is): bool {
                foreach ($values as $value) {
                    if (!Yup::sameValue($value, $is)) {
                        return false;
                    }
                }

                return true;
            };

        $this->fn = static function (mixed ...$args) use ($check, $then, $otherwise): ?Yup {
            /** @var array<string, mixed> $resolveOptions */
            $resolveOptions = array_pop($args);
            /** @var Yup $schema */
            $schema = array_pop($args);
            $branch = Yup::truthy($check(...$args)) ? $then : $otherwise;
            if ($branch === null) {
                return null;
            }
            if ($branch instanceof \Closure) {
                $result = $branch($schema);

                return $result instanceof Yup ? $result : null;
            }
            if (!$branch instanceof Yup) {
                throw new \TypeError('conditions must return a schema object');
            }

            return $schema->concat($branch->resolve($resolveOptions));
        };
    }

    /** @param array<string, mixed> $options */
    public function resolve(Yup $base, array $options): Yup
    {
        $values = array_map(
            static fn (Reference $ref): mixed => $ref->getValue(
                array_key_exists('value', $options) ? $options['value'] : Undefined::value(),
                array_key_exists('parent', $options) ? $options['parent'] : Undefined::value(),
                array_key_exists('context', $options) ? $options['context'] : Undefined::value(),
            ),
            $this->refs,
        );
        $schema = ($this->fn)(...[...$values, $base, $options]);
        if ($schema === null || $schema === $base) {
            return $base;
        }
        if (!$schema instanceof Yup) {
            throw new \TypeError('conditions must return a schema object');
        }

        return $schema->resolve($options);
    }
}

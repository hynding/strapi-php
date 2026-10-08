<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

use Strapi\Utils\EmptyObject;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.object(shape)`. Input: a non-list array, `[]` (as `{}`) or any object (`stdClass`, read
 * through its public properties). Output: an associative array with the shape's keys first, in
 * shape order, without keys whose value is `undefined`.
 *
 * Unknown keys are stripped by default, rejected by `strict()` (`unrecognized_keys`), and kept by
 * `passthrough()` / `loose()` / `z.looseObject()`; `catchall(schema)` parses them with a schema.
 */
class ZodObject extends ZodType
{
    /**
     * @param array<string, ZodType> $shape
     * @param string|array<string, mixed>|null $params
     */
    public function __construct(protected array $shape = [], string|array|null $params = null, protected ?ZodType $catchall = null)
    {
        foreach ($shape as $key => $schema) {
            if (!$schema instanceof ZodType) {
                throw new \InvalidArgumentException("Invalid element at key \"{$key}\": expected a Zod schema");
            }
        }
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'object';
    }

    /**
     * `schema.shape`
     *
     * @return array<string, ZodType>
     */
    public function shape(): array
    {
        return $this->shape;
    }

    public function getCatchall(): ?ZodType
    {
        return $this->catchall;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['shape' => $this->shape, 'catchall' => $this->catchall];
    }

    /** @return array<string, list<mixed>> */
    public function propValues(): array
    {
        $propValues = [];
        foreach ($this->shape as $key => $schema) {
            $values = $schema->values();
            if ($values !== null) {
                $propValues[(string) $key] = $values;
            }
        }

        return $propValues;
    }

    // --- unknown keys --------------------------------------------------------------------------

    public function strict(): static
    {
        return $this->catchall(new ZodNever());
    }

    public function passthrough(): static
    {
        return $this->catchall(new ZodUnknown());
    }

    public function loose(): static
    {
        return $this->catchall(new ZodUnknown());
    }

    public function strip(): static
    {
        $clone = clone $this;
        $clone->catchall = null;

        return $clone;
    }

    public function catchall(ZodType $catchall): static
    {
        $clone = clone $this;
        $clone->catchall = $catchall;

        return $clone;
    }

    // --- shape manipulation --------------------------------------------------------------------

    /** @param array<string, ZodType> $shape */
    public function extend(array $shape): static
    {
        if ($this->checks !== []) {
            foreach (array_keys($shape) as $key) {
                if (array_key_exists($key, $this->shape)) {
                    throw new \LogicException('Cannot overwrite keys on object schemas containing refinements. Use `.safeExtend()` instead.');
                }
            }
        }

        return $this->safeExtend($shape);
    }

    /** @param array<string, ZodType> $shape */
    public function safeExtend(array $shape): static
    {
        $clone = clone $this;
        $clone->shape = array_merge($this->shape, $shape);

        return $clone;
    }

    public function merge(ZodObject $other): static
    {
        if ($this->checks !== []) {
            throw new \LogicException('.merge() cannot be used on object schemas containing refinements. Use .safeExtend() instead.');
        }
        $clone = clone $this;
        $clone->shape = array_merge($this->shape, $other->shape);
        $clone->catchall = $other->catchall;
        $clone->checks = $other->checks;

        return $clone;
    }

    /** @param array<string, bool>|list<string> $mask `['a' => true]` as in zod, or `['a']` */
    public function pick(array $mask): static
    {
        if ($this->checks !== []) {
            throw new \LogicException('.pick() cannot be used on object schemas containing refinements');
        }
        $keys = self::maskKeys($mask);
        $clone = clone $this;
        $clone->shape = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->shape)) {
                throw new \InvalidArgumentException("Unrecognized key: \"{$key}\"");
            }
            $clone->shape[$key] = $this->shape[$key];
        }

        return $clone;
    }

    /** @param array<string, bool>|list<string> $mask */
    public function omit(array $mask): static
    {
        if ($this->checks !== []) {
            throw new \LogicException('.omit() cannot be used on object schemas containing refinements');
        }
        $keys = self::maskKeys($mask);
        $clone = clone $this;
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->shape)) {
                throw new \InvalidArgumentException("Unrecognized key: \"{$key}\"");
            }
            unset($clone->shape[$key]);
        }

        return $clone;
    }

    /** @param array<string, bool>|list<string>|null $mask */
    public function partial(?array $mask = null): static
    {
        if ($this->checks !== []) {
            throw new \LogicException('.partial() cannot be used on object schemas containing refinements');
        }
        $clone = clone $this;
        foreach ($mask === null ? array_keys($this->shape) : self::maskKeys($mask) as $key) {
            if (!array_key_exists($key, $this->shape)) {
                throw new \InvalidArgumentException("Unrecognized key: \"{$key}\"");
            }
            $clone->shape[$key] = new ZodOptional($this->shape[$key]);
        }

        return $clone;
    }

    /** @param array<string, bool>|list<string>|null $mask */
    public function required(?array $mask = null): static
    {
        $clone = clone $this;
        foreach ($mask === null ? array_keys($this->shape) : self::maskKeys($mask) as $key) {
            if (!array_key_exists($key, $this->shape)) {
                throw new \InvalidArgumentException("Unrecognized key: \"{$key}\"");
            }
            $clone->shape[$key] = new ZodNonOptional($this->shape[$key]);
        }

        return $clone;
    }

    public function keyof(): ZodEnum
    {
        return new ZodEnum(array_map(strval(...), array_keys($this->shape)));
    }

    /**
     * @param array<string, bool>|list<string> $mask
     *
     * @return list<string>
     */
    private static function maskKeys(array $mask): array
    {
        if (array_is_list($mask)) {
            return array_map(strval(...), $mask);
        }

        return array_map(strval(...), array_keys(array_filter($mask)));
    }

    // --- parsing -------------------------------------------------------------------------------

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $input = $payload->value;
        if (!Util::isObject($input)) {
            return $this->issue($payload, ['expected' => 'object', 'code' => 'invalid_type']);
        }
        $entries = Util::entries($input);
        $output = [];
        foreach ($this->shape as $key => $schema) {
            $key = (string) $key;
            $present = array_key_exists($key, $entries);
            $result = $schema->run(new ParsePayload($present ? $entries[$key] : Undefined::Value));
            self::handleProperty($result, $payload, $output, $key, $present, $schema->isOptionalIn(), $schema->isOptionalOut());
        }
        if ($this->catchall !== null) {
            $unrecognized = [];
            $isNever = $this->catchall instanceof ZodNever;
            foreach ($entries as $key => $value) {
                $key = (string) $key;
                if (array_key_exists($key, $this->shape)) {
                    continue;
                }
                if ($isNever) {
                    $unrecognized[] = $key;
                    continue;
                }
                $result = $this->catchall->run(new ParsePayload($value));
                self::handleProperty($result, $payload, $output, $key, true, $this->catchall->isOptionalIn(), $this->catchall->isOptionalOut());
            }
            if ($unrecognized !== []) {
                $payload->issues[] = ['code' => 'unrecognized_keys', 'keys' => $unrecognized, 'input' => $input, 'inst' => $this];
            }
        }
        // a JSON `{}` (EmptyObject) parsed to nothing stays one
        $payload->value = $output === [] && $input instanceof EmptyObject ? $input : $output;

        return $payload;
    }

    /** @param array<string, mixed> $output */
    private static function handleProperty(ParsePayload $result, ParsePayload $final, array &$output, string $key, bool $present, bool $optionalIn, bool $optionalOut): void
    {
        if ($result->issues !== []) {
            if ($optionalIn && $optionalOut && !$present) {
                return;
            }
            array_push($final->issues, ...Util::prefixIssues($key, $result->issues));
        }
        if (!$present && !$optionalIn) {
            if ($result->issues === []) {
                $final->issues[] = ['code' => 'invalid_type', 'expected' => 'nonoptional', 'input' => Undefined::Value, 'path' => [$key]];
            }

            return;
        }
        if ($result->value !== Undefined::Value) {
            $output[$key] = $result->value;
        }
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.enum(['a', 'b'])`, also `z.enum(['A' => 'a'])` (zod's object form / `z.nativeEnum`) and
 * `z.enum(SomeBackedEnum::class)`, which accepts the enum's backing values.
 */
class ZodEnum extends ZodType
{
    /** @var array<string, string|int> */
    protected array $entries;

    /**
     * @param list<string|int>|array<string, string|int>|class-string<\BackedEnum> $values
     * @param string|array<string, mixed>|null $params
     */
    public function __construct(array|string $values, string|array|null $params = null)
    {
        if (is_string($values)) {
            $entries = [];
            foreach ($values::cases() as $case) {
                $entries[$case->name] = $case->value;
            }
        } elseif (array_is_list($values)) {
            $entries = [];
            foreach ($values as $v) {
                $entries[(string) $v] = $v;
            }
        } else {
            $entries = $values;
        }
        /** @var array<string, string|int> $entries */
        $this->entries = $entries;
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'enum';
    }

    /** @return list<string|int> */
    public function values(): array
    {
        return array_values($this->entries);
    }

    /**
     * `schema.options`
     *
     * @return list<string|int>
     */
    public function options(): array
    {
        return array_values($this->entries);
    }

    /**
     * `schema.enum`
     *
     * @return array<string, string|int>
     */
    public function enum(): array
    {
        return $this->entries;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['entries' => $this->entries];
    }

    /**
     * @param list<string|int> $values
     * @param string|array<string, mixed>|null $params
     */
    public function extract(array $values, string|array|null $params = null): self
    {
        return new self(array_values(array_filter($this->values(), static fn ($v): bool => Util::contains($values, $v))), $params);
    }

    /**
     * @param list<string|int> $values
     * @param string|array<string, mixed>|null $params
     */
    public function exclude(array $values, string|array|null $params = null): self
    {
        return new self(array_values(array_filter($this->values(), static fn ($v): bool => !Util::contains($values, $v))), $params);
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $values = $this->values();
        if (Util::contains($values, $payload->value)) {
            return $payload;
        }

        return $this->issue($payload, ['code' => 'invalid_value', 'values' => $values]);
    }
}

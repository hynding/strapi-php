<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.discriminatedUnion('type', [...])`: picks the option whose literal value for the
 * discriminator key matches, and reports `invalid_union` with `note: "No matching discriminator"`
 * at the discriminator's path otherwise.
 */
class ZodDiscriminatedUnion extends ZodUnion
{
    /** @var list<array{mixed, ZodType}>|null */
    private ?array $map = null;

    /**
     * @param list<ZodType> $options
     * @param string|array<string, mixed>|null $params
     */
    public function __construct(protected string $discriminator, array $options, string|array|null $params = null)
    {
        parent::__construct($options, $params);
    }

    public function discriminator(): string
    {
        return $this->discriminator;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['discriminator' => $this->discriminator];
    }

    /** @return array<string, list<mixed>> */
    public function propValues(): array
    {
        $propValues = [];
        foreach ($this->options as $index => $option) {
            $optionValues = $option->propValues();
            if ($optionValues === null || $optionValues === []) {
                throw new \LogicException("Invalid discriminated union option at index \"{$index}\"");
            }
            foreach ($optionValues as $key => $values) {
                foreach ($values as $value) {
                    if (!Util::contains($propValues[$key] ?? [], $value)) {
                        $propValues[$key][] = $value;
                    }
                }
            }
        }

        return $propValues;
    }

    /** @return list<array{mixed, ZodType}> */
    private function discriminatorMap(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        $map = [];
        foreach ($this->options as $index => $option) {
            $values = $option->propValues()[$this->discriminator] ?? [];
            if ($values === []) {
                throw new \LogicException("Invalid discriminated union option at index \"{$index}\"");
            }
            foreach ($values as $value) {
                foreach ($map as [$existing]) {
                    if (Util::same($existing, $value)) {
                        throw new \LogicException('Duplicate discriminator value "' . Util::jsString($value) . '"');
                    }
                }
                $map[] = [$value, $option];
            }
        }

        return $this->map = $map;
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $input = $payload->value;
        if (!Util::isObject($input)) {
            return $this->issue($payload, ['code' => 'invalid_type', 'expected' => 'object']);
        }
        $entries = Util::entries($input);
        $value = array_key_exists($this->discriminator, $entries) ? $entries[$this->discriminator] : Undefined::Value;
        $map = $this->discriminatorMap();
        foreach ($map as [$candidate, $option]) {
            if (Util::same($candidate, $value)) {
                return $option->run($payload);
            }
        }

        return $this->issue($payload, [
            'code' => 'invalid_union',
            'errors' => [],
            'note' => 'No matching discriminator',
            'discriminator' => $this->discriminator,
            'options' => array_map(static fn (array $entry): mixed => $entry[0], $map),
            'input' => $input,
            'path' => [$this->discriminator],
        ]);
    }
}

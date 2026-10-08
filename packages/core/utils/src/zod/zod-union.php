<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.union([a, b])` / `a.or(b)`: the first option that parses wins. When none does, a single
 * option that failed only with non-aborting issues (e.g. a too-short string) reports its own
 * issues; otherwise an `invalid_union` issue carries every option's issues in `errors`.
 */
class ZodUnion extends ZodType
{
    /**
     * @param list<ZodType> $options
     * @param string|array<string, mixed>|null $params
     */
    public function __construct(protected array $options, string|array|null $params = null)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'union';
    }

    /**
     * `schema.options`
     *
     * @return list<ZodType>
     */
    public function options(): array
    {
        return $this->options;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['options' => $this->options];
    }

    public function isOptionalIn(): bool
    {
        foreach ($this->options as $option) {
            if ($option->isOptionalIn()) {
                return true;
            }
        }

        return false;
    }

    public function isOptionalOut(): bool
    {
        foreach ($this->options as $option) {
            if ($option->isOptionalOut()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<mixed>|null */
    public function values(): ?array
    {
        $values = [];
        foreach ($this->options as $option) {
            $optionValues = $option->values();
            if ($optionValues === null) {
                return null;
            }
            array_push($values, ...$optionValues);
        }

        return $values;
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if (count($this->options) === 1) {
            return $this->options[0]->run($payload);
        }
        $results = [];
        foreach ($this->options as $option) {
            $result = $option->run(new ParsePayload($payload->value));
            if ($result->issues === []) {
                return $result;
            }
            $results[] = $result;
        }
        $nonAborted = array_values(array_filter($results, static fn (ParsePayload $r): bool => !$r->isAborted()));
        if (count($nonAborted) === 1) {
            return $nonAborted[0];
        }

        return $this->issue($payload, [
            'code' => 'invalid_union',
            'input' => $payload->value,
            'errors' => array_map(
                static fn (ParsePayload $r): array => array_map(Util::finalizeIssue(...), $r->issues),
                $results,
            ),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.tuple([a, b], rest?)`: a PHP list with positional schemas.
 */
class ZodTuple extends ZodType
{
    /**
     * @param list<ZodType> $items
     * @param string|array<string, mixed>|null $params
     */
    public function __construct(protected array $items, protected ?ZodType $rest = null, string|array|null $params = null)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'tuple';
    }

    /** @return list<ZodType> */
    public function items(): array
    {
        return $this->items;
    }

    public function getRest(): ?ZodType
    {
        return $this->rest;
    }

    public function rest(ZodType $rest): static
    {
        $clone = clone $this;
        $clone->rest = $rest;

        return $clone;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['items' => $this->items, 'rest' => $this->rest];
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $input = $payload->value;
        if (!is_array($input) || !array_is_list($input)) {
            return $this->issue($payload, ['expected' => 'tuple', 'code' => 'invalid_type']);
        }
        $count = count($this->items);
        $optinStart = $this->optStart(true);
        $optoutStart = $this->optStart(false);
        if ($this->rest === null) {
            if (count($input) < $optinStart) {
                return $this->issue($payload, ['code' => 'too_small', 'minimum' => $optinStart, 'inclusive' => true, 'origin' => 'array']);
            }
            if (count($input) > $count) {
                $this->issue($payload, ['code' => 'too_big', 'maximum' => $count, 'inclusive' => true, 'origin' => 'array']);
            }
        }
        $results = [];
        foreach ($this->items as $i => $item) {
            $results[$i] = $item->run(new ParsePayload(array_key_exists($i, $input) ? $input[$i] : Undefined::Value));
        }
        $output = [];
        $restIssues = [];
        if ($this->rest !== null) {
            for ($i = $count, $n = count($input); $i < $n; $i++) {
                $result = $this->rest->run(new ParsePayload($input[$i]));
                if ($result->issues !== []) {
                    array_push($restIssues, ...Util::prefixIssues($i, $result->issues));
                }
                $output[$i] = Util::output($result->value);
            }
        }
        array_push($payload->issues, ...$restIssues);
        $values = [];
        foreach ($this->items as $i => $item) {
            $r = $results[$i];
            $present = $i < count($input);
            if ($r->issues !== []) {
                if (!$present && $i >= $optoutStart) {
                    break;
                }
                array_push($payload->issues, ...Util::prefixIssues($i, $r->issues));
            }
            $values[$i] = $r->value;
        }
        for ($i = count($values) - 1; $i >= count($input); $i--) {
            if ($this->items[$i]->isOptionalOut() && $values[$i] === Undefined::Value) {
                unset($values[$i]);
            } else {
                break;
            }
        }
        $final = array_map(Util::output(...), $values);
        foreach ($output as $i => $v) {
            $final[$i] = $v;
        }
        ksort($final);
        $payload->value = array_values($final);

        return $payload;
    }

    private function optStart(bool $in): int
    {
        for ($i = count($this->items) - 1; $i >= 0; $i--) {
            if (!($in ? $this->items[$i]->isOptionalIn() : $this->items[$i]->isOptionalOut())) {
                return $i + 1;
            }
        }

        return 0;
    }
}

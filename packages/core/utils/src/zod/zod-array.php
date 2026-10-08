<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.array(element)` / `schema.array()`: a PHP list (`[]` included). A non-list array is a
 * JS object and is rejected (`expected: "array", received object`).
 */
class ZodArray extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(protected ZodType $element, string|array|null $params = null)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'array';
    }

    /** `schema.element` */
    public function element(): ZodType
    {
        return $this->element;
    }

    public function unwrap(): ZodType
    {
        return $this->element;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['element' => $this->element];
    }

    /** @param string|array<string, mixed>|null $params */
    public function min(int $minLength, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::minLength($minLength, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function max(int $maxLength, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::maxLength($maxLength, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function length(int $length, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::length($length, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function nonempty(string|array|null $params = null): static
    {
        return $this->check(ZodCheck::minLength(1, $params));
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $input = $payload->value;
        if (!is_array($input) || !array_is_list($input)) {
            return $this->issue($payload, ['expected' => 'array', 'code' => 'invalid_type']);
        }
        $output = [];
        foreach ($input as $i => $item) {
            $result = $this->element->run(new ParsePayload($item));
            if ($result->issues !== []) {
                array_push($payload->issues, ...Util::prefixIssues($i, $result->issues));
            }
            $output[] = Util::output($result->value);
        }
        $payload->value = $output;

        return $payload;
    }
}

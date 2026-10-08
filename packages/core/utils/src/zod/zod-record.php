<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

use Strapi\Utils\EmptyObject;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.record(keySchema, valueSchema)`. Input: a non-list array, `[]` or a `stdClass`. Keys are
 * validated as strings (numeric keys are retried as numbers, as in zod). When the key schema has a
 * finite set of values (`z.enum`, `z.literal`), every value is required and other keys are
 * `unrecognized_keys`; `z.partialRecord()` drops that exhaustiveness and `z.looseRecord()` passes
 * keys that fail the key schema through unchanged.
 */
class ZodRecord extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(
        protected ZodType $keyType,
        protected ZodType $valueType,
        string|array|null $params = null,
        protected bool $partial = false,
        protected bool $loose = false,
    ) {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'record';
    }

    public function keyType(): ZodType
    {
        return $this->keyType;
    }

    public function valueType(): ZodType
    {
        return $this->valueType;
    }

    public function isPartial(): bool
    {
        return $this->partial;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['keyType' => $this->keyType, 'valueType' => $this->valueType, 'mode' => $this->loose ? 'loose' : 'strict'];
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $input = $payload->value;
        if (!Util::isObject($input)) {
            return $this->issue($payload, ['expected' => 'record', 'code' => 'invalid_type']);
        }
        $entries = Util::entries($input);
        $output = [];
        $values = $this->partial ? null : $this->keyType->values();

        if ($values !== null) {
            $recordKeys = [];
            foreach ($values as $key) {
                if (!is_string($key) && !is_int($key) && !is_float($key)) {
                    continue;
                }
                $keyString = is_string($key) ? $key : Util::numberToString($key);
                $recordKeys[$keyString] = true;
                $keyResult = $this->keyType->run(new ParsePayload($key));
                if ($keyResult->issues !== []) {
                    $payload->issues[] = $this->invalidKey($keyResult, $key);
                    continue;
                }
                $present = array_key_exists($keyString, $entries);
                $result = $this->valueType->run(new ParsePayload($present ? $entries[$keyString] : Undefined::Value));
                if ($result->issues !== []) {
                    array_push($payload->issues, ...Util::prefixIssues($keyString, $result->issues));
                }
                if ($result->value !== Undefined::Value) {
                    $output[Util::jsString($keyResult->value)] = $result->value;
                }
            }
            $unrecognized = [];
            foreach (array_keys($entries) as $key) {
                if (!isset($recordKeys[(string) $key])) {
                    $unrecognized[] = (string) $key;
                }
            }
            if ($unrecognized !== []) {
                $payload->issues[] = ['code' => 'unrecognized_keys', 'input' => $input, 'inst' => $this, 'keys' => $unrecognized];
            }
            $payload->value = $output;

            return $payload;
        }

        foreach ($entries as $key => $value) {
            $key = (string) $key;
            $keyResult = $this->keyType->run(new ParsePayload($key));
            if ($keyResult->issues !== [] && preg_match('/^-?\d+(?:\.\d+)?$/', $key) === 1) {
                $retry = $this->keyType->run(new ParsePayload(Util::jsNumber($key)));
                if ($retry->issues === []) {
                    $keyResult = $retry;
                }
            }
            if ($keyResult->issues !== []) {
                if ($this->loose) {
                    $output[$key] = $value;
                } else {
                    $payload->issues[] = $this->invalidKey($keyResult, $key);
                }
                continue;
            }
            $result = $this->valueType->run(new ParsePayload($value));
            if ($result->issues !== []) {
                array_push($payload->issues, ...Util::prefixIssues($key, $result->issues));
            }
            if ($result->value !== Undefined::Value) {
                $output[Util::jsString($keyResult->value)] = $result->value;
            }
        }
        // a JSON `{}` (EmptyObject) stays one
        $payload->value = $output === [] && $input instanceof EmptyObject ? $input : $output;

        return $payload;
    }

    /** @return array<string, mixed> */
    private function invalidKey(ParsePayload $keyResult, string|int|float $key): array
    {
        return [
            'code' => 'invalid_key',
            'origin' => 'record',
            'issues' => array_map(Util::finalizeIssue(...), $keyResult->issues),
            'input' => $key,
            'path' => [is_float($key) ? Util::numberToString($key) : $key],
            'inst' => $this,
        ];
    }
}

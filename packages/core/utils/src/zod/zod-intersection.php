<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.intersection(a, b)` / `a.and(b)`: both must parse; object results are merged.
 */
class ZodIntersection extends ZodType
{
    public function __construct(protected ZodType $left, protected ZodType $right)
    {
    }

    public function type(): string
    {
        return 'intersection';
    }

    public function left(): ZodType
    {
        return $this->left;
    }

    public function right(): ZodType
    {
        return $this->right;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['left' => $this->left, 'right' => $this->right];
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $left = $this->left->run(new ParsePayload($payload->value));
        $right = $this->right->run(new ParsePayload($payload->value));

        $unrecognized = [];
        $unrecognizedIssue = null;
        foreach ([[$left, 'l'], [$right, 'r']] as [$side, $flag]) {
            foreach ($side->issues as $issue) {
                if (($issue['code'] ?? null) === 'unrecognized_keys') {
                    if ($flag === 'l') {
                        $unrecognizedIssue ??= $issue;
                    }
                    /** @var list<string> $keys */
                    $keys = $issue['keys'] ?? [];
                    foreach ($keys as $key) {
                        $unrecognized[$key][$flag] = true;
                    }
                } else {
                    $payload->issues[] = $issue;
                }
            }
        }
        $both = [];
        foreach ($unrecognized as $key => $flags) {
            if (isset($flags['l'], $flags['r'])) {
                $both[] = (string) $key;
            }
        }
        if ($both !== [] && $unrecognizedIssue !== null) {
            $unrecognizedIssue['keys'] = $both;
            $payload->issues[] = $unrecognizedIssue;
        }
        if ($payload->isAborted()) {
            return $payload;
        }
        [$valid, $data, $path] = self::mergeValues($left->value, $right->value);
        if (!$valid) {
            throw new \LogicException('Unmergable intersection. Error path: ' . json_encode($path));
        }
        $payload->value = $data;

        return $payload;
    }

    /** @return array{bool, mixed, list<string|int>} */
    private static function mergeValues(mixed $a, mixed $b): array
    {
        if (Util::same($a, $b)) {
            return [true, $a, []];
        }
        if (is_array($a) && is_array($b) && array_is_list($a) && array_is_list($b) && $a !== [] && $b !== []) {
            if (count($a) !== count($b)) {
                return [false, null, []];
            }
            $merged = [];
            foreach ($a as $i => $item) {
                [$valid, $data, $path] = self::mergeValues($item, $b[$i]);
                if (!$valid) {
                    return [false, null, [$i, ...$path]];
                }
                $merged[] = $data;
            }

            return [true, $merged, []];
        }
        if (Util::isObject($a) && Util::isObject($b)) {
            $a = Util::entries($a);
            $b = Util::entries($b);
            $merged = array_replace($a, $b);
            foreach ($a as $key => $value) {
                if (array_key_exists($key, $b)) {
                    [$valid, $data, $path] = self::mergeValues($value, $b[$key]);
                    if (!$valid) {
                        return [false, null, [$key, ...$path]];
                    }
                    $merged[$key] = $data;
                }
            }

            return [true, $merged, []];
        }

        return [false, null, []];
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\ApolloServer;

/**
 * `negotiator`'s `mediaType(available)` (lib/mediaType.js): the available media type the
 * `Accept` header prefers, or null.
 */
final class Negotiator
{
    /**
     * @return list<array{type: string, subtype: string, params: array<string, string>, q: float, i: int}>
     */
    private static function parseAccept(string $accept): array
    {
        $accepts = [];
        foreach (self::splitMediaTypes($accept) as $i => $raw) {
            $parsed = self::parseMediaType(trim($raw), $i);
            if ($parsed !== null) {
                $accepts[] = $parsed;
            }
        }

        return $accepts;
    }

    /** @return list<string> */
    private static function splitMediaTypes(string $accept): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $length = strlen($accept);
        for ($i = 0; $i < $length; ++$i) {
            $char = $accept[$i];
            if ($char === '"') {
                $quoted = !$quoted;
            }
            if ($char === ',' && !$quoted) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }

    /** @return array{type: string, subtype: string, params: array<string, string>, q: float, i: int}|null */
    private static function parseMediaType(string $str, int $i): ?array
    {
        if (preg_match('/^\s*([^\s\/;]+)\/([^;\s]+)\s*(?:;(.*))?$/', $str, $match) !== 1) {
            return null;
        }

        $params = [];
        $q = 1.0;
        if (isset($match[3]) && $match[3] !== '') {
            foreach (explode(';', $match[3]) as $param) {
                $pair = explode('=', trim($param), 2);
                if (count($pair) !== 2) {
                    continue;
                }
                $key = strtolower(trim($pair[0]));
                $value = trim($pair[1]);
                if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
                    $value = substr($value, 1, -1);
                }
                if ($key === 'q') {
                    $q = (float) $value;
                    break;
                }
                $params[$key] = $value;
            }
        }

        return ['type' => $match[1], 'subtype' => $match[2], 'params' => $params, 'q' => $q, 'i' => $i];
    }

    /**
     * @param array{type: string, subtype: string, params: array<string, string>} $type
     * @param array{type: string, subtype: string, params: array<string, string>, q: float, i: int} $spec
     */
    private static function specify(array $type, array $spec): ?int
    {
        $s = 0;
        if (strtolower($spec['type']) === strtolower($type['type'])) {
            $s |= 4;
        } elseif ($spec['type'] !== '*') {
            return null;
        }

        if (strtolower($spec['subtype']) === strtolower($type['subtype'])) {
            $s |= 2;
        } elseif ($spec['subtype'] !== '*') {
            return null;
        }

        $keys = array_keys($spec['params']);
        if ($keys !== []) {
            foreach ($keys as $key) {
                $value = $spec['params'][$key];
                if (!($value === '*' || strtolower($value) === strtolower($type['params'][$key] ?? ''))) {
                    return null;
                }
            }
            $s |= 1;
        }

        return $s;
    }

    /** @param list<string> $available */
    public static function mediaType(string $accept, array $available): ?string
    {
        $accepts = self::parseAccept($accept);

        $priorities = [];
        foreach ($available as $index => $type) {
            $parsed = self::parseMediaType($type, $index);
            if ($parsed === null) {
                continue;
            }
            $priority = ['o' => -1, 'q' => 0.0, 's' => 0, 'i' => $index, 'type' => $type];
            foreach ($accepts as $spec) {
                $s = self::specify($parsed, $spec);
                if ($s !== null && ($priority['s'] - $s ?: $priority['q'] - $spec['q'] ?: $priority['o'] - $spec['i']) < 0) {
                    $priority = ['o' => $spec['i'], 'q' => $spec['q'], 's' => $s, 'i' => $index, 'type' => $type];
                }
            }
            if ($priority['q'] > 0) {
                $priorities[] = $priority;
            }
        }

        usort($priorities, static fn (array $a, array $b): int => ($b['q'] <=> $a['q']) ?: ($b['s'] <=> $a['s']) ?: ($a['o'] <=> $b['o']) ?: ($a['i'] <=> $b['i']));

        return $priorities[0]['type'] ?? null;
    }
}

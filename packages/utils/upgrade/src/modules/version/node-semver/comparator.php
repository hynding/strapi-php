<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Version\NodeSemver;

/**
 * Port of the npm `semver@7.7.4` package's classes/comparator.js (strict mode), plus
 * functions/cmp.js which only it uses. `$semver === null` stands for upstream's `Comparator.ANY`.
 */
final class Comparator implements \Stringable
{
    public string $operator;

    public ?SemVer $semver;

    public string $value;

    public function __construct(Comparator|string $comp)
    {
        if ($comp instanceof Comparator) {
            $comp = $comp->value;
        }

        $comp = implode(' ', preg_split('/\s+/', trim($comp)) ?: []);
        $this->parse($comp);

        $this->value = $this->semver === null ? '' : $this->operator . $this->semver->version;
    }

    private function parse(string $comp): void
    {
        if (preg_match(Re::COMPARATOR, $comp, $m) !== 1) {
            throw new \InvalidArgumentException("Invalid comparator: {$comp}");
        }

        $this->operator = $m[1] ?? '';
        if ($this->operator === '=') {
            $this->operator = '';
        }

        // if it literally is just '>' or '' then allow anything.
        $this->semver = isset($m[2]) ? new SemVer($m[2]) : null;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function test(SemVer|string|null $version): bool
    {
        if ($this->semver === null || $version === null) {
            return true;
        }

        if (is_string($version)) {
            try {
                $version = new SemVer($version);
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        return self::cmp($version, $this->operator, $this->semver);
    }

    public function intersects(Comparator $comp): bool
    {
        if ($this->operator === '') {
            if ($this->value === '') {
                return true;
            }

            return (new Range($comp->value))->test($this->value);
        }
        if ($comp->operator === '') {
            if ($comp->value === '') {
                return true;
            }

            return (new Range($this->value))->test($comp->semver);
        }

        // Special cases where nothing can possibly be lower
        if (str_starts_with($this->value, '<0.0.0') || str_starts_with($comp->value, '<0.0.0')) {
            return false;
        }

        // Same direction increasing (> or >=)
        if (str_starts_with($this->operator, '>') && str_starts_with($comp->operator, '>')) {
            return true;
        }
        // Same direction decreasing (< or <=)
        if (str_starts_with($this->operator, '<') && str_starts_with($comp->operator, '<')) {
            return true;
        }

        assert($this->semver !== null && $comp->semver !== null);

        // same SemVer and both sides are inclusive (<= or >=)
        if ($this->semver->version === $comp->semver->version && str_contains($this->operator, '=') && str_contains($comp->operator, '=')) {
            return true;
        }
        // opposite directions less than
        if (self::cmp($this->semver, '<', $comp->semver) && str_starts_with($this->operator, '>') && str_starts_with($comp->operator, '<')) {
            return true;
        }
        // opposite directions greater than
        if (self::cmp($this->semver, '>', $comp->semver) && str_starts_with($this->operator, '<') && str_starts_with($comp->operator, '>')) {
            return true;
        }

        return false;
    }

    /** Port of functions/cmp.js. */
    public static function cmp(SemVer|string $a, string $op, SemVer|string $b): bool
    {
        $a = $a instanceof SemVer ? $a : new SemVer($a);
        $b = $b instanceof SemVer ? $b : new SemVer($b);

        return match ($op) {
            '===' => $a->version === $b->version,
            '!==' => $a->version !== $b->version,
            '', '=', '==' => $a->compare($b) === 0,
            '!=' => $a->compare($b) !== 0,
            '>' => $a->compare($b) > 0,
            '>=' => $a->compare($b) >= 0,
            '<' => $a->compare($b) < 0,
            '<=' => $a->compare($b) <= 0,
            default => throw new \InvalidArgumentException("Invalid operator: {$op}"),
        };
    }
}

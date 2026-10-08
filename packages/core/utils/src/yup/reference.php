<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

use Strapi\Utils\Primitives\Objects;

/**
 * yup's `Reference` (`yup.ref(key)`): a sibling key (`a.b`), a context key (`$a`) or the value
 * itself (`.` / `.a`).
 */
final class Reference implements \Stringable
{
    public readonly string $key;

    public readonly bool $isContext;

    public readonly bool $isValue;

    public readonly bool $isSibling;

    public readonly string $path;

    /** @var \Closure(mixed): mixed|null */
    private ?\Closure $map;

    /** @param array{map?: callable(mixed): mixed} $options */
    public function __construct(string $key, array $options = [])
    {
        $this->key = trim($key);
        if ($key === '') {
            throw new \TypeError('ref must be a non-empty string');
        }
        $this->isContext = str_starts_with($this->key, '$');
        $this->isValue = str_starts_with($this->key, '.');
        $this->isSibling = !$this->isContext && !$this->isValue;
        $this->path = $this->isSibling ? $this->key : substr($this->key, 1);
        $this->map = isset($options['map']) ? $options['map'](...) : null;
    }

    public function getValue(mixed $value, mixed $parent, mixed $context): mixed
    {
        $result = $this->isContext ? $context : ($this->isValue ? $value : $parent);
        if ($this->path !== '') {
            $sentinel = new \stdClass();
            $source = Yup::truthy($result) ? $result : [];
            $result = Objects::get($source, $this->path, $sentinel);
            if ($result === $sentinel) {
                $result = Undefined::value();
            }
        }
        if ($this->map !== null) {
            $result = ($this->map)($result);
        }

        return $result;
    }

    /** @param array<string, mixed> $options */
    public function cast(mixed $value, array $options = []): mixed
    {
        return $this->getValue($value, $options['parent'] ?? Undefined::value(), $options['context'] ?? Undefined::value());
    }

    /** @return array{type: string, key: string} */
    public function describe(): array
    {
        return ['type' => 'ref', 'key' => $this->key];
    }

    public function __toString(): string
    {
        return "Ref({$this->key})";
    }
}

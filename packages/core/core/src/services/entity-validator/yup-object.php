<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** `yup.object()` */
class YupObject extends Yup
{
    protected string $type = 'object';

    /** @var array<string, Yup> */
    public array $fields = [];

    /** @param array<string, Yup> $shape */
    public function shape(array $shape): static
    {
        $clone = clone $this;
        $clone->fields = [...$clone->fields, ...$shape];

        return $clone;
    }

    public function noUnknown(bool $noUnknown = true, string $message = '${path} field has unspecified keys: ${unknown}'): static
    {
        $clone = clone $this;
        if ($noUnknown) {
            $clone = $clone->test('noUnknown', $message, function (mixed $v, TestContext $ctx) use ($message): bool|YupError {
                if (!is_array($v)) {
                    return true;
                }
                $unknown = array_diff(array_keys($v), array_keys($this->fields));
                if ($unknown === []) {
                    return true;
                }

                return $ctx->createError(['message' => $message, 'params' => ['unknown' => implode(', ', $unknown)]]);
            });
        }

        return $clone;
    }

    protected function cast(mixed $value): mixed
    {
        if (is_string($value) && json_validate($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && !array_is_list($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    protected function typeCheck(mixed $value): bool
    {
        return is_array($value) && (!array_is_list($value) || $value === []) || is_object($value);
    }

    protected function runInner(mixed $value, string $path, bool $strict, array &$errors): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = $value;
        foreach ($this->fields as $key => $schema) {
            $fieldValue = array_key_exists($key, $value) ? $value[$key] : Undefined::value();
            $result = $schema->run($fieldValue, Yup::joinPath($path, $key), $strict, $errors, $fieldValue);
            if ($result instanceof Undefined) {
                unset($out[$key]);
            } else {
                $out[$key] = $result;
            }
        }

        return $out;
    }
}

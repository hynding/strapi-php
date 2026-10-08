<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Zod\Z;
use Strapi\Utils\Zod\ZodError;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/utils/src/zod.ts.
 *
 * Upstream re-exports `z` from `zod/v4`; here the zod subset lives in `src/zod/`
 * (`Strapi\Utils\Zod\*`) and this class *is* `z`: it inherits every factory of
 * {@see Z} as a static method. Import it under the name `z` and upstream code translates
 * mechanically (`.` → `->`, `z.` → `z::`, object literals → arrays, regex literals → PCRE strings):
 *
 * ```php
 * use Strapi\Utils\Zod as z;
 *
 * // z.object({ name: z.string().min(1), tags: z.array(z.string()).optional() }).strict()
 * $schema = z::object(['name' => z::string()->min(1), 'tags' => z::array(z::string())->optional()])->strict();
 *
 * $result = $schema->safeParse($input);   // ['success' => bool, 'data' => ..., 'error' => ?ZodError]
 * $data = Zod::validateZodSchema($schema)($input, 'Invalid body');   // throws ValidationError
 * ```
 *
 * `z.coerce.number()` is `z::coerce()->number()`, `z.ZodIssueCode.custom` is
 * `Zod\ZodIssueCode::custom`, JavaScript `undefined` is `Zod\Undefined::Value`.
 */
final class Zod extends Z
{
    /**
     * Returns `fn(mixed $data, ?string $errorMessage = null): mixed` that parses `$data` and
     * throws a {@see ValidationError} carrying `details.errors[] = { path, message, name }` on
     * failure (upstream's `value: undefined` is left out, as JSON serialization drops it).
     *
     * @return \Closure(mixed, string|null=): mixed
     */
    public static function validateZodSchema(ZodType $schema): \Closure
    {
        return static function (mixed $data, ?string $errorMessage = null) use ($schema): mixed {
            try {
                return $schema->parse($data);
            } catch (ZodError $error) {
                ['message' => $message, 'errors' => $errors] = self::formatZodErrors($error);

                throw new ValidationError($errorMessage !== null && $errorMessage !== '' ? $errorMessage : $message, ['errors' => $errors]);
            }
        };
    }

    /**
     * @return array{errors: list<array{path: list<string>, message: string, name: string}>, message: string}
     */
    private static function formatZodErrors(ZodError $error): array
    {
        $errors = [];
        foreach ($error->issues as $issue) {
            /** @var list<string|int> $path */
            $path = $issue['path'] ?? [];
            $errors[] = [
                'path' => array_map(strval(...), $path),
                'message' => (string) ($issue['message'] ?? ''),
                'name' => 'ValidationError',
            ];
        }

        return [
            'errors' => $errors,
            'message' => isset($error->issues[0]['message']) ? (string) $error->issues[0]['message'] : 'Validation error',
        ];
    }

    /**
     * Converts a ZodError into form-compatible errors matching getYupValidationErrors from the
     * @strapi/admin Form component: a flat map of dot-path keys to the first message for that
     * path. Root-level errors (path = []) are stored under the empty string key ''.
     *
     * @return array<string, string>
     */
    public static function getZodValidationErrors(ZodError $error): array
    {
        $errors = [];
        foreach ($error->issues as $issue) {
            /** @var list<string|int> $path */
            $path = $issue['path'] ?? [];
            $key = implode('.', array_map(strval(...), $path));
            if (!array_key_exists($key, $errors)) {
                $errors[$key] = (string) ($issue['message'] ?? '');
            }
        }

        return $errors;
    }
}

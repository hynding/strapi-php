<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Validation;

use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodError;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/validation/zod.ts.
 *
 * @phpstan-type FormattedZodError array{path: list<string>, message: string, name: 'ValidationError'}
 * @phpstan-type FormattedZodErrors array{errors: list<FormattedZodError>, message: string}
 */
final class Zod
{
    /**
     * Transforms a ZodError into the same shape as formatYupErrors from @strapi/utils.
     * Only keeps the first error per path to match Yup behavior. (Upstream's `value: undefined`
     * is left out: JSON drops it.)
     *
     * @return FormattedZodErrors
     */
    public static function formatZodErrors(ZodError $zodError): array
    {
        $seen = [];
        $formattedErrors = [];

        foreach ($zodError->issues as $issue) {
            /** @var list<string|int> $path */
            $path = $issue['path'] ?? [];
            $key = implode('.', array_map('strval', $path));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $formattedErrors[] = [
                    'path' => array_map('strval', $path),
                    'message' => (string) ($issue['message'] ?? ''),
                    'name' => 'ValidationError',
                ];
            }
        }

        return [
            'errors' => $formattedErrors,
            'message' => isset($zodError->issues[0]['message']) ? (string) $zodError->issues[0]['message'] : 'Validation error',
        ];
    }

    /**
     * Zod schema for Strapi entity IDs.
     * Matches the StrapiIDSchema from @strapi/utils/yup:
     * accepts strings or non-negative integers.
     */
    public static function strapiID(): ZodType
    {
        return z::union([z::string(), z::number()->int()->nonnegative()]);
    }

    /**
     * Async Zod validator matching the signature of validateYupSchema from @strapi/utils.
     *
     * Usage:
     *   $validate = Zod::validateZodAsync($mySchema);
     *   $data = $validate($body);            // throws ValidationError on failure
     *   $data = $validate($body, 'Custom');  // throws with custom message
     *
     * @return \Closure(mixed, string|null=): mixed
     */
    public static function validateZodAsync(ZodType $schema): \Closure
    {
        return static function (mixed $data, ?string $errorMessage = null) use ($schema): mixed {
            try {
                return $schema->parse($data);
            } catch (ZodError $error) {
                ['message' => $message, 'errors' => $formattedErrors] = self::formatZodErrors($error);

                throw new ValidationError($errorMessage !== null && $errorMessage !== '' ? $errorMessage : $message, [
                    'errors' => $formattedErrors,
                ]);
            }
        };
    }
}

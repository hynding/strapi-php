<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers\Validation;

use Strapi\Utils\Template;

/** Port of server/src/controllers/validation/email-template.js. */
final class EmailTemplate
{
    private const INVALID_PATTERNS_REGEXES = [
        // Ignore "evaluation" patterns: <% ... %>
        '/<%[^=]([\s\S]*?)%>/m',
        // Ignore basic string interpolations
        '/\$\{([^{}]*)\}/m',
    ];

    private const AUTHORIZED_KEYS = [
        'URL',
        'ADMIN_URL',
        'SERVER_URL',
        'CODE',
        'USER',
        'USER.email',
        'USER.username',
        'TOKEN',
    ];

    /** @return list<string> */
    private static function matchAll(string $pattern, string $src): array
    {
        $matches = [];
        if (preg_match_all($pattern, $src, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $match) {
                $matches[] = trim($match[1] ?? '');
            }
        }

        return $matches;
    }

    public static function isValidEmailTemplate(mixed $template): bool
    {
        // `RegExp.test(undefined)` tests the string "undefined"
        $template = match (true) {
            $template === null => 'null',
            is_string($template) => $template,
            is_scalar($template) => (string) $template,
            default => '[object Object]',
        };

        // Check for known invalid patterns
        foreach (self::INVALID_PATTERNS_REGEXES as $reg) {
            if (preg_match($reg, $template) === 1) {
                return false;
            }
        }

        // Strict interpolation pattern to match only valid groups
        $strict = Template::createStrictInterpolationRegExp(self::AUTHORIZED_KEYS);
        // Weak interpolation pattern to match as many group as possible.
        $loose = Template::createLooseInterpolationRegExp();

        // Compute both strict & loose matches
        $strictMatches = self::matchAll($strict, $template);
        $looseMatches = self::matchAll($loose, $template);

        // If we have more matches with the loose RegExp than with the strict one,
        // then it means that at least one of the interpolation group is invalid
        // Note: In the future, if we wanted to give more details for error formatting
        // purposes, we could return the difference between the two arrays
        if (count($looseMatches) > count($strictMatches)) {
            return false;
        }

        return true;
    }
}

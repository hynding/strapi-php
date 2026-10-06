<?php

declare(strict_types=1);

namespace Strapi\Utils;

/** Port of packages/core/utils/src/template.ts: `<%= variable %>` interpolation regexps. */
final class Template
{
    /**
     * Strict interpolation regexp accepting only the given variable names.
     *
     * @param list<string> $allowedVariableNames
     */
    public static function createStrictInterpolationRegExp(array $allowedVariableNames, string $flags = ''): string
    {
        $oneOfVariables = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $allowedVariableNames));

        return "/<%=\\s*({$oneOfVariables})\\s*%>/{$flags}";
    }

    /** Loose interpolation regexp matching as many groups as possible. */
    public static function createLooseInterpolationRegExp(string $flags = ''): string
    {
        return "/<%=([\\s\\S]+?)%>/{$flags}";
    }

    /**
     * Render a template with `<%= name %>` placeholders from the given variables (strict: unknown names stay).
     *
     * @param array<string, scalar|\Stringable|null> $variables
     */
    public static function render(string $template, array $variables): string
    {
        if ($variables === []) {
            return $template;
        }

        $regexp = self::createStrictInterpolationRegExp(array_keys($variables), 'g');

        return (string) preg_replace_callback(
            rtrim($regexp, 'g'),
            static fn (array $m): string => (string) ($variables[$m[1]] ?? ''),
            $template,
        );
    }
}

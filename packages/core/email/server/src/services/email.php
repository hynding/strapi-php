<?php

declare(strict_types=1);

namespace Strapi\Email\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Template;

/**
 * Port of server/src/services/email.ts.
 *
 * `sendTemplatedEmail` fills `subject`, `text` and `html` the way upstream's
 * `_.template(str, { interpolate: createStrictInterpolationRegExp(keysDeep(data)) })(data)` does:
 * `<%= path %>` is replaced by the value at `path` in `data` when `path` is one of its leaf paths
 * (`null`/missing → `''`, other values stringified as JavaScript would). lodash still compiles every
 * other `<% … %>` / `<%- … %>` block as JavaScript; there is no JavaScript here, so such a block
 * (which upstream evaluates, or rejects with a SyntaxError/ReferenceError) throws.
 */
final class Email
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array<string, mixed> */
    public function getProviderSettings(): array
    {
        $config = $this->strapi->config()->get('plugin::email');

        return is_array($config) ? $config : [];
    }

    /** @param array<string, mixed> $options */
    public function send(array $options): mixed
    {
        return $this->providerSend($options);
    }

    /**
     * fill subject, text and html using lodash template
     *
     * @param array<string, mixed> $emailOptions - to, from and replyto...
     * @param array<string, mixed> $emailTemplate - object containing attributes to fill
     * @param array<string, mixed> $data - data used to fill the template
     */
    public function sendTemplatedEmail(array $emailOptions, array $emailTemplate, array $data): mixed
    {
        $attributes = ['subject', 'text', 'html'];
        $missingAttributes = array_values(array_diff($attributes, array_map('strval', array_keys($emailTemplate))));

        if (count($missingAttributes) > 0) {
            throw new \RuntimeException(
                'Following attributes are missing from your email template : ' . implode(', ', $missingAttributes)
            );
        }

        $allowedInterpolationVariables = Objects::keysDeep($data);
        $interpolate = Template::createStrictInterpolationRegExp($allowedInterpolationVariables, '');

        $templatedAttributes = [];
        foreach ($attributes as $attribute) {
            $value = $emailTemplate[$attribute];
            // `emailTemplate[attribute] ? … : compiled`: falsy templates are left out
            if ($value === null || $value === '' || $value === false || $value === 0) {
                continue;
            }
            $templatedAttributes[$attribute] = self::template((string) $value, $interpolate, $data);
        }

        return $this->providerSend([...$emailOptions, ...$templatedAttributes]);
    }

    /**
     * `_.template(source, { interpolate })(data)` for the strict interpolation regexp.
     *
     * @param array<string, mixed> $data
     */
    private static function template(string $source, string $interpolate, array $data): string
    {
        $result = preg_replace_callback(
            $interpolate,
            static fn (array $m): string => self::toJsString(self::get($data, $m[1])),
            $source,
        );
        $result = (string) $result;

        // lodash's `escape` / `evaluate` delimiters (and an `<%= … %>` naming no data path, which
        // falls through to `evaluate`) would run JavaScript.
        if (preg_match('/<%([\s\S]+?)%>/', $result, $m) === 1) {
            throw new \RuntimeException("Invalid or unexpected token in template: unsupported template expression \"<%{$m[1]}%>\"");
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private static function get(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } elseif (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
            } else {
                return null;
            }
        }

        return $value;
    }

    /** `((__t = (value)) == null ? '' : __t)` concatenated into a string. */
    private static function toJsString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_array($value) && array_is_list($value) => implode(',', array_map(self::toJsString(...), $value)),
            $value instanceof \Stringable => (string) $value,
            default => '[object Object]',
        };
    }

    /**
     * `strapi.plugin('email').provider.send(options)`
     *
     * @param array<string, mixed> $options
     */
    private function providerSend(array $options): mixed
    {
        $provider = $this->strapi->plugin('email')->provider;
        $send = [$provider, 'send'];
        if (!is_object($provider) || !is_callable($send)) {
            throw new \RuntimeException('The email plugin has no provider');
        }

        return $send($options);
    }
}

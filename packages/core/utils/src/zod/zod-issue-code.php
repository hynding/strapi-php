<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.ZodIssueCode`: the issue codes zod v4 reports. Constant names keep zod's spelling so
 * `z.ZodIssueCode.custom` translates to `ZodIssueCode::custom`.
 */
final class ZodIssueCode
{
    public const string invalid_type = 'invalid_type';
    public const string too_big = 'too_big';
    public const string too_small = 'too_small';
    public const string invalid_format = 'invalid_format';
    public const string not_multiple_of = 'not_multiple_of';
    public const string unrecognized_keys = 'unrecognized_keys';
    public const string invalid_union = 'invalid_union';
    public const string invalid_key = 'invalid_key';
    public const string invalid_element = 'invalid_element';
    public const string invalid_value = 'invalid_value';
    public const string custom = 'custom';
}

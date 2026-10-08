<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils;

/**
 * Not an upstream file: the `url-join` npm package (4.0.1) the plugin imports in its services
 * and controllers, ported once here.
 */
final class UrlJoin
{
    public static function join(string ...$parts): string
    {
        $strArray = array_values($parts);
        if ($strArray === []) {
            return '';
        }

        // If the first part is a plain protocol, we combine it with the next part.
        if (preg_match('~^[^/:]+:/*$~', $strArray[0]) === 1 && count($strArray) > 1) {
            $first = array_shift($strArray);
            $strArray[0] = $first . $strArray[0];
        }

        // There must be two or three slashes in the file protocol, two slashes in anything else.
        if (preg_match('~^file:///~', $strArray[0]) === 1) {
            $strArray[0] = (string) preg_replace('~^([^/:]+):/*~', '$1:///', $strArray[0]);
        } else {
            $strArray[0] = (string) preg_replace('~^([^/:]+):/*~', '$1://', $strArray[0]);
        }

        $resultArray = [];
        $count = count($strArray);
        foreach ($strArray as $i => $component) {
            if ($component === '') {
                continue;
            }

            if ($i > 0) {
                // Removing the starting slashes for each component but the first.
                $component = (string) preg_replace('~^/+~', '', $component);
            }

            if ($i < $count - 1) {
                // Removing the ending slashes for each component but the last.
                $component = (string) preg_replace('~/+$~', '', $component);
            } else {
                // For the last component we will combine multiple slashes to a single one.
                $component = (string) preg_replace('~/+$~', '/', $component);
            }

            $resultArray[] = $component;
        }

        $str = implode('/', $resultArray);

        // remove trailing slash before parameters or hash
        $str = (string) preg_replace('~/(\?|&|#[^!])~', '$1', $str);

        // replace ? in parameters with &
        $queryParts = explode('?', $str);
        $head = array_shift($queryParts);

        return $head . ($queryParts !== [] ? '?' : '') . implode('&', $queryParts);
    }
}

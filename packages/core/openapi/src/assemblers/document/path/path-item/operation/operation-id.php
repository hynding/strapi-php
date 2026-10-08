<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Constants;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/assemblers/document/path/path-item/operation/operation-id.ts. */
final class OperationIDAssembler implements Assembler\Operation
{
    /**
     * `REGEX_STRAPI_PATH_PARAMS` is a module-level `/g` regex upstream, and `RegExp.exec()` on a
     * global regex resumes at its `lastIndex`: that state carries over between path segments,
     * routes and documents of the same process, which shapes some operation IDs (a `:param`
     * segment that follows a matched one at a lower offset is read as a plain segment). Kept as is.
     */
    private static int $lastIndex = 0;

    public function assemble(Context $context, array $route): void
    {
        $debug = Debug::createDebugger('assembler:operation-id');

        $path = (string) ($route['path'] ?? '');
        $method = (string) ($route['method'] ?? '');
        $info = is_array($route['info'] ?? null) ? $route['info'] : [];

        $origin = $info['apiName'] ?? $info['pluginName'] ?? null;
        $origin = is_string($origin) ? $origin : null;

        // 'origin/' or ''
        $operationId = $this->maybeAppendOrigin($origin);
        // 'origin/get' or 'get'
        $operationId = $this->appendMethod($method, $operationId);
        // 'origin/get/entity_by_id' or 'get/entity_by_id'
        $operationId = $this->maybeAppendPath($path, $operationId);

        $debug('assembled an operation ID for %o %o: %o', $method, $path, $operationId);

        $context->output->data['operationId'] = $operationId;
    }

    private function maybeAppendOrigin(?string $origin): string
    {
        return $origin !== null && $origin !== '' ? "{$origin}/" : '';
    }

    private function appendMethod(string $method, string $operationId): string
    {
        return $operationId . strtolower($method);
    }

    private function maybeAppendPath(string $path, string $operationId): string
    {
        $pathParts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));

        if ($pathParts === []) {
            return $operationId;
        }

        // Make sure to add a trailing slash after the method name
        $appendix = '/';

        $formatPart = static function (string $str) use (&$appendix): string {
            return preg_match('/[_\/]$/', $appendix) === 1 ? $str : "_{$str}";
        };

        foreach ($pathParts as $part) {
            $match = self::exec($part);

            $appendix .= $match !== null
                // Parameter
                ? $formatPart("by_{$match}")
                // Regular path segment
                : $formatPart((string) preg_replace('/\W/', '_', $part));
        }

        return $operationId . $appendix;
    }

    /** `REGEX_STRAPI_PATH_PARAMS.exec(part)` with the regex's `lastIndex` (see {@see self::$lastIndex}); returns group 1. */
    private static function exec(string $part): ?string
    {
        if (self::$lastIndex > strlen($part)
            || preg_match(Constants::REGEX_STRAPI_PATH_PARAMS, $part, $match, PREG_OFFSET_CAPTURE, self::$lastIndex) !== 1
        ) {
            self::$lastIndex = 0;

            return null;
        }

        self::$lastIndex = $match[0][1] + strlen($match[0][0]);

        return $match[1][0];
    }
}

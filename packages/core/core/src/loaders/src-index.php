<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/loaders/src-index.ts: `src/index.php` returns
 * `['register' => fn (Strapi $strapi) => ..., 'bootstrap' => ..., 'destroy' => ...]`.
 */
final class SrcIndex
{
    public function __invoke(Strapi $strapi): void
    {
        $src = $strapi->dirs()->src;
        if (!is_dir($src)) {
            return;
        }

        $pathToSrcIndex = $src . '/index.php';
        if (!is_file($pathToSrcIndex)) {
            return;
        }

        $srcIndex = (static fn (): mixed => require $pathToSrcIndex)();
        if ($srcIndex === 1 || $srcIndex === null) {
            $srcIndex = [];
        }

        if (!is_array($srcIndex)) {
            throw new \RuntimeException('Invalid file `./src/index.php`: expected an array with register/bootstrap/destroy keys');
        }

        foreach ($srcIndex as $key => $value) {
            if (!in_array($key, ['register', 'bootstrap', 'destroy'], true)) {
                throw new \RuntimeException("Invalid file `./src/index.php`: this field has unspecified keys: {$key}");
            }
            if ($value !== null && !is_callable($value)) {
                throw new \RuntimeException("Invalid file `./src/index.php`: {$key} is not a function");
            }
        }

        $strapi->app = $srcIndex;
    }
}

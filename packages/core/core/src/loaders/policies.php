<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/loaders/policies.ts: `src/policies/<name>.php` under `global::`. */
final class Policies
{
    public function __invoke(Strapi $strapi): void
    {
        self::loadPolicies($strapi);
    }

    public static function loadPolicies(Strapi $strapi): void
    {
        $dir = $strapi->dirs()->policies;
        if (!is_dir($dir)) {
            return;
        }

        $policies = [];
        $entries = scandir($dir) ?: [];
        sort($entries);
        foreach ($entries as $name) {
            $fullPath = $dir . '/' . $name;
            if (is_file($fullPath) && pathinfo($name, PATHINFO_EXTENSION) === 'php') {
                $key = pathinfo($name, PATHINFO_FILENAME);
                $policy = (static fn (): mixed => require $fullPath)();
                if (!is_callable($policy) && !$policy instanceof \Strapi\Utils\Policy\PolicyDefinition) {
                    throw new \RuntimeException("Policy file {$fullPath} must return a callable");
                }
                $policies[$key] = $policy;
            }
        }

        $strapi->get('policies')->add('global::', $policies);
    }
}

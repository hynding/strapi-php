<?php

declare(strict_types=1);

/**
 * Plugin declarations. Upstream enables graphql, documentation and a local plugin here; those
 * packages are not ported yet, so nothing is enabled. A Composer package with
 * `extra.strapi.kind = "plugin"` is picked up automatically; a local plugin is declared as
 * `['enabled' => true, 'resolve' => './src/plugins/local-plugin', 'config' => [...]]`.
 */
return static fn (): array => [
    // 'myplugin' => [
    //     'enabled' => true,
    //     'resolve' => './src/plugins/local-plugin', // From the root of the project
    //     'config' => ['testConf' => 3],
    // ],
];

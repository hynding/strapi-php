<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

/** Mirrors Core.StrapiDirectories — resolved paths of a project (dist/ and app/ are the same in PHP). */
final readonly class StrapiDirectories
{
    public function __construct(
        public string $root,
        public string $src,
        public string $api,
        public string $components,
        public string $extensions,
        public string $policies,
        public string $middlewares,
        public string $config,
        public string $public,
        public string $uploads,
        public string $tmp,
        public string $static,
        public string $database,
    ) {
    }

    public static function fromRoot(string $root, ?string $publicDir = null): self
    {
        $root = rtrim($root, '/');
        $src = $root . '/src';

        return new self(
            root: $root,
            src: $src,
            api: $src . '/api',
            components: $src . '/components',
            extensions: $src . '/extensions',
            policies: $src . '/policies',
            middlewares: $src . '/middlewares',
            config: $root . '/config',
            public: $publicDir ?? $root . '/public',
            uploads: ($publicDir ?? $root . '/public') . '/uploads',
            tmp: $root . '/.tmp',
            static: $root . '/.strapi/client',
            database: $root . '/database',
        );
    }
}

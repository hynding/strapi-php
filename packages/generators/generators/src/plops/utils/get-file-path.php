<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

/** Port of src/plops/utils/get-file-path.ts: the destination folder, relative to `src/`. */
final class GetFilePath
{
    public static function getFilePath(?string $destination): string
    {
        if ($destination === 'api') {
            return 'api/{{ api }}';
        }

        if ($destination === 'plugin') {
            return 'plugins/{{ plugin }}/server/src';
        }

        if ($destination === 'root') {
            return '.';
        }

        return 'api/{{ id }}';
    }
}

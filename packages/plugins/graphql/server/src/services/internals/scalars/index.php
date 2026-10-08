<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Scalars;

use Strapi\Plugin\Graphql\Lib\GraphqlScalars\DateTime;
use Strapi\Plugin\Graphql\Lib\GraphqlScalars\Json;
use Strapi\Plugin\Graphql\Lib\GraphqlScalars\Long;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ScalarTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/services/internals/scalars/index.ts */
final class Scalars
{
    /** @return array<string, ScalarTypeDef> */
    public static function create(): array
    {
        return [
            'JSON' => Nexus::asNexusMethod(Json::type(), 'json'),
            'DateTime' => Nexus::asNexusMethod(DateTime::type(), 'dateTime'),
            'Time' => Nexus::asNexusMethod(Time::type(), 'time'),
            'Date' => Nexus::asNexusMethod(Date::type(), 'date'),
            'Long' => Nexus::asNexusMethod(Long::type(), 'long'),
        ];
    }
}

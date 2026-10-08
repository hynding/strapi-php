<?php

declare(strict_types=1);

namespace Strapi\Generators;

use Strapi\Generators\Plops\Api;
use Strapi\Generators\Plops\ContentType;
use Strapi\Generators\Plops\Controller;
use Strapi\Generators\Plops\Middleware;
use Strapi\Generators\Plops\Migration;
use Strapi\Generators\Plops\Plugin;
use Strapi\Generators\Plops\Policy;
use Strapi\Generators\Plops\Service;
use Strapi\Generators\Plops\Utils\Pluralize;

/** Port of src/plopfile.ts: registers the helpers and the generators. */
final class Plopfile
{
    public function __invoke(Plop $plop): void
    {
        // Plop config
        $plop->setWelcomeMessage('Strapi Generators');
        $plop->setHelper('pluralize', static fn (mixed $text): string => Pluralize::plural(is_scalar($text) ? (string) $text : ''));
        // not upstream: JSON values in the schema.json template (handlebars would HTML-escape them)
        $plop->setHelper('json', static fn (mixed $value): string => (string) json_encode($value ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Generators
        (new Api())($plop);
        (new Controller())($plop);
        (new ContentType())($plop);
        (new Policy())($plop);
        (new Middleware())($plop);
        (new Migration())($plop);
        (new Service())($plop);
        // not upstream (upstream: `npx @strapi/sdk-plugin init`)
        (new Plugin())($plop);
    }
}

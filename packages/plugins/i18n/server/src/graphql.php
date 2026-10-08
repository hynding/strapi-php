<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotImplementedError;

/**
 * Port of server/src/graphql.ts. The GraphQL plugin (nexus) is not ported: `register()` is only
 * called when a `graphql` plugin is installed, and throws until there is one to extend.
 */
final class Graphql
{
    public const string LOCALE_SCALAR_TYPENAME = 'I18NLocaleCode';

    public const string LOCALE_ARG_PLUGIN_NAME = 'I18NLocaleArg';

    public static function graphqlProvider(Strapi $strapi): self
    {
        return new self();
    }

    public function register(): void
    {
        throw new NotImplementedError('The i18n GraphQL extension needs @strapi/plugin-graphql, which is not ported.');
    }
}

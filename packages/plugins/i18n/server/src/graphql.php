<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n;

use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\PluginDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ScalarTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\I18n\Services\ContentTypes;
use Strapi\Plugin\I18n\Services\IsoLocales;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/graphql.ts: the i18n extension of the GraphQL plugin's content API (the
 * `I18NLocaleCode` scalar, a `locale` argument on localized queries and mutations, localized
 * fields hidden from inputs, the locale queries' scopes).
 */
final class Graphql
{
    public const string LOCALE_SCALAR_TYPENAME = 'I18NLocaleCode';

    public const string LOCALE_ARG_PLUGIN_NAME = 'I18NLocaleArg';

    private function __construct(private readonly Strapi $strapi)
    {
    }

    public static function graphqlProvider(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public function register(): void
    {
        $strapi = $this->strapi;
        $graphql = $strapi->plugin('graphql');

        $contentTypesService = $strapi->plugin('i18n')->service('content-types');
        \assert($contentTypesService instanceof ContentTypes);

        $extensionService = $graphql->service('extension');
        \assert($extensionService instanceof Extension);

        $extensionService->shadowCRUD('plugin::i18n.locale')->disableMutations();

        // Disable unwanted fields for localized content types
        foreach ($strapi->contentTypes() as $uid => $ct) {
            if ($contentTypesService->isLocalizedContentType($ct)) {
                // Disable locale field in localized inputs
                $extensionService->shadowCRUD((string) $uid)->field('locale')->disableInput();

                // Disable localizations field in localized inputs
                $extensionService->shadowCRUD((string) $uid)->field('localizations')->disableInput();
            }
        }

        $extensionService->use(function (array $params): array {
            $typeRegistry = $params['typeRegistry'] ?? null;
            $i18nLocaleArgPlugin = $this->getI18nLocaleArgPlugin($typeRegistry instanceof TypeRegistry ? $typeRegistry : null);
            $i18nLocaleScalar = $this->getLocaleScalar();

            return [
                'plugins' => [$i18nLocaleArgPlugin],
                'types' => [$i18nLocaleScalar],

                'resolversConfig' => [
                    // Modify the default scope associated to find and findOne locale queries to match the actual action name
                    'Query.i18NLocale' => ['auth' => ['scope' => 'plugin::i18n.locales.listLocales']],
                    'Query.i18NLocales' => ['auth' => ['scope' => 'plugin::i18n.locales.listLocales']],
                ],
            ];
        });
    }

    private function getLocaleScalar(): ScalarTypeDef
    {
        $isoLocales = $this->strapi->plugin('i18n')->service('iso-locales');
        \assert($isoLocales instanceof IsoLocales);
        $locales = $isoLocales->getIsoLocales();

        return Nexus::scalarType([
            'name' => self::LOCALE_SCALAR_TYPENAME,

            'description' => 'A string used to identify an i18n locale',

            'serialize' => static fn (mixed $value): mixed => $value,
            'parseValue' => static fn (mixed $value): mixed => $value,

            'parseLiteral' => static function (Node $ast) use ($locales): string {
                if (!$ast instanceof StringValueNode) {
                    throw new ValidationError('Locale cannot represent non string type');
                }

                $isValidLocale = $ast->value === '*';
                if (!$isValidLocale) {
                    foreach ($locales as $locale) {
                        if (is_array($locale) && ($locale['code'] ?? null) === $ast->value) {
                            $isValidLocale = true;
                            break;
                        }
                    }
                }

                if (!$isValidLocale) {
                    throw new ValidationError('Unknown locale supplied');
                }

                return $ast->value;
            },
        ]);
    }

    private function getI18nLocaleArgPlugin(?TypeRegistry $typeRegistry): PluginDef
    {
        $contentTypesService = $this->strapi->plugin('i18n')->service('content-types');
        \assert($contentTypesService instanceof ContentTypes);

        return Nexus::plugin([
            'name' => self::LOCALE_ARG_PLUGIN_NAME,

            'onAddOutputField' => static function (array $config) use ($typeRegistry, $contentTypesService): ?array {
                // Add the locale arg to the queries on localized CTs

                $parentType = $config['parentType'] ?? null;

                // Only target queries or mutations
                if ($parentType !== 'Query' && $parentType !== 'Mutation') {
                    return null;
                }

                $contentType = $config['extensions']['strapi']['contentType'] ?? null;

                if ($contentType === null) {
                    $registryType = $typeRegistry?->get($config['type'] ?? null);

                    if ($registryType === null) {
                        return null;
                    }

                    $contentType = $registryType['config']['contentType'] ?? null;
                }

                // Ignore non-localized content types
                if (!$contentTypesService->isLocalizedContentType($contentType)) {
                    return null;
                }

                if (!is_array($config['args'] ?? null)) {
                    $config['args'] = [];
                }

                $config['args']['locale'] = Nexus::arg([
                    'type' => self::LOCALE_SCALAR_TYPENAME,
                    'description' => 'The locale to use for the query',
                ]);

                return $config;
            },
        ]);
    }
}

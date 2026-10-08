<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Utils\AST;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\UnionDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ScalarTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\UnionTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/services/builders/dynamic-zones.ts */
final class DynamicZones
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param list<string> $components */
    private function buildTypeDefinition(string $name, array $components): UnionTypeDef
    {
        $strapi = $this->strapi;
        $isEmpty = count($components) === 0;

        $componentsTypeNames = array_map(static function (string $componentUID) use ($strapi): string {
            $component = $strapi->components()[$componentUID] ?? null;

            if ($component === null) {
                throw new ApplicationError("Trying to create a dynamic zone type with an unknown component: \"{$componentUID}\"");
            }

            return $component->globalId;
        }, $components);

        return Nexus::unionType([
            'name' => $name,

            'resolveType' => static function (mixed $obj) use ($strapi, $isEmpty): ?string {
                if ($isEmpty) {
                    return Constants::ERROR_TYPE_NAME;
                }

                $uid = is_array($obj) ? (string) ($obj['__component'] ?? '') : '';

                return ($strapi->components()[$uid] ?? null)?->globalId;
            },

            'definition' => static function (UnionDefinitionBlock $t) use ($componentsTypeNames): void {
                $t->members(...[...$componentsTypeNames, Constants::ERROR_TYPE_NAME]);
            },
        ]);
    }

    /** @param list<string> $components */
    private function buildInputDefinition(string $name, array $components): ScalarTypeDef
    {
        $strapi = $this->strapi;

        $parseData = static function (mixed $value) use ($strapi, $components): array {
            $typename = is_array($value) ? ($value['__typename'] ?? null) : null;
            $component = null;
            foreach ($strapi->components() as $candidate) {
                if ($candidate->globalId === $typename) {
                    $component = $candidate;
                    break;
                }
            }

            if ($component === null) {
                $expected = implode(', ', array_map(static function (string $uid) use ($strapi): string {
                    $component = $strapi->components()[$uid] ?? null;

                    return $component !== null ? $component->globalId : 'undefined';
                }, $components));

                throw new ApplicationError("Component not found. expected one of: {$expected}");
            }

            $rest = is_array($value) ? $value : [];
            unset($rest['__typename']);

            return [
                '__component' => $component->uid,
                ...$rest,
            ];
        };

        return Nexus::scalarType([
            'name' => $name,

            'serialize' => static fn (mixed $value): mixed => $value,

            'parseValue' => static fn (mixed $value): array => $parseData($value),

            'parseLiteral' => static function (Node $ast, ?array $variables = null) use ($parseData): ?array {
                if (!$ast instanceof ObjectValueNode) {
                    return null;
                }

                $value = AST::valueFromASTUntyped($ast, $variables);

                return $parseData($value);
            },
        ]);
    }

    /**
     * Build a Nexus dynamic zone type from a Strapi dz attribute
     *
     * @param array<string, mixed> $definition
     * @return array{0: UnionTypeDef, 1: ScalarTypeDef}
     */
    public function buildDynamicZoneDefinition(array $definition, string $name, string $inputName): array
    {
        $components = array_values(array_map('strval', is_array($definition['components'] ?? null) ? $definition['components'] : []));

        $typeDefinition = $this->buildTypeDefinition($name, $components);
        $inputDefinition = $this->buildInputDefinition($inputName, $components);

        return [$typeDefinition, $inputDefinition];
    }
}

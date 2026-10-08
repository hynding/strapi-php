<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib;

use GraphQL\Error\Error;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\ValidationRule;

/**
 * `graphql-depth-limit` 1.1 as a validation rule: reports
 * `'<operationName>' exceeds maximum operation depth of <maxDepth>` for operations nested deeper
 * than `maxDepth`. Like the JS package, a non-numeric limit (`undefined`) never triggers;
 * introspection fields (`__*`) and `options.ignore` fields are not counted.
 */
final class GraphqlDepthLimit extends ValidationRule
{
    /** @var callable|null */
    private $callback;

    /**
     * @param array{ignore?: list<string|callable>} $options
     */
    public function __construct(private readonly mixed $maxDepth, private readonly array $options = [], ?callable $callback = null)
    {
        $this->name = 'depthLimit';
        $this->callback = $callback;
    }

    public function getVisitor(QueryValidationContext $context): array
    {
        $definitions = $context->getDocument()->definitions;

        $fragments = [];
        $queries = [];
        foreach ($definitions as $definition) {
            if ($definition instanceof FragmentDefinitionNode) {
                $fragments[$definition->name->value] = $definition;
            } elseif ($definition instanceof OperationDefinitionNode) {
                $queries[$definition->name !== null ? $definition->name->value : ''] = $definition;
            }
        }

        $queryDepths = [];
        foreach ($queries as $name => $query) {
            $queryDepths[$name] = $this->determineDepth($query, $fragments, 0, $context, (string) $name);
        }

        if ($this->callback !== null) {
            ($this->callback)($queryDepths);
        }

        return [];
    }

    /** `depthSoFar > maxDepth` with JS number semantics (non-numbers compare false) */
    private function exceeds(int $depthSoFar): bool
    {
        $max = $this->maxDepth;
        if (is_string($max) && is_numeric($max)) {
            $max = (float) $max;
        }

        return (is_int($max) || is_float($max)) && !is_nan((float) $max) && $depthSoFar > $max;
    }

    private function seeIfIgnored(FieldNode $node): bool
    {
        foreach ($this->options['ignore'] ?? [] as $rule) {
            $fieldName = $node->name->value;
            if (is_string($rule) && $rule === $fieldName) {
                return true;
            }
            if (is_string($rule) && @preg_match($rule, '') !== false && preg_match($rule, $fieldName) === 1) {
                return true;
            }
            if (is_callable($rule) && $rule($fieldName)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, FragmentDefinitionNode> $fragments */
    private function determineDepth(?Node $node, array $fragments, int $depthSoFar, QueryValidationContext $context, string $operationName): int
    {
        if ($node === null) {
            return 0;
        }

        if ($this->exceeds($depthSoFar)) {
            $maxDepth = is_scalar($this->maxDepth) ? (string) $this->maxDepth : '';
            $context->reportError(new Error("'{$operationName}' exceeds maximum operation depth of {$maxDepth}", [$node]));

            return 0;
        }

        if ($node instanceof FieldNode) {
            // by default, ignore the introspection fields which begin with double underscores
            $shouldIgnore = str_starts_with($node->name->value, '__') || $this->seeIfIgnored($node);

            if ($shouldIgnore || $node->selectionSet === null) {
                return 0;
            }

            $depths = [];
            foreach ($node->selectionSet->selections as $selection) {
                $depths[] = $this->determineDepth($selection, $fragments, $depthSoFar + 1, $context, $operationName);
            }

            return 1 + ($depths === [] ? 0 : max($depths));
        }

        if ($node instanceof FragmentSpreadNode) {
            return $this->determineDepth($fragments[$node->name->value] ?? null, $fragments, $depthSoFar, $context, $operationName);
        }

        if ($node instanceof InlineFragmentNode || $node instanceof FragmentDefinitionNode || $node instanceof OperationDefinitionNode) {
            $depths = [];
            foreach ($node->selectionSet->selections as $selection) {
                $depths[] = $this->determineDepth($selection, $fragments, $depthSoFar, $context, $operationName);
            }

            return $depths === [] ? 0 : max($depths);
        }

        throw new \RuntimeException('uh oh! depth crawler cannot handle: ' . $node->kind);
    }
}

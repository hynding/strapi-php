<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Strapi\Upgrade\Modules\Runner\Code\TransformAPI;

/**
 * Port of resources/codemods/5.0.0/entity-service-document-service.code.ts.
 *
 * This codemod transforms entity service calls to match the new document service interface.
 * It supports all kind of argument parsing, including spread elements & deeply nested arrays.
 *
 *   $strapi->entityService()->findOne($uid, $entityId, ['fields' => [...], 'publicationState' => 'preview']);
 *   // becomes
 *   $strapi->documents($uid)->findOne(['documentId' => '__TODO__', 'fields' => [...], 'status' => 'draft']);
 *
 * Upstream's scenarios carry over with PHP's spellings: arguments given through a variable
 * declared elsewhere (`$params = [...]`), spread arrays (`...$args`, `...[$id, $params]`),
 * nested spreads in arrays, and `findMany` / `count` / `create` (no entity id) vs `findOne` /
 * `update` / `delete` (entity id replaced by a `documentId` placeholder). The receiver is any
 * PHP spelling of upstream's `strapi` (`$strapi`, `$this->strapi`, `strapi()`).
 *
 * @param array{path: string, source: string} $file
 */
return static function (array $file, TransformAPI $api): string {
    $parsed = $api->parse($file['source']);

    $transformer = new class ($api, $parsed[0]) extends NodeVisitorAbstract {
        private const MOVED_FUNCTIONS = ['findOne', 'findMany', 'count', 'create', 'update', 'delete'];

        private const FUNCTIONS_WITH_ENTITY_ID = ['findOne', 'update', 'delete'];

        public bool $changed = false;

        /** @var \SplObjectStorage<Node, null> */
        private \SplObjectStorage $visited;

        /** @param array<Node> $root */
        public function __construct(private readonly TransformAPI $api, private readonly array $root)
        {
            $this->visited = new \SplObjectStorage();
        }

        /** find the first `$name = …` assignment in the scope that declares `$name` */
        private function findClosestDeclaration(Node $node, string $name): ?Assign
        {
            $scope = $node->getAttribute('parent');
            // arrow functions share their parent's scope
            while ($scope instanceof Node && (!$scope instanceof FunctionLike || $scope instanceof Expr\ArrowFunction)) {
                $scope = $scope->getAttribute('parent');
            }

            $search = $scope instanceof FunctionLike ? ($scope->getStmts() ?? []) : $this->root;
            $found = $this->api->finder->findFirst($search, static fn (Node $n): bool => $n instanceof Assign && $n->var instanceof Variable && $n->var->name === $name);

            return $found instanceof Assign ? $found : null;
        }

        private function transformElement(Node $path, Node $element): void
        {
            if ($this->visited->contains($element)) {
                return;
            }
            $this->visited->attach($element);

            if ($element instanceof Array_) {
                $this->transformObjectParam($path, $element);
            } elseif ($element instanceof Variable && is_string($element->name)) {
                $declaration = $this->findClosestDeclaration($path, $element->name);
                if ($declaration !== null) {
                    $this->transformElement($path, $declaration->expr);
                }
            }
        }

        private static function draftOrPublished(String_ $value): String_
        {
            return new String_($value->value === 'live' ? 'published' : 'draft', ['kind' => $value->getAttribute('kind', String_::KIND_SINGLE_QUOTED)]);
        }

        private function transformObjectParam(Node $path, Array_ $expression): void
        {
            foreach ($expression->items as $prop) {
                // spread elements and list elements (upstream's array elements)
                if ($prop->unpack || $prop->key === null) {
                    $this->transformElement($path, $prop->value);
                    continue;
                }

                if (!$prop->key instanceof String_ || $prop->key->value !== 'publicationState') {
                    continue;
                }

                $prop->key = new String_('status', ['kind' => $prop->key->getAttribute('kind', String_::KIND_SINGLE_QUOTED)]);

                if ($prop->value instanceof String_) {
                    $prop->value = self::draftOrPublished($prop->value);
                } elseif ($prop->value instanceof Variable && is_string($prop->value->name)) {
                    $declaration = $this->findClosestDeclaration($path, $prop->value->name);
                    if ($declaration !== null && $declaration->expr instanceof String_) {
                        $declaration->expr = self::draftOrPublished($declaration->expr);
                    }
                }
            }
        }

        /**
         * @param list<array{0: Expr, 1: bool}> $args [value, unpack]
         * @param list<string> $seen
         * @return list<array{0: Expr, 1: bool}>
         */
        private function resolveArgs(Node $path, array $args, array $seen = []): array
        {
            $resolved = [];
            foreach ($args as [$value, $unpack]) {
                if (!$unpack) {
                    $resolved[] = [$value, false];
                    continue;
                }

                if ($value instanceof Variable && is_string($value->name) && !in_array($value->name, $seen, true)) {
                    $declaration = $this->findClosestDeclaration($path, $value->name);
                    if ($declaration !== null && $declaration->expr instanceof Array_) {
                        $resolved = [...$resolved, ...$this->resolveArgs($path, self::items($declaration->expr), [...$seen, $value->name])];
                        continue;
                    }
                } elseif ($value instanceof Array_) {
                    $resolved = [...$resolved, ...$this->resolveArgs($path, self::items($value), $seen)];
                    continue;
                }

                $resolved[] = [$value, true];
            }

            return $resolved;
        }

        /** @return list<array{0: Expr, 1: bool}> */
        private static function items(Array_ $array): array
        {
            return array_values(array_map(static fn (ArrayItem $i): array => [$i->value, $i->unpack], $array->items));
        }

        public function leaveNode(Node $node): ?Node
        {
            if (!$node instanceof MethodCall
                || !$node->name instanceof Identifier
                || !in_array($node->name->name, self::MOVED_FUNCTIONS, true)
                || !$node->var instanceof MethodCall
                || !TransformAPI::isStrapiMethodCall($node->var, 'entityService')
            ) {
                return null;
            }

            $receiver = $node->var->var;
            $args = array_values(array_filter($node->args, static fn (Node $a): bool => $a instanceof Arg));

            if ($args === []) {
                // we don't know how to transform this
                return null;
            }

            $resolvedArgs = $this->resolveArgs($node, array_map(static fn (Arg $a): array => [$a->value, $a->unpack], $args));

            $docUID = array_shift($resolvedArgs);
            $rest = $resolvedArgs;

            // function with entityId as first argument
            if (in_array($node->name->name, self::FUNCTIONS_WITH_ENTITY_ID, true)) {
                array_splice($rest, 0, 1);

                // in case no extra params are passed in the function e.g delete($uid, $entityId)
                if ($rest === []) {
                    $rest[] = [new Array_([], ['kind' => Array_::KIND_SHORT]), false];
                }

                $params = $rest[0][0];

                $placeholder = new ArrayItem(new String_('__TODO__'), new String_('documentId'));

                // add documentId to params with a placeholder
                if ($params instanceof Array_) {
                    array_unshift($params->items, $placeholder);
                } elseif ($params instanceof Variable && is_string($params->name)) {
                    $declaration = $this->findClosestDeclaration($node, $params->name);

                    if ($declaration === null) {
                        return null;
                    }

                    if ($declaration->expr instanceof Array_) {
                        array_unshift($declaration->expr->items, $placeholder);
                    }
                }
            }

            foreach ($args as $arg) {
                $this->transformElement($node, $arg->value);
            }

            $this->changed = true;

            return new MethodCall(
                new MethodCall($receiver, 'documents', $docUID === null ? [] : [new Arg($docUID[0], false, $docUID[1])]),
                $node->name,
                array_map(static fn (array $a): Arg => new Arg($a[0], false, $a[1]), $rest)
            );
        }
    };

    $parsed[0] = (new NodeTraverser($transformer))->traverse($parsed[0]);

    return $transformer->changed ? $api->print($parsed) : $file['source'];
};

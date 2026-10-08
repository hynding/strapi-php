<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Code;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Token;

/**
 * PHP-only: the `api` a PHP code codemod receives, the counterpart of jscodeshift's `api`
 * (`api.jscodeshift`, `api.stats`, `api.report`). Sources are parsed with nikic/php-parser and
 * printed back with its format-preserving printer, so only the nodes a codemod replaces are
 * reformatted (jscodeshift's recast does the same for JS).
 */
final class TransformAPI
{
    private readonly Parser $parser;

    private readonly Standard $printer;

    public readonly NodeFinder $finder;

    /** @var array<string, int> */
    public array $stats = [];

    /** @var list<string> */
    public array $reports = [];

    public function __construct(public readonly string $cwd)
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->printer = new Standard();
        $this->finder = new NodeFinder();
    }

    /**
     * Parses a PHP source. The returned statements are a copy whose nodes know their `parent`
     * (attribute) and whose names are resolved (`resolvedName` / `namespacedName` attributes);
     * modify them and pass the whole triple to `print()`.
     *
     * @return array{0: array<Node>, 1: array<Node>, 2: array<int, Token>} [stmts, original stmts, tokens]
     */
    public function parse(string $source): array
    {
        $oldStmts = $this->parser->parse($source) ?? [];
        $oldTokens = $this->parser->getTokens();

        $traverser = new NodeTraverser(new CloningVisitor());
        $stmts = $traverser->traverse($oldStmts);

        $traverser = new NodeTraverser(new NameResolver(null, ['replaceNodes' => false]), new ParentConnectingVisitor());
        $stmts = $traverser->traverse($stmts);

        return [$stmts, $oldStmts, $oldTokens];
    }

    /** @param array{0: array<Node>, 1: array<Node>, 2: array<int, Token>} $parsed */
    public function print(array $parsed): string
    {
        [$stmts, $oldStmts, $oldTokens] = $parsed;

        return $this->printer->printFormatPreserving($stmts, $oldStmts, $oldTokens);
    }

    /** Pretty-prints a new node (for messages and tests). */
    public function printNode(Node $node): string
    {
        return $node instanceof Node\Expr ? $this->printer->prettyPrintExpr($node) : $this->printer->prettyPrint([$node]);
    }

    /**
     * Upstream's codemods match the global `strapi` identifier; its PHP spellings are a `$strapi`
     * variable, the `$this->strapi` property (services, controllers) and a `strapi()` call.
     */
    public static function isStrapi(Node $node): bool
    {
        return match (true) {
            $node instanceof Node\Expr\Variable => $node->name === 'strapi',
            $node instanceof Node\Expr\PropertyFetch, $node instanceof Node\Expr\NullsafePropertyFetch => $node->var instanceof Node\Expr\Variable && $node->var->name === 'this' && $node->name instanceof Node\Identifier && $node->name->name === 'strapi',
            $node instanceof Node\Expr\FuncCall => $node->name instanceof Node\Name && $node->name->toLowerString() === 'strapi' && $node->args === [],
            default => false,
        };
    }

    /** a `<strapi>-><name>()` call without arguments (`$strapi->config()`, `$this->strapi->entityService()`) */
    public static function isStrapiMethodCall(Node $node, string $name): bool
    {
        return $node instanceof Node\Expr\MethodCall
            && $node->name instanceof Node\Identifier
            && $node->name->name === $name
            && $node->args === []
            && self::isStrapi($node->var);
    }

    /** jscodeshift `api.stats(name, quantity)` */
    public function stats(string $name, int $quantity = 1): void
    {
        $this->stats[$name] = ($this->stats[$name] ?? 0) + $quantity;
    }

    /** jscodeshift `api.report(message)` */
    public function report(string $message): void
    {
        $this->reports[] = $message;
    }
}

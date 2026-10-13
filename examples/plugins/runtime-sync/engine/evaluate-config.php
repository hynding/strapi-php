<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\Process\Process;

/**
 * Mirrors engine/evaluate-config.js: turns a config file into the IR (see Ir). Each language
 * is read by its own runtime: a `.php` file is parsed here with nikic/php-parser (no evaluation:
 * `$env(...)` calls stay references, so secrets never land in the generated file), a `.js`/`.ts`
 * file is evaluated by Node (engine/bin/evaluate-config.js, with a recording `env`). Each CLI
 * calls the other interpreter for the other language.
 *
 * @phpstan-type Doc array{source: string, form: string, value: mixed, warnings: list<string>}
 */
final class EvaluateConfig
{
    /** @return Doc */
    public static function evaluate(string $root, string $relPath): array
    {
        $ext = Files::extension($relPath);
        if ($ext === 'php') {
            return self::evaluatePhp($root, $relPath);
        }
        if (Spec::runtimeOfExtension($ext) === 'node') {
            return self::evaluateWithNode($root, $relPath);
        }

        throw new \RuntimeException("{$relPath} is not a config file of either runtime");
    }

    /** @return Doc */
    public static function evaluateWithNode(string $root, string $relPath): array
    {
        $node = getenv('RUNTIME_SYNC_NODE') ?: 'node';
        $command = [$node];
        if (in_array(Files::extension($relPath), ['ts', 'mts'], true)) {
            $command[] = '--experimental-strip-types';
            $command[] = '--no-warnings';
        }
        $root = realpath($root) ?: $root;
        $command[] = __DIR__ . '/bin/evaluate-config.js';
        $command[] = $root;
        $command[] = $relPath;

        $process = new Process($command, $root, null, null, 60);
        $process->run();
        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new \RuntimeException("Node could not evaluate {$relPath}: {$message}");
        }

        return Ir::decode($process->getOutput());
    }

    /** @return Doc */
    public static function evaluatePhp(string $root, string $relPath): array
    {
        $code = Files::read($root . '/' . $relPath);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code) ?? [];

        $ctx = new EvaluatePhpContext($relPath);
        $doc = ['source' => $relPath, 'form' => 'value', 'value' => null, 'warnings' => []];
        $returned = false;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Declare_ || $stmt instanceof Stmt\Use_ || $stmt instanceof Stmt\Nop || $stmt instanceof Stmt\InlineHTML) {
                continue;
            }
            if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Assign && $stmt->expr->var instanceof Expr\Variable && is_string($stmt->expr->var->name)) {
                $ctx->vars[$stmt->expr->var->name] = $ctx->toIr($stmt->expr->expr);
                continue;
            }
            if ($stmt instanceof Stmt\Return_ && $stmt->expr !== null && !$returned) {
                $returned = true;
                $expr = $stmt->expr;
                if ($expr instanceof Expr\ArrowFunction) {
                    $doc['form'] = 'function';
                    $ctx->envVar = self::firstParamName($expr) ?? 'env';
                    $doc['value'] = $ctx->toIr($expr->expr);
                } elseif ($expr instanceof Expr\Closure) {
                    $doc['form'] = 'function';
                    $ctx->envVar = self::firstParamName($expr) ?? 'env';
                    $doc['value'] = self::closureBody($expr, $ctx);
                } else {
                    $doc['value'] = $ctx->toIr($expr);
                }
                continue;
            }
            $ctx->warnings[] = 'statement ignored: ' . self::oneLine((new Standard())->prettyPrint([$stmt]));
        }

        if (!$returned) {
            $ctx->warnings[] = 'no return statement found';
        }
        $doc['warnings'] = $ctx->warnings;

        return $doc;
    }

    private static function firstParamName(Expr\ArrowFunction|Expr\Closure $fn): ?string
    {
        $param = $fn->params[0] ?? null;

        return $param instanceof Node\Param && $param->var instanceof Expr\Variable && is_string($param->var->name) ? $param->var->name : null;
    }

    private static function closureBody(Expr\Closure $fn, EvaluatePhpContext $ctx): mixed
    {
        $value = null;
        foreach ($fn->stmts as $stmt) {
            if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Assign && $stmt->expr->var instanceof Expr\Variable && is_string($stmt->expr->var->name)) {
                $ctx->vars[$stmt->expr->var->name] = $ctx->toIr($stmt->expr->expr);
            } elseif ($stmt instanceof Stmt\Return_ && $stmt->expr !== null) {
                $value = $ctx->toIr($stmt->expr);
            } elseif (!$stmt instanceof Stmt\Nop) {
                $ctx->warnings[] = 'statement ignored: ' . self::oneLine((new Standard())->prettyPrint([$stmt]));
            }
        }

        return $value;
    }

    public static function oneLine(string $code): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $code));
    }
}

/**
 * The walk over one PHP config file: the variables assigned so far (inlined where used), the
 * name of the env parameter, and the warnings collected.
 *
 * @internal
 */
final class EvaluatePhpContext
{
    /** @var array<string, mixed> */
    public array $vars = [];

    public string $envVar = 'env';

    /** @var list<string> */
    public array $warnings = [];

    private Standard $printer;

    public function __construct(private readonly string $relPath)
    {
        $this->printer = new Standard();
    }

    public function toIr(Expr $expr): mixed
    {
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Scalar\Int_) {
            return $expr->value;
        }
        if ($expr instanceof Scalar\Float_) {
            return $expr->value;
        }
        if ($expr instanceof Expr\UnaryMinus && ($expr->expr instanceof Scalar\Int_ || $expr->expr instanceof Scalar\Float_)) {
            return -$expr->expr->value;
        }
        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            if ($name === 'true' || $name === 'false') {
                return $name === 'true';
            }
            if ($name === 'null') {
                return null;
            }

            return $this->todo($expr);
        }
        if ($expr instanceof Expr\Array_) {
            return $this->arrayToIr($expr);
        }
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            if (array_key_exists($expr->name, $this->vars)) {
                return $this->vars[$expr->name];
            }

            return $this->todo($expr);
        }
        if ($expr instanceof Expr\FuncCall) {
            return $this->callToIr($expr);
        }
        if ($expr instanceof Expr\MethodCall && $expr->var instanceof Expr\Variable && $expr->var->name === $this->envVar && $expr->name instanceof Node\Identifier) {
            return $this->envToIr($expr->name->toString(), $expr->args);
        }
        if ($expr instanceof Scalar\MagicConst\Dir) {
            return $this->dirToIr(0);
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $parts = [];
            foreach ([$expr->left, $expr->right] as $side) {
                $part = $this->toIr($side);
                if (Ir::isConcat($part)) {
                    array_push($parts, ...$part->{'$concat'});
                } else {
                    $parts[] = $part;
                }
            }

            return Ir::concat($parts);
        }

        return $this->todo($expr);
    }

    private function arrayToIr(Expr\Array_ $expr): mixed
    {
        $isList = true;
        foreach ($expr->items as $item) {
            if ($item->key !== null || $item->unpack) {
                $isList = false;
            }
        }
        if ($isList) {
            $list = [];
            foreach ($expr->items as $item) {
                $list[] = $this->toIr($item->value);
            }

            return $list;
        }

        $object = new \stdClass();
        foreach ($expr->items as $item) {
            if ($item->unpack || $item->key === null) {
                return $this->todo($expr);
            }
            $key = $item->key;
            if ($key instanceof Scalar\String_) {
                $object->{$key->value} = $this->toIr($item->value);
            } elseif ($key instanceof Scalar\Int_) {
                $object->{(string) $key->value} = $this->toIr($item->value);
            } else {
                return $this->todo($expr);
            }
        }

        return $object;
    }

    private function callToIr(Expr\FuncCall $expr): mixed
    {
        // $env('KEY', default)
        if ($expr->name instanceof Expr\Variable && $expr->name->name === $this->envVar) {
            return $this->envToIr('string', $expr->args);
        }
        // dirname(__DIR__[, levels])
        if ($expr->name instanceof Node\Name && strtolower($expr->name->toString()) === 'dirname') {
            $first = $expr->args[0] ?? null;
            $second = $expr->args[1] ?? null;
            if ($first instanceof Node\Arg && $first->value instanceof Scalar\MagicConst\Dir) {
                $levels = $second instanceof Node\Arg && $second->value instanceof Scalar\Int_ ? $second->value->value : ($second === null ? 1 : null);
                if ($levels !== null) {
                    return $this->dirToIr($levels);
                }
            }
        }

        return $this->todo($expr);
    }

    /**
     * @param array<Node\Arg|Node\ArgPlaceholder|Node\VariadicPlaceholder> $args
     */
    private function envToIr(string $type, array $args): mixed
    {
        $values = [];
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->name !== null || $arg->unpack) {
                return $this->todo(new Expr\MethodCall(new Expr\Variable($this->envVar), $type, $args));
            }
            $values[] = $arg->value;
        }
        if (!in_array($type, Ir::ENV_TYPES, true) || !isset($values[0]) || !$values[0] instanceof Scalar\String_) {
            return $this->todo(new Expr\MethodCall(new Expr\Variable($this->envVar), $type, $args));
        }
        $key = $values[0]->value;

        if ($type === 'oneOf') {
            $expected = isset($values[1]) ? $this->toIr($values[1]) : null;
            if (!is_array($expected)) {
                return $this->todo(new Expr\MethodCall(new Expr\Variable($this->envVar), $type, $args));
            }
            $hasDefault = isset($values[2]);

            return Ir::env('oneOf', $key, $hasDefault ? $this->toIr($values[2]) : null, $hasDefault, array_values(array_map(strval(...), $expected)));
        }

        $hasDefault = isset($values[1]);

        return Ir::env($type, $key, $hasDefault ? $this->toIr($values[1]) : null, $hasDefault);
    }

    /** `__DIR__` (levels 0) or `dirname(__DIR__, levels)` as the project root plus a relative directory. */
    private function dirToIr(int $levels): mixed
    {
        $segments = array_values(array_filter(explode('/', dirname($this->relPath)), static fn (string $s): bool => $s !== '' && $s !== '.'));
        $kept = array_slice($segments, 0, max(0, count($segments) - $levels));

        return $kept === [] ? Ir::root() : Ir::concat([Ir::root(), '/' . implode('/', $kept)]);
    }

    private function todo(Expr $expr): \stdClass
    {
        $source = EvaluateConfig::oneLine($this->printer->prettyPrintExpr($expr));
        $this->warnings[] = "could not translate: {$source}";

        return Ir::todo($source, 'php');
    }
}

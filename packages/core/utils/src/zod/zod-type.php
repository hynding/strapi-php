<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.ZodType`, the base of every schema. Schemas are immutable: every builder method returns a
 * modified clone. Parsing follows zod's `_zod.run`: the type's own parse, then its checks.
 *
 * - `parse($data)` returns the parsed value or throws {@see ZodError};
 * - `safeParse($data)` returns `['success' => true, 'data' => ..., 'error' => null]` or
 *   `['success' => false, 'data' => null, 'error' => ZodError]`;
 * - calling either without an argument parses JavaScript's `undefined` ({@see Undefined}).
 *
 * `def()` exposes zod's `_zod.def` (`type`, `checks`, and per-type fields such as `shape`,
 * `element`, `innerType`, `options`) for code that inspects schemas.
 */
abstract class ZodType
{
    /** @var list<ZodCheck> */
    protected array $checks = [];

    /** The schema's `error` param: `fn(array $issue): string|array{message: string}|null`. */
    protected ?\Closure $error = null;

    protected ?string $description = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    /** zod's `def.type`: `string`, `number`, `object`, `optional`, `pipe`, ... */
    abstract public function type(): string;

    /** The type's own parse (zod's `_zod.parse`), before checks. */
    abstract protected function parseType(ParsePayload $payload): ParsePayload;

    // --- parsing -------------------------------------------------------------------------------

    public function parse(mixed $data = Undefined::Value): mixed
    {
        $result = $this->run(new ParsePayload($data));
        if ($result->issues !== []) {
            throw new ZodError(array_map(Util::finalizeIssue(...), $result->issues));
        }

        return Util::output($result->value);
    }

    /** @return array{success: bool, data: mixed, error: ZodError|null} */
    public function safeParse(mixed $data = Undefined::Value): array
    {
        $result = $this->run(new ParsePayload($data));
        if ($result->issues !== []) {
            return ['success' => false, 'data' => null, 'error' => new ZodError(array_map(Util::finalizeIssue(...), $result->issues))];
        }

        return ['success' => true, 'data' => Util::output($result->value), 'error' => null];
    }

    /** zod's `_zod.run`: type parse, then checks. Internal, used by composite schemas. */
    public function run(ParsePayload $payload): ParsePayload
    {
        $result = $this->parseType($payload);
        if ($this->checks === []) {
            return $result;
        }

        return $this->runChecks($result);
    }

    private function runChecks(ParsePayload $payload): ParsePayload
    {
        $isAborted = $payload->isAborted();
        foreach ($this->checks as $check) {
            if ($check->when !== null) {
                if ($payload->isExplicitlyAborted()) {
                    continue;
                }
                if (!($check->when)($payload)) {
                    continue;
                }
            } elseif ($isAborted) {
                continue;
            }
            $before = count($payload->issues);
            $check->run($payload);
            if (count($payload->issues) !== $before && !$isAborted) {
                $isAborted = $payload->isAborted($before);
            }
        }

        return $payload;
    }

    /** Push an issue raised by this schema itself (zod's `inst` is the schema). */
    /** @param array<string, mixed> $issue */
    protected function issue(ParsePayload $payload, array $issue): ParsePayload
    {
        if (!array_key_exists('input', $issue)) {
            $issue['input'] = $payload->value;
        }
        $issue['inst'] = $this;
        $payload->issues[] = $issue;

        return $payload;
    }

    // --- introspection -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function def(): array
    {
        return ['type' => $this->type(), 'checks' => $this->checks];
    }

    /** @return list<ZodCheck> */
    public function checks(): array
    {
        return $this->checks;
    }

    public function errorMap(): ?\Closure
    {
        return $this->error;
    }

    /** `schema.description` */
    public function description(): ?string
    {
        return $this->description;
    }

    /** True when an absent input is acceptable (zod's `optin === "optional"`). */
    public function isOptionalIn(): bool
    {
        return false;
    }

    /** True when the output may be absent (zod's `optout === "optional"`). */
    public function isOptionalOut(): bool
    {
        return false;
    }

    /**
     * The finite set of accepted values (zod's `_zod.values`), or null.
     *
     * @return list<mixed>|null
     */
    public function values(): ?array
    {
        return null;
    }

    /**
     * Literal values per property (zod's `_zod.propValues`), used by discriminated unions.
     *
     * @return array<string, list<mixed>>|null
     */
    public function propValues(): ?array
    {
        return null;
    }

    public function isOptional(): bool
    {
        return $this->safeParse(Undefined::Value)['success'];
    }

    public function isNullable(): bool
    {
        return $this->safeParse(null)['success'];
    }

    // --- builders ------------------------------------------------------------------------------

    /** @param string|array<string, mixed>|null $params */
    protected function withParams(string|array|null $params): static
    {
        $this->error = Util::normalizeParams($params)['error'];

        return $this;
    }

    public function check(ZodCheck|\Closure ...$checks): static
    {
        $clone = clone $this;
        foreach ($checks as $check) {
            $clone->checks[] = $check instanceof ZodCheck
                ? $check
                : new ZodCheck(static function (ParsePayload $payload) use ($check): void {
                    $check($payload);
                }, ['check' => 'custom']);
        }

        return $clone;
    }

    /** @param string|array<string, mixed>|null $params */
    public function refine(\Closure $check, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::refine($check, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function superRefine(\Closure $refinement, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::superRefine($refinement, $params));
    }

    public function overwrite(\Closure $fn): static
    {
        return $this->check(ZodCheck::overwrite($fn));
    }

    public function optional(): ZodOptional
    {
        return new ZodOptional($this);
    }

    public function nullable(): ZodNullable
    {
        return new ZodNullable($this);
    }

    public function nullish(): ZodOptional
    {
        return new ZodOptional(new ZodNullable($this));
    }

    /** @param string|array<string, mixed>|null $params */
    public function nonoptional(string|array|null $params = null): ZodNonOptional
    {
        return new ZodNonOptional($this, $params);
    }

    public function array(): ZodArray
    {
        return new ZodArray($this);
    }

    public function or(ZodType $other): ZodUnion
    {
        return new ZodUnion([$this, $other]);
    }

    public function and(ZodType $other): ZodIntersection
    {
        return new ZodIntersection($this, $other);
    }

    /** `fn($value, ParsePayload $ctx)`; `$ctx->addIssue()` reports issues. */
    public function transform(\Closure $transform): ZodPipe
    {
        return new ZodPipe($this, new ZodTransform($transform));
    }

    public function pipe(ZodType $target): ZodPipe
    {
        return new ZodPipe($this, $target);
    }

    /** A `\Closure` default is called on each use (zod's function defaults). */
    public function default(mixed $value): ZodDefault
    {
        return new ZodDefault($this, $value);
    }

    /** Like default(), but the value is parsed by this schema. */
    public function prefault(mixed $value): ZodDefault
    {
        return new ZodDefault($this, $value, true);
    }

    public function readonly(): ZodReadonly
    {
        return new ZodReadonly($this);
    }

    /** No runtime effect, as in TypeScript. */
    public function brand(mixed ...$_): static
    {
        return $this;
    }

    public function describe(string $description): static
    {
        $clone = clone $this;
        $clone->description = $description;
        $clone->metadata['description'] = $description;

        return $clone;
    }

    /**
     * `meta()` returns the metadata; `meta([...])` returns a clone carrying it.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @return static|array<string, mixed>
     */
    public function meta(?array $metadata = null): static|array
    {
        if ($metadata === null) {
            return $this->metadata;
        }
        $clone = clone $this;
        $clone->metadata = array_merge($clone->metadata, $metadata);
        if (isset($metadata['description']) && is_string($metadata['description'])) {
            $clone->description = $metadata['description'];
        }

        return $clone;
    }

    /** @param \Closure(static): mixed $fn */
    public function apply(\Closure $fn): mixed
    {
        return $fn($this);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function toJSONSchema(array $params = []): array
    {
        return ToJsonSchema::generate($this, $params);
    }
}

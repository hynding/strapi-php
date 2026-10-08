// Oracle cases for the PHP zod subset (packages/core/utils/src/zod).
//
// Each case pairs a zod 4.4.3 schema (`js`) with its PHP translation (`php`, an expression in
// which `z` is Strapi\Utils\Zod) and a list of inputs. generate.js runs safeParse() on every
// input with the real library and writes ZodOracleCases.php, the PHPUnit data provider.
//
// Inputs are JS values. `raw(php, js)` gives an input whose PHP literal differs from the default
// conversion (e.g. `[]` passed to z.object()).

const raw = (php, js) => ({ __raw: true, php, js });

module.exports = (z) => [
  // --- string --------------------------------------------------------------------------------
  { name: 'string', js: z.string(), php: 'z::string()', inputs: ['abc', '', 1, null, undefined, true, [1], {}, raw('[]', [])] },
  { name: 'string min', js: z.string().min(3), php: 'z::string()->min(3)', inputs: ['abc', 'ab', '', 5] },
  { name: 'string max', js: z.string().max(3), php: 'z::string()->max(3)', inputs: ['abc', 'abcd', '😀😀', 'éé'] },
  { name: 'string length', js: z.string().length(3), php: 'z::string()->length(3)', inputs: ['abc', 'ab', 'abcd'] },
  { name: 'string nonempty', js: z.string().nonempty(), php: 'z::string()->nonempty()', inputs: ['a', ''] },
  { name: 'string regex', js: z.string().regex(/^[a-z]+$/), php: "z::string()->regex('/^[a-z]+$/')", inputs: ['abc', 'aB1'] },
  { name: 'string regex flags', js: z.string().regex(/^[a-z]+$/i), php: "z::string()->regex('/^[a-z]+$/i')", inputs: ['aBc', 'a-b'] },
  { name: 'string regex message', js: z.string().regex(/^\d+$/, 'digits only'), php: "z::string()->regex('/^\\\\d+$/', 'digits only')", inputs: ['12', 'x'] },
  { name: 'string min max regex', js: z.string().max(2).regex(/^\d+$/), php: "z::string()->max(2)->regex('/^\\\\d+$/')", inputs: ['abc', '12'] },
  { name: 'string email', js: z.string().email(), php: 'z::string()->email()', inputs: ['a@b.co', 'john.doe+x@example.com', 'bad', 'a@b', '.a@b.co'] },
  { name: 'string email min', js: z.string().email().min(50), php: 'z::string()->email()->min(50)', inputs: ['bad'] },
  { name: 'string uuid', js: z.string().uuid(), php: 'z::string()->uuid()', inputs: ['123e4567-e89b-12d3-a456-426614174000', 'nope', '00000000-0000-0000-0000-000000000000'] },
  { name: 'string datetime', js: z.string().datetime(), php: 'z::string()->datetime()', inputs: ['2024-01-01T00:00:00Z', '2024-01-01T00:00:00.123Z', '2024-01-01T00:00:00+01:00', '2024-01-01', '2023-02-29T00:00:00Z'] },
  { name: 'string datetime offset', js: z.string().datetime({ offset: true }), php: "z::string()->datetime(['offset' => true])", inputs: ['2024-01-01T00:00:00+01:00', '2024-01-01T00:00:00'] },
  { name: 'string trim', js: z.string().trim(), php: 'z::string()->trim()', inputs: ['  hi ', 3] },
  { name: 'string trim min', js: z.string().trim().min(1), php: 'z::string()->trim()->min(1)', inputs: ['   ', ' a '] },
  { name: 'string toLowerCase', js: z.string().toLowerCase(), php: 'z::string()->toLowerCase()', inputs: ['ABC'] },
  { name: 'string toUpperCase', js: z.string().toUpperCase(), php: 'z::string()->toUpperCase()', inputs: ['abc'] },
  { name: 'string startsWith endsWith includes', js: z.string().startsWith('a').endsWith('z').includes('m'), php: "z::string()->startsWith('a')->endsWith('z')->includes('m')", inputs: ['amz', 'xyz'] },
  { name: 'string error string', js: z.string('need a string'), php: "z::string('need a string')", inputs: [1, 'a'] },
  { name: 'string error param', js: z.string({ error: 'need string' }), php: "z::string(['error' => 'need string'])", inputs: [1] },
  { name: 'string message param', js: z.string({ message: 'need string2' }), php: "z::string(['message' => 'need string2'])", inputs: [1] },
  { name: 'string error fn', js: z.string({ error: (iss) => (iss.input === undefined ? 'Required' : undefined) }), php: "z::string(['error' => fn (array $iss) => $iss['input'] === Undefined::Value ? 'Required' : null])", inputs: [undefined, 3] },
  { name: 'string min message', js: z.string().min(2, { message: 'too short' }), php: "z::string()->min(2, ['message' => 'too short'])", inputs: ['a'] },
  { name: 'string min error fn', js: z.string().min(2, { error: (iss) => `min ${iss.minimum}` }), php: "z::string()->min(2, ['error' => fn (array $iss) => 'min ' . $iss['minimum']])", inputs: ['a'] },
  { name: 'email', js: z.email(), php: 'z::email()', inputs: ['a@b.co', 'nope', 1] },
  { name: 'uuid', js: z.uuid(), php: 'z::uuid()', inputs: ['123e4567-e89b-12d3-a456-426614174000', 'x'] },

  // --- number --------------------------------------------------------------------------------
  { name: 'number', js: z.number(), php: 'z::number()', inputs: [1, 1.5, -3, '1', null, undefined, NaN, Infinity, true] },
  { name: 'number int', js: z.number().int(), php: 'z::number()->int()', inputs: [1, 1.5, 9007199254740993, -9007199254740993] },
  { name: 'number int min', js: z.number().int().min(5), php: 'z::number()->int()->min(5)', inputs: [1.5, 3, 6] },
  { name: 'number positive', js: z.number().positive(), php: 'z::number()->positive()', inputs: [1, 0, -1] },
  { name: 'number nonnegative', js: z.number().nonnegative(), php: 'z::number()->nonnegative()', inputs: [0, -1] },
  { name: 'number negative nonpositive', js: z.number().negative().nonpositive(), php: 'z::number()->negative()->nonpositive()', inputs: [-1, 0, 2] },
  { name: 'number min max', js: z.number().min(1).max(10), php: 'z::number()->min(1)->max(10)', inputs: [1, 10, 0, 11, 2.5] },
  { name: 'number gt lt', js: z.number().gt(1).lt(10), php: 'z::number()->gt(1)->lt(10)', inputs: [1, 10, 5] },
  { name: 'number multipleOf', js: z.number().multipleOf(3), php: 'z::number()->multipleOf(3)', inputs: [9, 4] },
  { name: 'number multipleOf decimal', js: z.number().multipleOf(0.1), php: 'z::number()->multipleOf(0.1)', inputs: [0.3, 0.35] },
  { name: 'number min message', js: z.number().min(5, 'at least five'), php: "z::number()->min(5, 'at least five')", inputs: [1] },

  // --- coerce --------------------------------------------------------------------------------
  { name: 'coerce number', js: z.coerce.number(), php: 'z::coerce()->number()', inputs: ['5', '1.5', ' 42 ', '', 'abc', null, true, '0x10', '1e3', undefined] },
  { name: 'coerce number int min', js: z.coerce.number().int().min(1).optional(), php: 'z::coerce()->number()->int()->min(1)->optional()', inputs: ['3', '0', '1.5', undefined] },
  { name: 'coerce boolean', js: z.coerce.boolean(), php: 'z::coerce()->boolean()', inputs: ['false', '', 0, 1, null, undefined, 'x', []] },
  { name: 'coerce string', js: z.coerce.string(), php: 'z::coerce()->string()', inputs: [12.5, 3, true, null, undefined, [1, 2], 0.000001, 1e21, 1.5e-7] },

  // --- boolean, null, any, unknown, never ----------------------------------------------------
  { name: 'boolean', js: z.boolean(), php: 'z::boolean()', inputs: [true, false, 'true', 0, null] },
  { name: 'null', js: z.null(), php: 'z::null()', inputs: [null, undefined, 0] },
  { name: 'any', js: z.any(), php: 'z::any()', inputs: [1, null, 'x', [1]] },
  { name: 'unknown', js: z.unknown(), php: 'z::unknown()', inputs: [1, { a: 1 }] },
  { name: 'never', js: z.never(), php: 'z::never()', inputs: [1, undefined] },

  // --- literal & enum ------------------------------------------------------------------------
  { name: 'literal', js: z.literal('a'), php: "z::literal('a')", inputs: ['a', 'c', undefined] },
  { name: 'literal number', js: z.literal(2), php: 'z::literal(2)', inputs: [2, '2'] },
  { name: 'literal multi', js: z.literal(['a', 2, true, null]), php: "z::literal(['a', 2, true, null])", inputs: [2, null, 'c'] },
  { name: 'enum', js: z.enum(['a', 'b']), php: "z::enum(['a', 'b'])", inputs: ['a', 'c', 1, undefined] },
  { name: 'enum message', js: z.enum(['a', 'b'], { message: 'pick one' }), php: "z::enum(['a', 'b'], ['message' => 'pick one'])", inputs: ['c'] },

  // --- object --------------------------------------------------------------------------------
  { name: 'object', js: z.object({ a: z.string(), b: z.number().optional() }), php: "z::object(['a' => z::string(), 'b' => z::number()->optional()])", inputs: [{ a: 'x' }, { a: 'x', b: 1 }, { a: 'x', extra: true }, {}, { b: 'y' }, null, [1], 'str', undefined] },
  { name: 'object empty input', js: z.object({}), php: 'z::object([])', inputs: [{}, raw('[]', {}), raw('new \\stdClass()', {}), raw("(object) ['a' => 1]", { a: 1 }), [1]] },
  { name: 'object stdClass', js: z.object({ a: z.string() }), php: "z::object(['a' => z::string()])", inputs: [raw("(object) ['a' => 'x', 'b' => 1]", { a: 'x', b: 1 }), raw("(object) ['a' => 1]", { a: 1 })] },
  { name: 'object strict', js: z.object({ a: z.string() }).strict(), php: "z::object(['a' => z::string()])->strict()", inputs: [{ a: 'x' }, { a: 'x', b: 1 }, { a: 'x', b: 1, c: 2 }, { b: 1 }] },
  { name: 'object passthrough', js: z.object({ a: z.string() }).passthrough(), php: "z::object(['a' => z::string()])->passthrough()", inputs: [{ a: 'x', b: 1 }] },
  { name: 'object loose', js: z.object({ a: z.string() }).loose(), php: "z::object(['a' => z::string()])->loose()", inputs: [{ a: 'x', b: [1] }] },
  { name: 'looseObject', js: z.looseObject({ a: z.string() }), php: "z::looseObject(['a' => z::string()])", inputs: [{ a: 'x', b: 1 }, { b: 1 }] },
  { name: 'strictObject', js: z.strictObject({ a: z.string() }), php: "z::strictObject(['a' => z::string()])", inputs: [{ a: 'x', b: 1 }] },
  { name: 'object catchall', js: z.object({ a: z.string() }).catchall(z.number()), php: "z::object(['a' => z::string()])->catchall(z::number())", inputs: [{ a: 'x', b: 1 }, { a: 'x', b: 'y' }] },
  { name: 'object unknown key required', js: z.object({ a: z.unknown(), b: z.any().optional() }), php: "z::object(['a' => z::unknown(), 'b' => z::any()->optional()])", inputs: [{}, { a: null }] },
  { name: 'object nested paths', js: z.object({ a: z.object({ b: z.array(z.object({ c: z.number() })) }) }), php: "z::object(['a' => z::object(['b' => z::array(z::object(['c' => z::number()]))])])", inputs: [{ a: { b: [{ c: 1 }, { c: 'x' }, {}] } }, { a: { b: {} } }] },
  { name: 'object extend', js: z.object({ a: z.string() }).extend({ b: z.number(), a: z.number() }), php: "z::object(['a' => z::string()])->extend(['b' => z::number(), 'a' => z::number()])", inputs: [{ a: 1, b: 2 }, { a: 'x' }] },
  { name: 'object extend keeps refinements', js: z.object({ a: z.string() }).refine((v) => v.a !== 'bad', 'bad a').extend({ b: z.number().optional() }), php: "z::object(['a' => z::string()])->refine(fn (\$v) => \$v['a'] !== 'bad', 'bad a')->extend(['b' => z::number()->optional()])", inputs: [{ a: 'bad' }, { a: 'ok', b: 1 }] },
  { name: 'object partial', js: z.object({ a: z.string(), b: z.number() }).partial(), php: "z::object(['a' => z::string(), 'b' => z::number()])->partial()", inputs: [{}, { a: 1 }] },
  { name: 'object pick omit', js: z.object({ a: z.string(), b: z.number(), c: z.boolean() }).pick({ a: true, b: true }).omit({ b: true }), php: "z::object(['a' => z::string(), 'b' => z::number(), 'c' => z::boolean()])->pick(['a' => true, 'b' => true])->omit(['b' => true])", inputs: [{ a: 'x', b: 1, c: true }, {}] },
  { name: 'object required', js: z.object({ a: z.string().optional() }).required(), php: "z::object(['a' => z::string()->optional()])->required()", inputs: [{}, { a: 'x' }] },
  { name: 'object refine', js: z.object({ a: z.string() }).refine(() => false), php: "z::object(['a' => z::string()])->refine(fn () => false)", inputs: [{}, { a: 'x' }] },
  { name: 'object refine path', js: z.object({ pw: z.string(), confirm: z.string() }).refine((v) => v.pw === v.confirm, { message: 'Passwords do not match', path: ['confirm'] }), php: "z::object(['pw' => z::string(), 'confirm' => z::string()])->refine(fn (\$v) => \$v['pw'] === \$v['confirm'], ['message' => 'Passwords do not match', 'path' => ['confirm']])", inputs: [{ pw: 'a', confirm: 'b' }, { pw: 'a', confirm: 'a' }] },
  { name: 'object numeric keys', js: z.object({ 1: z.string() }), php: "z::object(['1' => z::string()])", inputs: [{ 1: 'x' }, { 1: 2 }] },

  // --- array ---------------------------------------------------------------------------------
  { name: 'array', js: z.array(z.string()), php: 'z::array(z::string())', inputs: [['a'], [], ['a', 1, null], { a: 1 }, 'a'] },
  { name: 'array min', js: z.array(z.string()).min(2), php: 'z::array(z::string())->min(2)', inputs: [[1], ['a', 'b']] },
  { name: 'array max length nonempty', js: z.array(z.number()).max(1).nonempty(), php: 'z::array(z::number())->max(1)->nonempty()', inputs: [[], [1, 2]] },
  { name: 'array length', js: z.array(z.number()).length(2), php: 'z::array(z::number())->length(2)', inputs: [[1], [1, 2, 3]] },
  { name: 'schema.array()', js: z.string().array(), php: 'z::string()->array()', inputs: [['a'], ['a', 2]] },
  { name: 'array of objects strip', js: z.array(z.object({ id: z.number() })), php: "z::array(z::object(['id' => z::number()]))", inputs: [[{ id: 1, x: 2 }]] },

  // --- tuple ---------------------------------------------------------------------------------
  { name: 'tuple', js: z.tuple([z.string(), z.number()]), php: 'z::tuple([z::string(), z::number()])', inputs: [['a', 1], ['a'], ['a', 1, 2], [1, 'a'], {}] },
  { name: 'tuple optional tail', js: z.tuple([z.string(), z.number().optional()]), php: 'z::tuple([z::string(), z::number()->optional()])', inputs: [['a'], ['a', 'b']] },

  // --- union & discriminatedUnion ------------------------------------------------------------
  { name: 'union', js: z.union([z.string(), z.number()]), php: 'z::union([z::string(), z::number()])', inputs: ['a', 1, true, null] },
  { name: 'union nonaborted single', js: z.union([z.string().min(3), z.number()]), php: 'z::union([z::string()->min(3), z::number()])', inputs: ['ab'] },
  { name: 'union literals', js: z.union([z.literal('a'), z.literal('b')]), php: "z::union([z::literal('a'), z::literal('b')])", inputs: ['c', 'b'] },
  { name: 'union objects', js: z.union([z.object({ a: z.string() }), z.object({ b: z.number() })]), php: "z::union([z::object(['a' => z::string()]), z::object(['b' => z::number()])])", inputs: [{ b: 1 }, { c: 1 }] },
  { name: 'or', js: z.string().or(z.boolean()), php: 'z::string()->or(z::boolean())', inputs: [false, 1] },
  { name: 'discriminatedUnion', js: z.discriminatedUnion('type', [z.object({ type: z.literal('a'), a: z.string() }), z.object({ type: z.literal('b'), b: z.number() })]), php: "z::discriminatedUnion('type', [z::object(['type' => z::literal('a'), 'a' => z::string()]), z::object(['type' => z::literal('b'), 'b' => z::number()])])", inputs: [{ type: 'a', a: 'x' }, { type: 'b', b: 'x' }, { type: 'c' }, {}, 'x', [1]] },
  { name: 'discriminatedUnion mixed values', js: z.discriminatedUnion('t', [z.object({ t: z.literal('a') }), z.object({ t: z.literal(1) }), z.object({ t: z.enum(['x', 'y']) })]), php: "z::discriminatedUnion('t', [z::object(['t' => z::literal('a')]), z::object(['t' => z::literal(1)]), z::object(['t' => z::enum(['x', 'y'])])])", inputs: [{ t: 'y' }, { t: 2 }] },

  // --- intersection --------------------------------------------------------------------------
  { name: 'intersection', js: z.intersection(z.object({ a: z.string() }), z.object({ b: z.string() })), php: "z::intersection(z::object(['a' => z::string()]), z::object(['b' => z::string()]))", inputs: [{ a: 'x', b: 'y' }, { a: 'x' }] },
  { name: 'and', js: z.object({ a: z.string() }).strict().and(z.object({ b: z.string() }).strict()), php: "z::object(['a' => z::string()])->strict()->and(z::object(['b' => z::string()])->strict())", inputs: [{ a: 'x', b: 'y', c: 1 }] },

  // --- record --------------------------------------------------------------------------------
  { name: 'record', js: z.record(z.string(), z.number()), php: 'z::record(z::string(), z::number())', inputs: [{ a: 1 }, { a: 'x' }, {}, raw('[]', {}), [1], null] },
  { name: 'record unknown', js: z.record(z.string(), z.unknown()), php: 'z::record(z::string(), z::unknown())', inputs: [{ a: null, b: [1] }] },
  { name: 'record key schema', js: z.record(z.string().min(2), z.number()), php: 'z::record(z::string()->min(2), z::number())', inputs: [{ a: 1 }, { ab: 1 }] },
  { name: 'record enum keys', js: z.record(z.enum(['a', 'b']), z.number()), php: "z::record(z::enum(['a', 'b']), z::number())", inputs: [{ a: 1, b: 2 }, { a: 1 }, { a: 1, b: 2, c: 3 }] },
  { name: 'partialRecord', js: z.partialRecord(z.enum(['a', 'b']), z.number()), php: "z::partialRecord(z::enum(['a', 'b']), z::number())", inputs: [{ a: 1 }, { c: 1 }] },
  { name: 'record numeric keys', js: z.record(z.number(), z.string()), php: 'z::record(z::number(), z::string())', inputs: [{ 1: 'a', 2: 'b' }, { x: 'a' }] },
  { name: 'record single arg', js: z.record(z.string(), z.boolean()), php: 'z::record(z::boolean())', inputs: [{ a: true }, { a: 1 }] },

  // --- optional, nullable, nullish, default, nonoptional, readonly ---------------------------
  { name: 'optional', js: z.string().optional(), php: 'z::string()->optional()', inputs: ['a', undefined, null] },
  { name: 'nullable', js: z.string().nullable(), php: 'z::string()->nullable()', inputs: ['a', null, undefined] },
  { name: 'nullish', js: z.string().nullish(), php: 'z::string()->nullish()', inputs: [null, undefined, 1] },
  { name: 'default', js: z.string().default('d'), php: "z::string()->default('d')", inputs: [undefined, 'x', null, 1] },
  { name: 'default fn', js: z.array(z.string()).default(() => ['fn']), php: "z::array(z::string())->default(fn () => ['fn'])", inputs: [undefined] },
  { name: 'default in object', js: z.object({ a: z.string().default('x'), b: z.boolean().optional().default(false) }), php: "z::object(['a' => z::string()->default('x'), 'b' => z::boolean()->optional()->default(false)])", inputs: [{}, { a: 'y', b: true }] },
  { name: 'nonoptional', js: z.string().optional().nonoptional(), php: 'z::string()->optional()->nonoptional()', inputs: [undefined, 'a'] },
  { name: 'nonoptional string', js: z.string().nonoptional(), php: 'z::string()->nonoptional()', inputs: [undefined] },
  { name: 'readonly', js: z.object({ a: z.string() }).readonly(), php: "z::object(['a' => z::string()])->readonly()", inputs: [{ a: 'x' }, { a: 1 }] },

  // --- refine, superRefine, custom -----------------------------------------------------------
  { name: 'refine', js: z.string().refine((v) => v.length > 2), php: 'z::string()->refine(fn ($v) => strlen($v) > 2)', inputs: ['abc', 'ab', 1] },
  { name: 'refine message string', js: z.string().refine(() => false, 'bad2'), php: "z::string()->refine(fn () => false, 'bad2')", inputs: ['x'] },
  { name: 'refine error param', js: z.string().refine(() => false, { error: 'bad3' }), php: "z::string()->refine(fn () => false, ['error' => 'bad3'])", inputs: ['x'] },
  { name: 'refine path', js: z.string().refine(() => false, { message: 'bad', path: ['p'] }), php: "z::string()->refine(fn () => false, ['message' => 'bad', 'path' => ['p']])", inputs: ['x'] },
  { name: 'refine after failed check', js: z.string().min(3).refine(() => false, 'refined'), php: "z::string()->min(3)->refine(fn () => false, 'refined')", inputs: ['a'] },
  { name: 'refine abort', js: z.string().refine(() => false, { message: 'first', abort: true }).refine(() => false, 'second'), php: "z::string()->refine(fn () => false, ['message' => 'first', 'abort' => true])->refine(fn () => false, 'second')", inputs: ['a'] },
  { name: 'refine chain', js: z.number().refine((v) => v > 0, 'positive').refine((v) => v % 2 === 0, 'even'), php: "z::number()->refine(fn (\$v) => \$v > 0, 'positive')->refine(fn (\$v) => \$v % 2 === 0, 'even')", inputs: [-1, 3, 4] },
  { name: 'superRefine', js: z.string().superRefine((v, ctx) => { ctx.addIssue({ code: 'custom', message: 'm1', path: ['x'] }); ctx.addIssue('m2'); ctx.addIssue({ code: 'too_small', minimum: 3, origin: 'string', inclusive: true }); }), php: "z::string()->superRefine(function (\$v, \$ctx) { \$ctx->addIssue(['code' => 'custom', 'message' => 'm1', 'path' => ['x']]); \$ctx->addIssue('m2'); \$ctx->addIssue(['code' => 'too_small', 'minimum' => 3, 'origin' => 'string', 'inclusive' => true]); })", inputs: ['a'] },
  { name: 'superRefine ZodIssueCode', js: z.object({ a: z.array(z.string()) }).superRefine((v, ctx) => { v.a.forEach((s, i) => { if (s === 'dup') ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['a', i], message: `Duplicate ${s}` }); }); }), php: "z::object(['a' => z::array(z::string())])->superRefine(function (\$v, \$ctx) { foreach (\$v['a'] as \$i => \$s) { if (\$s === 'dup') { \$ctx->addIssue(['code' => ZodIssueCode::custom, 'path' => ['a', \$i], 'message' => \"Duplicate {\$s}\"]); } } })", inputs: [{ a: ['x', 'dup', 'dup'] }, { a: ['x'] }, { a: 'x' }] },
  { name: 'superRefine fatal', js: z.string().superRefine((v, ctx) => { ctx.addIssue({ code: 'custom', message: 'fatal', fatal: true }); }).refine(() => false, 'not reached'), php: "z::string()->superRefine(function (\$v, \$ctx) { \$ctx->addIssue(['code' => 'custom', 'message' => 'fatal', 'fatal' => true]); })->refine(fn () => false, 'not reached')", inputs: ['a'] },
  { name: 'custom', js: z.custom((v) => typeof v === 'string' && v.includes(':')), php: "z::custom(fn (\$v) => is_string(\$v) && str_contains(\$v, ':'))", inputs: ['a:b', 'abc', 1] },
  { name: 'custom message', js: z.custom(() => false, 'custom msg'), php: "z::custom(fn () => false, 'custom msg')", inputs: ['abc'] },
  { name: 'custom no fn', js: z.custom(), php: 'z::custom()', inputs: ['abc', undefined] },
  { name: 'custom aborts refine', js: z.custom(() => false).refine(() => false, 'second'), php: "z::custom(fn () => false)->refine(fn () => false, 'second')", inputs: [1] },

  // --- transform, preprocess, pipe -----------------------------------------------------------
  { name: 'transform', js: z.string().transform((v) => v.length), php: 'z::string()->transform(fn ($v) => strlen($v))', inputs: ['abc', 1] },
  { name: 'transform then pipe', js: z.string().transform((v) => Number(v)).pipe(z.number().max(3)), php: "z::string()->transform(fn (\$v) => (int) \$v)->pipe(z::number()->max(3))", inputs: ['2', '5'] },
  { name: 'transform addIssue', js: z.string().transform((v, ctx) => { ctx.addIssue({ code: 'custom', message: 'tx' }); return z.NEVER; }), php: "z::string()->transform(function (\$v, \$ctx) { \$ctx->addIssue(['code' => 'custom', 'message' => 'tx']); return z::NEVER; })", inputs: ['a'] },
  { name: 'transform in object missing', js: z.object({ a: z.string().transform((x) => x) }), php: "z::object(['a' => z::string()->transform(fn (\$x) => \$x)])", inputs: [{}] },
  { name: 'transform optional in object', js: z.object({ a: z.string().optional().transform((x) => x ?? 'none') }), php: "z::object(['a' => z::string()->optional()->transform(fn (\$x) => \$x === Undefined::Value ? 'none' : \$x)])", inputs: [{}, { a: 'y' }] },
  { name: 'preprocess', js: z.preprocess((v) => Number(v), z.number().max(3)), php: "z::preprocess(fn (\$v) => is_numeric(\$v) ? \$v + 0 : \$v, z::number()->max(3))", inputs: ['5', '2'] },
  { name: 'preprocess identity optional', js: z.object({ c: z.preprocess((v) => v, z.string().optional()) }), php: "z::object(['c' => z::preprocess(fn (\$v) => \$v, z::string()->optional())])", inputs: [{}, { c: 1 }] },
  { name: 'preprocess split', js: z.preprocess((v) => (typeof v === 'string' ? v.split(',') : v), z.array(z.string()).min(2)), php: "z::preprocess(fn (\$v) => is_string(\$v) ? explode(',', \$v) : \$v, z::array(z::string())->min(2))", inputs: ['a,b', 'a', 3] },

  // --- nesting -------------------------------------------------------------------------------
  { name: 'union in object', js: z.object({ a: z.union([z.string(), z.number()]) }), php: "z::object(['a' => z::union([z::string(), z::number()])])", inputs: [{ a: true }, { a: 2 }] },
  { name: 'record in object', js: z.object({ opts: z.record(z.string(), z.object({ on: z.boolean() })) }), php: "z::object(['opts' => z::record(z::string(), z::object(['on' => z::boolean()]))])", inputs: [{ opts: { x: { on: 1 } } }] },
  { name: 'superRefine nested', js: z.object({ list: z.array(z.string().superRefine((v, ctx) => { if (v === 'x') ctx.addIssue({ code: 'custom', message: 'no x', path: ['inner'] }); })) }), php: "z::object(['list' => z::array(z::string()->superRefine(function (\$v, \$ctx) { if (\$v === 'x') { \$ctx->addIssue(['code' => 'custom', 'message' => 'no x', 'path' => ['inner']]); } }))])", inputs: [{ list: ['a', 'x'] }] },
  { name: 'discriminatedUnion in array', js: z.array(z.discriminatedUnion('kind', [z.object({ kind: z.literal('text'), text: z.string() }), z.object({ kind: z.literal('link'), url: z.string() })])), php: "z::array(z::discriminatedUnion('kind', [z::object(['kind' => z::literal('text'), 'text' => z::string()]), z::object(['kind' => z::literal('link'), 'url' => z::string()])]))", inputs: [[{ kind: 'text', text: 'a' }, { kind: 'link' }, { kind: 'img' }]] },
  { name: 'nullable object field', js: z.object({ a: z.string().nullable(), b: z.number().nullish() }), php: "z::object(['a' => z::string()->nullable(), 'b' => z::number()->nullish()])", inputs: [{ a: null, b: null }, {}, { a: 'x', b: 'y' }] },
  { name: 'optional union', js: z.union([z.string(), z.number()]).optional(), php: 'z::union([z::string(), z::number()])->optional()', inputs: [undefined, false] },
  { name: 'refine when', js: z.object({ a: z.string(), b: z.string() }).refine((v) => v.a === v.b, { message: 'must match', path: ['b'], when: (p) => typeof p.value.b === 'string' }), php: "z::object(['a' => z::string(), 'b' => z::string()])->refine(fn (\$v) => \$v['a'] === \$v['b'], ['message' => 'must match', 'path' => ['b'], 'when' => fn (\$p) => is_string(\$p->value['b'] ?? null)])", inputs: [{ a: 1, b: 'x' }, { a: 'y', b: 'x' }] },

  // --- lazy ----------------------------------------------------------------------------------
  { name: 'lazy', js: z.lazy(() => z.string()), php: 'z::lazy(fn () => z::string())', inputs: ['a', 1] },
  { name: 'lazy recursive', js: (() => { const Cat = z.lazy(() => z.object({ name: z.string(), children: z.array(Cat).optional() })); return Cat; })(), php: 'self::category()', inputs: [{ name: 'a', children: [{ name: 'b' }, { name: 1, children: [{}] }] }] },
];

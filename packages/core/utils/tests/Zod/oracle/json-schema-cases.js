// toJSONSchema() oracle cases: [name, zod schema or registry, PHP expression, JS params, PHP params].
// The PHP side is compared after a JSON round trip, ignoring key order.

module.exports = (z) => {
  const article = z.object({ title: z.string() });
  const withArticle = z.object({ a: article, list: z.array(article), self: z.lazy(() => withArticle).optional() });
  const registry = z.registry();
  registry.add(article, { id: 'Article' });
  registry.add(withArticle, { id: 'B' });
  const Cat = z.lazy(() => z.object({ name: z.string(), children: z.array(Cat) }));
  const Node = z.lazy(() => z.object({ name: z.string(), children: z.array(Node).optional() }));
  // ids in a schema's own .meta() (zod's global registry)
  const MetaNested = z.object({ value: z.string() }).meta({ id: 'MetaNested', description: 'nested' });
  const metaRegistry = z.registry();
  metaRegistry.add(z.object({ nested: MetaNested, other: z.object({ inner: z.string().meta({ id: 'MetaInner' }) }) }), { id: 'MetaRoot' });
  metaRegistry.add(z.object({ again: MetaNested }), { id: 'MetaOther' });

  return [
    {
      name: 'object of everything',
      js: z.object({ a: z.string().min(1).max(5).describe('A'), b: z.number().int().positive().optional(), c: z.enum(['x', 'y']), d: z.literal('k'), e: z.array(z.boolean()).min(1), f: z.string().nullable(), g: z.union([z.string(), z.number()]), h: z.record(z.string(), z.unknown()), i: z.any(), j: z.string().email(), k: z.string().uuid(), l: z.string().datetime(), m: z.string().regex(/^a+$/), n: z.number().default(3), o: z.null(), p: z.string().nullish(), q: z.literal(['a', 'b']), r: z.number().min(1).max(10), s: z.number().nonnegative() }).strict(),
      php: "z::object(['a' => z::string()->min(1)->max(5)->describe('A'), 'b' => z::number()->int()->positive()->optional(), 'c' => z::enum(['x', 'y']), 'd' => z::literal('k'), 'e' => z::array(z::boolean())->min(1), 'f' => z::string()->nullable(), 'g' => z::union([z::string(), z::number()]), 'h' => z::record(z::string(), z::unknown()), 'i' => z::any(), 'j' => z::string()->email(), 'k' => z::string()->uuid(), 'l' => z::string()->datetime(), 'm' => z::string()->regex('/^a+$/'), 'n' => z::number()->default(3), 'o' => z::null(), 'p' => z::string()->nullish(), 'q' => z::literal(['a', 'b']), 'r' => z::number()->min(1)->max(10), 's' => z::number()->nonnegative()])->strict()",
    },
    { name: 'passthrough', js: z.object({ a: z.string() }).passthrough(), php: "z::object(['a' => z::string()])->passthrough()" },
    { name: 'input io', js: z.object({ a: z.string().default('x'), b: z.string().optional() }), php: "z::object(['a' => z::string()->default('x'), 'b' => z::string()->optional()])", params: { io: 'input' }, phpParams: "['io' => 'input']" },
    { name: 'discriminatedUnion', js: z.discriminatedUnion('t', [z.object({ t: z.literal('a') }), z.object({ t: z.literal('b'), x: z.number() })]), php: "z::discriminatedUnion('t', [z::object(['t' => z::literal('a')]), z::object(['t' => z::literal('b'), 'x' => z::number()])])" },
    { name: 'recursive root', js: Cat, php: 'self::cat()' },
    { name: 'recursive nested', js: z.object({ root: Node }), php: "z::object(['root' => self::node()])" },
    { name: 'never', js: z.never(), php: 'z::never()' },
    { name: 'intersection', js: z.intersection(z.object({ a: z.string() }), z.object({ b: z.string() })), php: "z::intersection(z::object(['a' => z::string()]), z::object(['b' => z::string()]))" },
    { name: 'tuple', js: z.tuple([z.string(), z.number()]), php: 'z::tuple([z::string(), z::number()])' },
    { name: 'readonly', js: z.string().readonly(), php: 'z::string()->readonly()' },
    { name: 'partialRecord', js: z.partialRecord(z.enum(['a', 'b']), z.string()), php: "z::partialRecord(z::enum(['a', 'b']), z::string())" },
    { name: 'record enum keys', js: z.record(z.enum(['a', 'b']), z.string()), php: "z::record(z::enum(['a', 'b']), z::string())" },
    { name: 'preprocess', js: z.preprocess((v) => v, z.string()), php: 'z::preprocess(fn ($v) => $v, z::string())', params: { unrepresentable: 'any' }, phpParams: "['unrepresentable' => 'any']" },
    { name: 'transform any', js: z.string().transform((x) => x.length), php: 'z::string()->transform(fn ($x) => strlen($x))', params: { unrepresentable: 'any' }, phpParams: "['unrepresentable' => 'any']" },
    { name: 'string length', js: z.string().length(4), php: 'z::string()->length(4)' },
    { name: 'number max lt', js: z.number().max(3).lt(2), php: 'z::number()->max(3)->lt(2)' },
    { name: 'int', js: z.number().int(), php: 'z::number()->int()' },
    { name: 'meta', js: z.boolean().meta({ title: 'T' }), php: "z::boolean()->meta(['title' => 'T'])" },
    { name: 'describe + meta', js: z.object({ a: z.string() }).describe('Obj').meta({ title: 'T', example: 1 }), php: "z::object(['a' => z::string()])->describe('Obj')->meta(['title' => 'T', 'example' => 1])" },
    { name: 'openapi nullable', js: z.string().nullable(), php: 'z::string()->nullable()', params: { target: 'openapi-3.0' }, phpParams: "['target' => 'openapi-3.0']" },
    { name: 'draft-07', js: z.string(), php: 'z::string()', params: { target: 'draft-07' }, phpParams: "['target' => 'draft-07']" },
    { name: 'optional enum describe', js: z.enum(['a', 'b']).optional().describe('E'), php: "z::enum(['a', 'b'])->optional()->describe('E')" },
    { name: 'literal mixed', js: z.literal(['a', 1]), php: "z::literal(['a', 1])" },
    { name: 'literal null', js: z.literal(null), php: 'z::literal(null)' },
    { name: 'metadata registry', js: z.object({ x: article }), php: "z::object(['x' => self::article()])", params: { metadata: registry }, phpParams: "['metadata' => self::registry()]" },
    { name: 'meta id nested', js: z.object({ a: MetaNested, b: MetaNested }), php: "z::object(['a' => self::metaNested(), 'b' => self::metaNested()])" },
    { name: 'registry meta id __shared', js: metaRegistry, php: 'self::metaRegistry()', params: { target: 'draft-2020-12', io: 'output', uri: (id) => `#/components/schemas/${id}` }, phpParams: "['target' => 'draft-2020-12', 'io' => 'output', 'uri' => fn (string \$id) => \"#/components/schemas/{\$id}\"]" },
    { name: 'describe on wrappers (key order)', js: z.object({ a: z.string().default('d').optional().describe('x'), b: z.number().int().readonly().describe('r'), c: z.boolean().nullable().optional().describe('n'), d: z.string().describe('in').default('d').describe('out'), e: z.string().meta({ title: 'T' }).optional().describe('m'), f: z.union([z.string(), z.number()]).optional().describe('u') }), php: "z::object(['a' => z::string()->default('d')->optional()->describe('x'), 'b' => z::number()->int()->readonly()->describe('r'), 'c' => z::boolean()->nullable()->optional()->describe('n'), 'd' => z::string()->describe('in')->default('d')->describe('out'), 'e' => z::string()->meta(['title' => 'T'])->optional()->describe('m'), 'f' => z::union([z::string(), z::number()])->optional()->describe('u')])" },
    { name: 'date default', js: z.string().default(new Date(0)), php: "z::string()->default(new \\DateTimeImmutable('@0'))" },
    { name: 'enum integer-like values first', js: z.enum(['t', '1', 'true', 'f', '0', 'false']), php: "z::enum(['t', '1', 'true', 'f', '0', 'false'])" },
    { name: 'registry', js: registry, php: 'self::registry()', params: { target: 'draft-2020-12', io: 'output', uri: (id) => `#/components/schemas/${id}` }, phpParams: "['target' => 'draft-2020-12', 'io' => 'output', 'uri' => fn (string \$id) => \"#/components/schemas/{\$id}\"]" },
  ];
};

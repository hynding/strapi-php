# Porting a feature package (admin, content-manager, upload, plugins…)

`AGENTS.md` has the naming rules. This note covers what is specific to the feature packages,
whose server half lives in `server/src/` and is loaded as a module (`admin`) or a plugin.

## Files and classes

| Upstream | PHP |
| --- | --- |
| `packages/core/<pkg>/server/src/<dir>/<name>.ts` | `packages/core/<pkg>/server/src/<dir>/<name>.php` |
| `packages/core/<pkg>/shared/<dir>/<name>.ts` | `packages/core/<pkg>/shared/<dir>/<name>.php` |
| `packages/core/<pkg>/strapi-server.js` | `packages/core/<pkg>/strapi-server.php` (returns the module array) |
| `server/src/**/__tests__/<name>.test.ts` | `tests/<Dir>/<Name>Test.php` |

Namespace: the package namespace (`composer.json` `extra.strapi.namespace`, e.g. `Strapi\Admin`)
plus StudlyCase of each directory *below* `server/src/` (or `shared/`, which adds `Shared`).
Class name: StudlyCase of the file name; an `index.ts` takes its directory's name. Keep upstream's
file name casing (`content-types/User.ts` → `content-types/User.php`, class `User`).

- `server/src/services/user.ts` → `Strapi\Admin\Services\User`
- `server/src/services/permission/engine.ts` → `Strapi\Admin\Services\Permission\Engine`
- `server/src/services/permission/sections-builder/index.ts` → `Strapi\Admin\Services\Permission\SectionsBuilder\SectionsBuilder`
- `shared/utils/session-auth.ts` → `Strapi\Admin\Shared\Utils\SessionAuth`

Barrel `index.ts` files that only re-export are not ported, *except* the registries a module
needs (`services/index.ts`, `controllers/index.ts`, `routes/index.ts`, `content-types/index.ts`,
`policies/index.ts`, `middlewares/index.ts`): those become plain PHP files that `return` the map,
and `strapi-server.php` / `server/src/index.php` require them.

## Shapes

| Upstream | PHP |
| --- | --- |
| service = object of functions using the global `strapi` | `final class` with `__construct(private readonly Strapi $strapi)` and public methods of the same names; the registry entry is `static fn (Strapi $strapi) => new User($strapi)` |
| service with nested objects (`permission.actionProvider`, `permission.engine`) | public readonly properties (or methods) of the same names on the service class |
| controller = object of `async (ctx) => {}` | `final class` with `__construct(Strapi $strapi)` and `public function find(Context $ctx): mixed` per action; registry entry as for services |
| factory `export default ({ strapi }) => ({...})` | same class; the factory call becomes the constructor |
| module of exported functions (`validation/*.ts`, `utils/*.ts`, `domain/*.ts`) | `final class` with `public static` methods of the same names; exported constants become class constants |
| `getService('user')` (`server/src/utils`) | `$this->strapi->service('admin::user')`; a typed helper is fine |
| route files (`routes/*.ts`) | files returning the same arrays |
| content-type schema objects | files returning the schema array; `content-types/index.php` returns `['user' => ['schema' => ...]]` |
| policies `(ctx, config, { strapi }) => bool` | `static fn (PolicyContext $policyCtx, array $config, Strapi $strapi): bool` |
| middlewares `(config, { strapi }) => (ctx, next) => {}` | `static fn (array $config, Strapi $strapi): callable` returning `fn (Context $ctx, callable $next): void` |
| `async`/`await` | synchronous calls |
| `yup` / `validateYupSchema` | `Strapi\Utils\Yup`, `Strapi\Utils\Validators` |
| `zod` / `validateZodSchema` | `Strapi\Utils\Zod as z` (`z::object([...])`) |
| `errors.ApplicationError` & co. | `Strapi\Utils\Errors\*` |
| `@strapi/permissions` | `Strapi\Permissions\*` (packages/core/permissions) |
| `undefined` in JSON | omit the key; `null` stays `null` |
| `new Date().toISOString()` | `Strapi\Utils\Sessions::toISOString()` format `Y-m-d\TH:i:s.v\Z` |
| `crypto.randomBytes(n).toString('hex')` | `bin2hex(random_bytes($n))` |
| `bcryptjs` | `password_hash($p, PASSWORD_BCRYPT, ['cost' => 10])` / `password_verify` (same `$2y$`/`$2a$`/`$2b$` hashes) |

Wire formats are the contract (AGENTS.md rule 6): status codes, error names and messages,
response envelopes, cookie names and attributes, JWT claims, the order of keys in JSON bodies
where upstream tests compare whole objects.

## Verifying

1. Port the package's `__tests__` unit tests to PHPUnit.
2. Run the upstream HTTP suite for the package with `tests/api` (see its README):
   `cd tests/api && npm run jest -- <upstream>/tests/api/core/<pkg>`. Those tests are the
   oracle; when one fails, fix the port, don't patch the test.
3. Code that depends on a package not ported yet (e.g. `@strapi/data-transfer`) is ported with
   the missing call stubbed to throw `Strapi\Utils\Errors\NotImplementedError`, and listed in
   the package README's "stubbed" table.
4. Never read or port anything under an `ee/` directory: it is not MIT (see VERSIONING.md).

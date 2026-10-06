# strapi/permissions

Permission engine: CASL-style abilities with Mongo-style conditions (port of `@strapi/permissions`).

| | |
| --- | --- |
| Upstream | [`@strapi/permissions`](https://github.com/strapi/strapi/tree/develop/packages/core/permissions) |
| Namespace | `Strapi\Permissions\` |
| Status | `foundation` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the repository
root for the naming rules. There is no CASL or sift in PHP, so `engine/abilities/` carries a port
of the subset Strapi uses (`@casl/ability` 6.7.5 semantics, `sift` 16 operators).

## Ported files

| Upstream | PHP | Public API |
| --- | --- | --- |
| `domain/permission/index.ts` | `src/domain/permission/index.php` | `Domain\Permission\Permission::create($attributes)`, `sanitizePermissionFields()`, `getDefaultPermission()`, `addCondition($condition, $permission = null)` (curried when `$permission` is omitted), `getProperty($property, $permission = null)`. Permissions are arrays `{ action, actionParameters?, subject?, properties?, conditions? }`. |
| `engine/index.ts` | `src/engine/index.php` | `Engine\Engine::new(['providers' => ['action' => ..., 'condition' => ...], 'abilityBuilderFactory' => ?])` → `hooks()`, `on($hook, $handler)`, `generateAbility($permissions, $options)`, `createRegisterFunction($can, $options)`, `setCreateRegisterFunction()` (test seam). Hooks: `before-format::validate.permission`, `format.permission`, `after-format::validate.permission`, `before-evaluate.permission`, `before-register.permission`. A condition is an array/object with a callable `handler(array $context): bool|array`. |
| `engine/hooks.ts` | `src/engine/hooks.php` + `src/engine/hooks/*.php` | `Engine\Hooks::createEngineHooks()`, `createValidateContext()`, `createBeforeEvaluateContext()`, `createWillRegisterContext()`; contexts `ValidateContext` (`permission()`), `BeforeEvaluateContext` (`permission()`, `addCondition()`), `WillRegisterContext` (`permission()`, `options()`, `->condition->and()/or()`, array access to options). |
| `engine/abilities/casl-ability.ts` | `src/engine/abilities/casl-ability.php` + `custom-ability-builder.php` | `CaslAbility::caslAbilityBuilder()` → `CustomAbilityBuilder` (`can($permissionRule)`, `buildParametrizedAction()`, `build()`); `CaslAbility::conditionsMatcher()`, `ALLOWED_OPERATIONS`. |
| `@casl/ability` (subset) | `src/engine/abilities/ability.php`, `ability-builder.php`, `rule.php`, `subject.php`, `typed-subject.php` | `Ability::can($action, $subject = 'all', $field = null)`, `cannot()`, `rules()`, `rulesAsArrays()`, `rulesFor()`, `possibleRulesFor()`, `relevantRuleFor()`, and the `@casl/ability/extra` helpers `permittedFieldsOf($action, $subject, $fieldsFrom)` and `rulesToQuery($action, $subjectType, $convert)`. `Rule` exposes `action`, `subject`, `fields`, `conditions`, `inverted`, `matchesConditions()`, `matchesField()`. `Subject::subject($type, $entity)` / `Subject::detectSubjectType()` tag and read subject types (`__caslSubjectType__`, `uid`, `__type`). Actions may be strings or parametrized `['name' => ..., 'params' => [...]]` (serialized with `Qs::stringify`). |
| `sift` (subset) | `src/engine/abilities/sift.php` | `Sift::createQueryTester($query, $operations)`, `Sift::matches()`: `$and`, `$or`, `$not`, `$eq`, `$ne`, `$in`, `$nin`, `$gt`, `$gte`, `$lt`, `$lte`, `$exists`, `$elemMatch`, implicit equality (incl. "array contains"), deep object-literal equality, dot paths through arrays. Unsupported operators throw `Unsupported operation: $x`, surfaced by `Rule::conditionsMatcher()` as upstream's "RBAC condition uses unsupported operator" error. |

Barrel files (`index.ts` of `domain/`, `engine/abilities/`, the package root) and `types.ts` are
not ported; `global.d.ts` is TypeScript only.

## Deviations from upstream

- CASL validation that happens at `AbilityBuilder.can()` time (empty `fields` array) is raised by
  `Rule`'s constructor with the same message.
- `rulesToQuery` follows CASL 6.7.5 (the version Strapi pins): inverted rules go under `$and`
  *unnegated*, negation being the converter's job, exactly as the admin `query-builders.ts` expects.
- Conditions are evaluated in memory when `can()` receives a tagged entity; against a bare subject
  type a conditional rule counts as matching unless inverted (CASL `Rule.matchesConditions`).

# strapi/generators

Code generators for APIs, controllers, services, policies (port of @strapi/generators)

| | |
| --- | --- |
| Upstream | [`@strapi/generators`](https://github.com/strapi/strapi/tree/develop/packages/generators/generators) |
| Namespace | `Strapi\Generators\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

- `Generators::generate($name, $answers, ['dir' => $projectRoot])` runs a generator without
  prompting (the content-type builder's `generateAPI()` uses it, as upstream).
- `strapi generate` (packages/core/strapi, `src/cli/commands/generate.php`) is the interactive
  runner, `Generators::runCLI()`.

```
strapi generate                                   # pick a generator, answer its questions
strapi generate api                               # one generator
strapi generate api my-api false                  # plop's positional bypass, in prompt order (`_` = ask)
strapi generate policy -n -- --id=is-owner --destination=root
strapi generate content-type -n -- --displayName="Blog Post" --destination=new \
    --attributes=title:string,status:enumeration:draft|published,cover:media:single
strapi generate plugin -n -- --pluginId=my-plugin --typescript=false
```

Generated files are the PHP-project equivalents of upstream's JS/TS output (see
`examples/getstarted/src`): controllers/services/routers from `Strapi\Core\Factories`, action
arrays, route arrays, policies and middlewares returning closures, `schema.json`, migrations in
`database/migrations/<timestamp>.<name>.php` returning `['up' => fn (Connection $trx, Database $db) => ...]`.

## Port status

### Ported

| Upstream | PHP |
| --- | --- |
| `src/index.ts` (`generate`, `runCLI`) | `src/index.php` (`Generators`) |
| `src/plopfile.ts` | `src/plopfile.php` (`pluralize` helper; generators) |
| `src/plops/{api,content-type,controller,middleware,migration,policy,service}.ts` | `src/plops/*.php` |
| `src/plops/prompts/*.ts` | `src/plops/prompts/*.php` |
| `src/plops/utils/{content-structure,extend-plugin-index-files,get-file-path,get-formatted-date,validate-*}.ts` | `src/plops/utils/*.php` |
| `src/templates/{js,ts}/**/*.hbs` | `src/templates/php/**/*.tpl` (one PHP set) |
| `__tests__/content-type.test.ts`, `content-type-folders.test.ts`, `get-folder-prompts.test.ts` | `tests/Plops/ContentTypeTest.php`, `ContentTypeFoldersTest.php`, `GetFolderPromptsTest.php` |
| `utils/__tests__/get-file-path.test.ts`, `extend-plugin-index.files.test.ts` | `tests/Plops/Utils/GetFilePathTest.php`, `ExtendPluginIndexFilesTest.php` (PHP index files) |

`tests/GeneratorsTest.php` snapshots every generator's output; `tests/RunCliTest.php` covers the
interactive runner (typed answers, bypass, `--no-interaction`).

### PHP-port additions

| File | Stands in for |
| --- | --- |
| `src/plop.php` (`Plop`) | the minimal Plop API `generate()` builds upstream (`setGenerator`, `setHelper`, `getDestBasePath`, `setWelcomeMessage`) |
| `src/inquirer.php`, `src/console-inquirer.php` | inquirer, on symfony/console's question helper, with plop's bypass answers and `--no-interaction` defaults |
| `src/template.php` (`Template`) | handlebars: `{{ name }}` and `{{ helper name }}`, no HTML escaping (adds a `json` helper for `schema.json`) |
| `src/plops/plugin.php` | `npx @strapi/sdk-plugin init`: `strapi generate plugin` scaffolds a plugin in `src/plugins/<id>` — `composer.json` (`extra.strapi.kind = plugin`), `strapi-server.php`, the sdk-plugin server template in PHP, and (admin panel: yes) sdk-plugin's `package.json` (`./strapi-admin` export) and `admin/` template, TS or JS. Same layout as `examples/plugins/announcements` for the PHP half. |
| `src/plops/utils/plugin-index-actions.php` | the "create/extend the plugin index files" actions upstream repeats in each generator |
| `src/plops/utils/files.php` | fs-extra `outputFile` / `outputJSON` |
| `src/plops/utils/pluralize.php` | npm `pluralize` (a copy of the content-type builder's; that package depends on this one) |

### Not ported

| File | Why |
| --- | --- |
| `src/plops/utils/get-generator-language.ts` (+ test) | TS/JS detection: there is one (PHP) template set. |
| `generate()`'s `plopFile` option | there is one plopfile. |

### Differences

- `add` actions fail with `File already exists` when the target exists (plop's behaviour;
  upstream's own `generate()` overwrites). `skipIfExists` skips.
- `appendToFile` edits the array a PHP index file returns (tokenizer) instead of a JS AST:
  `'name' => require __DIR__ . '/name.php'`, content types as
  `['schema' => json_decode(file_get_contents(...))]`, and routes spread into
  `routes/content-api/index.php` (`...(require ...)['routes']`, or `->routes($strapi)` for a
  core router).
- Folder choices are scalar (`existing:<id>` / `new`) instead of `{ kind, id }` objects.
- The content-type generator's dynamic prompts accept `--attributes=name:type,...` when not
  interactive (upstream cannot bypass them).
- `console.warn` from the prompts goes to the console output (`Inquirer::warn()`).

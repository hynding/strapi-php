# strapi/plugin-color-picker

Color picker custom field (port of @strapi/plugin-color-picker server)

| | |
| --- | --- |
| Upstream | [`@strapi/plugin-color-picker`](https://github.com/strapi/strapi/tree/develop/packages/plugins/color-picker) |
| Namespace | `Strapi\Plugin\ColorPicker\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`server/src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules. The package is an installed plugin (`composer.json`
`extra.strapi.kind = plugin`, `name = color-picker`): a project that requires it gets the plugin.

The admin half (the color input) is the upstream `@strapi/plugin-color-picker` admin bundle.

## Port status

### Ported

| Upstream | PHP |
| --- | --- |
| `server/src/index.ts` | `server/src/index.php` (the module: `register`) |
| `server/src/register.ts` | `server/src/register.php` (`Register`: registers the `plugin::color-picker.color` custom field, type `string`) |

Upstream has no server tests; `tests/RegisterTest.php` checks the registration (once, with
upstream's options) and the plugin discovery.

The `examples/getstarted` project used to register the field itself while this plugin was a
placeholder; it no longer does (registering it twice throws).

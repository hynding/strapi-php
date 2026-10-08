# strapi/api-tests

Helpers for running the upstream Jest HTTP suite against a PHP instance (port of @strapi/api-tests)

| | |
| --- | --- |
| Upstream | [`@strapi/api-tests`](https://github.com/strapi/strapi/tree/develop/packages/utils/api-tests) |
| Namespace | `Strapi\ApiTests\\` |
| Status | `foundation` (replay bridge for `tests/api`) |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the
repository root for the naming rules.

`src/bridge.php` replays the JS side's recorded `strapi.*` chains (see `tests/api/README.md`);
`src/spy.php` (`Strapi\ApiTests\Spy`) stands in for a registered service while a test holds a
`jest.spyOn()` on one of its methods, calling the jest mock back in the test process.

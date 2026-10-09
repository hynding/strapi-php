# hynding/create-strapi

Alias of [`hynding/create-strapi-app`](../create-strapi-app/README.md): generate a new Strapi
application (PHP edition).

| | |
| --- | --- |
| Upstream | [`create-strapi`](https://github.com/strapi/strapi/tree/develop/packages/cli/create-strapi) |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Not published: it exists for parity with upstream's `create-strapi`. Use
`composer create-project hynding/create-strapi-app my-project`, which takes the same options.

Like upstream's `bin/index.js` (`require('create-strapi-app/bin')`), `bin/create-strapi` runs
`create-strapi-app`; every option is documented there. With `composer create-project` the
`post-create-project-cmd` script runs `create-strapi-app --in-place`, which replaces this
package's files with the new project.

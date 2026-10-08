# strapi/create-strapi

Alias of [`strapi/create-strapi-app`](../create-strapi-app/README.md): generate a new Strapi
application (PHP edition).

| | |
| --- | --- |
| Upstream | [`create-strapi`](https://github.com/strapi/strapi/tree/develop/packages/cli/create-strapi) |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

```sh
composer create-project strapi/create-strapi my-project     # add --stability=beta during a beta
# or, installed globally
composer global require strapi/create-strapi && create-strapi my-project --quickstart
```

Like upstream's `bin/index.js` (`require('create-strapi-app/bin')`), `bin/create-strapi` runs
`create-strapi-app`; every option is documented there. With `composer create-project` the
`post-create-project-cmd` script runs `create-strapi-app --in-place`, which replaces this
package's files with the new project.

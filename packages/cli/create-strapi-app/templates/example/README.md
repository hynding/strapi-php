# 🚀 Getting started with Strapi

This is a [Strapi](https://strapi.io) application served by [strapi-php](https://github.com/hynding/strapi-php), the PHP port of Strapi: same REST and admin API, same database schema, and the upstream React admin panel (`@strapi/admin`, installed with npm and built with Node).

Strapi comes with a full featured [Command Line Interface](https://docs.strapi.io/dev-docs/cli) (CLI) which lets you scaffold and manage your project in seconds.

### `develop`

Start your Strapi application in development mode: the admin panel is built, PHP sources are re-read on every request. [Learn more](https://docs.strapi.io/dev-docs/cli#strapi-develop)

```
php bin/strapi develop
# or
composer develop
```

### `start`

Start your Strapi application with PHP's built-in web server. [Learn more](https://docs.strapi.io/dev-docs/cli#strapi-start)

```
php bin/strapi start
# or
composer start
```

### `build`

Build your admin panel (needs Node.js and `npm install`). [Learn more](https://docs.strapi.io/dev-docs/cli#strapi-build)

```
php bin/strapi build
# or
composer build
```

## ⚙️ Deployment

`public/index.php` is the front controller. Serve the `public/` directory with PHP-FPM (`try_files $uri /index.php$is_args$args;`) or with [FrankenPHP](https://frankenphp.dev) in worker mode, where the application boots once per worker:

```
frankenphp php-server --root public/ --worker public/index.php --listen :1337
```

Cron jobs under PHP-FPM: schedule `php bin/strapi cron:run` with system cron.

## 📚 Learn more

- [strapi-php](https://github.com/hynding/strapi-php) - The PHP port: status, versioning, porting notes.
- [Resource center](https://strapi.io/resource-center) - Strapi resource center.
- [Strapi documentation](https://docs.strapi.io) - Official Strapi documentation.
- [Strapi tutorials](https://strapi.io/tutorials) - List of tutorials made by the core team and the community.
- [Strapi blog](https://strapi.io/blog) - Official Strapi blog containing articles made by the Strapi team and the community.
- [Changelog](https://strapi.io/changelog) - Find out about the Strapi product updates, new features and general improvements.

Feel free to check out the [Strapi GitHub repository](https://github.com/strapi/strapi). Your feedback and contributions are welcome!

## ✨ Community

- [Discord](https://discord.strapi.io) - Come chat with the Strapi community including the core team.
- [Forum](https://forum.strapi.io/) - Place to discuss, ask questions and find answers, show your Strapi project and get feedback or just talk with other Community members.
- [Awesome Strapi](https://github.com/strapi/awesome-strapi) - A curated list of awesome things related to Strapi.

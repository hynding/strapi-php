# getstarted (PHP edition)

The upstream `examples/getstarted` project served by the PHP port. Content types, components and
config keep upstream's files and values (`src/api/*/content-types/*/schema.json` are copied
verbatim); JavaScript modules become PHP files returning the same shapes.

```sh
cp .env.example .env            # then edit the secrets
php bin/strapi start            # development server (PHP built-in server)
php bin/strapi routes:list      # every registered route
php bin/strapi build            # builds the @strapi/admin bundle into build/ (needs Node)
```

Production: point PHP-FPM or FrankenPHP at `public/index.php` (worker mode is detected automatically).
Cron jobs under FPM: schedule `php bin/strapi cron:run` with system cron.

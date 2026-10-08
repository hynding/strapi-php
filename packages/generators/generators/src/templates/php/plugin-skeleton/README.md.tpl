# {{ title }}

{{ description }}

A [strapi-php](https://github.com/hynding/strapi-php) plugin. The server half is PHP
(`strapi-server.php` → `server/src/index.php`); the admin half, when there is one, is the same
React code a Strapi plugin has (`admin/src`, built with `npm run build`).

Enable it in `config/plugins.php`:

```php
return static fn (): array => [
    '{{ pluginId }}' => [
        'enabled' => true,
        'resolve' => './src/plugins/{{ pluginId }}',
    ],
];
```

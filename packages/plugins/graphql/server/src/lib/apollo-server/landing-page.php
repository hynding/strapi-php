<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\ApolloServer;

/**
 * @apollo/server 4.13 `ApolloServerPluginLandingPageLocalDefault` /
 * `ApolloServerPluginLandingPageProductionDefault` (plugin/landingPage/default): plugins whose
 * `serverWillStart` returns `renderLandingPage()` with the same HTML Apollo serves (embedded
 * Sandbox locally, the CDN landing page in production).
 */
final class LandingPage
{
    public const string PACKAGE_VERSION = '4.13.0';

    public const string DEFAULT_EMBEDDED_EXPLORER_VERSION = 'v3';

    public const string DEFAULT_EMBEDDED_SANDBOX_VERSION = 'v2';

    public const string DEFAULT_APOLLO_SERVER_LANDING_PAGE_VERSION = '_latest';

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed> an Apollo plugin
     */
    public static function localDefault(array $options = []): array
    {
        $options = ['embed' => true, ...$options];
        $version = $options['version'] ?? null;
        $apolloStudioEnv = $options['__internal_apolloStudioEnv__'] ?? null;
        unset($options['version'], $options['__internal_apolloStudioEnv__']);

        return self::landingPageDefault(is_string($version) ? $version : null, ['isProd' => false, 'apolloStudioEnv' => $apolloStudioEnv, ...$options]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed> an Apollo plugin
     */
    public static function productionDefault(array $options = []): array
    {
        $version = $options['version'] ?? null;
        $apolloStudioEnv = $options['__internal_apolloStudioEnv__'] ?? null;
        unset($options['version'], $options['__internal_apolloStudioEnv__']);

        return self::landingPageDefault(is_string($version) ? $version : null, ['isProd' => true, 'apolloStudioEnv' => $apolloStudioEnv, ...$options]);
    }

    /** `JSON.stringify` (undefined / null-for-undefined keys dropped by the callers) */
    private static function stringify(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /** JS `encodeURIComponent` */
    public static function encodeURIComponent(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }

    /**
     * A triple encoding! Wow! First we use JSON.stringify to turn our object into a
     * string. Then we encodeURIComponent so we don't have to stress about what
     * would happen if the config contained `</script>`. Finally, we JSON.stringify
     * it again, which in practice just wraps it in a pair of double quotes.
     *
     * @param array<string, mixed> $config
     */
    private static function encodeConfig(array $config): string
    {
        return self::stringify(self::encodeURIComponent(self::stringify(self::withoutUndefined($config))));
    }

    /**
     * `null` stands for JS `undefined` in these configs: JSON.stringify drops such keys.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>|\stdClass
     */
    private static function withoutUndefined(array $value): array|\stdClass
    {
        $out = [];
        foreach ($value as $key => $item) {
            if ($item === null) {
                continue;
            }
            $out[$key] = is_array($item) && !array_is_list($item) ? self::withoutUndefined($item) : $item;
        }

        return $out === [] ? new \stdClass() : $out;
    }

    /**
     * This function turns an object into a string and replaces <, >, &, ' with their unicode
     * chars (only the first occurrence of each, as String.prototype.replace does).
     *
     * @param array<string, mixed> $config
     */
    private static function getConfigStringForHtml(array $config): string
    {
        $json = self::stringify(self::withoutUndefined($config));
        foreach (['<' => '\\u003c', '>' => '\\u003e', '&' => '\\u0026', "'" => '\\u0027'] as $search => $replace) {
            $pos = strpos($json, $search);
            if ($pos !== false) {
                $json = substr_replace($json, $replace, $pos, strlen($search));
            }
        }

        return $json;
    }

    /** @param array<string, mixed> $config */
    private static function getNonEmbeddedLandingPageHTML(string $cdnVersion, array $config, string $apolloServerVersion, string $nonce): string
    {
        $encodedConfig = self::encodeConfig($config);

        return "\n <div class=\"fallback\">\n  <h1>Welcome to Apollo Server</h1>\n  <p>The full landing page cannot be loaded; it appears that you might be offline.</p>\n</div>\n<script nonce=\"{$nonce}\">window.landingPage = {$encodedConfig};</script>\n<script nonce=\"{$nonce}\" src=\"https://apollo-server-landing-page.cdn.apollographql.com/" . self::encodeURIComponent($cdnVersion) . "/static/js/main.js?runtime={$apolloServerVersion}\"></script>";
    }

    /** @param array<string, mixed> $config */
    private static function getEmbeddedExplorerHTML(string $explorerCdnVersion, array $config, string $apolloServerVersion, string $nonce): string
    {
        $embed = is_array($config['embed'] ?? null) ? $config['embed'] : [];
        $productionLandingPageEmbedConfigOrDefault = [
            'displayOptions' => [],
            'persistExplorerState' => false,
            'runTelemetry' => true,
            ...$embed,
        ];
        $initialState = [];
        if (array_key_exists('document', $config) || array_key_exists('headers', $config) || array_key_exists('variables', $config)) {
            $initialState = [
                'document' => $config['document'] ?? null,
                'headers' => $config['headers'] ?? null,
                'variables' => $config['variables'] ?? null,
            ];
        }
        if (array_key_exists('collectionId', $config)) {
            $initialState = [...$initialState, 'collectionId' => $config['collectionId'], 'operationId' => $config['operationId'] ?? null];
        }
        $displayOptions = is_array($productionLandingPageEmbedConfigOrDefault['displayOptions']) ? $productionLandingPageEmbedConfigOrDefault['displayOptions'] : [];
        $initialState['displayOptions'] = $displayOptions === [] ? new \stdClass() : $displayOptions;

        $embeddedExplorerParams = [
            'graphRef' => $config['graphRef'] ?? null,
            'target' => '#embeddableExplorer',
            'initialState' => $initialState,
            'persistExplorerState' => $productionLandingPageEmbedConfigOrDefault['persistExplorerState'],
            'includeCookies' => $config['includeCookies'] ?? null,
            'runtime' => $apolloServerVersion,
            'runTelemetry' => $productionLandingPageEmbedConfigOrDefault['runTelemetry'],
            'allowDynamicStyles' => false, // disabled for CSP - we add the iframe styles ourselves instead
        ];

        return "\n<div class=\"fallback\">\n  <h1>Welcome to Apollo Server</h1>\n  <p>Apollo Explorer cannot be loaded; it appears that you might be offline.</p>\n</div>\n<style nonce={$nonce}>\n  iframe {\n    background-color: white;\n    height: 100%;\n    width: 100%;\n    border: none;\n  }\n  #embeddableExplorer {\n    width: 100vw;\n    height: 100vh;\n    position: absolute;\n    top: 0;\n  }\n</style>\n<div id=\"embeddableExplorer\"></div>\n<script nonce=\"{$nonce}\" src=\"https://embeddable-explorer.cdn.apollographql.com/" . self::encodeURIComponent($explorerCdnVersion) . '/embeddable-explorer.umd.production.min.js?runtime=' . self::encodeURIComponent($apolloServerVersion) . "\"></script>\n<script nonce=\"{$nonce}\">\n  var endpointUrl = window.location.href;\n  var embeddedExplorerConfig = " . self::getConfigStringForHtml($embeddedExplorerParams) . ";\n  new window.EmbeddedExplorer({\n    ...embeddedExplorerConfig,\n    endpointUrl,\n  });\n</script>\n";
    }

    /** @param array<string, mixed> $config */
    private static function getEmbeddedSandboxHTML(string $sandboxCdnVersion, array $config, string $apolloServerVersion, string $nonce): string
    {
        $embed = is_array($config['embed'] ?? null) ? $config['embed'] : [];
        $localDevelopmentEmbedConfigOrDefault = [
            'runTelemetry' => true,
            'endpointIsEditable' => false,
            'initialState' => [],
            ...$embed,
        ];
        $initialState = [];
        if (array_key_exists('document', $config) || array_key_exists('headers', $config) || array_key_exists('variables', $config)) {
            $initialState = [
                'document' => $config['document'] ?? null,
                'variables' => $config['variables'] ?? null,
                'headers' => $config['headers'] ?? null,
            ];
        }
        if (array_key_exists('collectionId', $config)) {
            $initialState = [...$initialState, 'collectionId' => $config['collectionId'], 'operationId' => $config['operationId'] ?? null];
        }
        $initialState = [
            ...$initialState,
            'includeCookies' => $config['includeCookies'] ?? null,
            ...(is_array($localDevelopmentEmbedConfigOrDefault['initialState']) ? $localDevelopmentEmbedConfigOrDefault['initialState'] : []),
        ];

        $embeddedSandboxConfig = [
            'target' => '#embeddableSandbox',
            'initialState' => $initialState,
            'hideCookieToggle' => false,
            'endpointIsEditable' => $localDevelopmentEmbedConfigOrDefault['endpointIsEditable'],
            'runtime' => $apolloServerVersion,
            'runTelemetry' => $localDevelopmentEmbedConfigOrDefault['runTelemetry'],
            'allowDynamicStyles' => false, // disabled for CSP - we add the iframe styles ourselves instead
        ];

        return "\n<div class=\"fallback\">\n  <h1>Welcome to Apollo Server</h1>\n  <p>Apollo Sandbox cannot be loaded; it appears that you might be offline.</p>\n</div>\n<style nonce={$nonce}>\n  iframe {\n    background-color: white;\n    height: 100%;\n    width: 100%;\n    border: none;\n  }\n  #embeddableSandbox {\n    width: 100vw;\n    height: 100vh;\n    position: absolute;\n    top: 0;\n  }\n</style>\n<div id=\"embeddableSandbox\"></div>\n<script nonce=\"{$nonce}\" src=\"https://embeddable-sandbox.cdn.apollographql.com/" . self::encodeURIComponent($sandboxCdnVersion) . '/embeddable-sandbox.umd.production.min.js?runtime=' . self::encodeURIComponent($apolloServerVersion) . "\"></script>\n<script nonce=\"{$nonce}\">\n  var initialEndpoint = window.location.href;\n  var embeddedSandboxConfig = " . self::getConfigStringForHtml($embeddedSandboxConfig) . ";\n  new window.EmbeddedSandbox(\n    {\n      ...embeddedSandboxConfig,\n      initialEndpoint,\n    }\n  );\n</script>\n";
    }

    /** a uuid v4, hashed: `createHash('sha256').update(uuidv4()).digest('hex')` */
    private static function nonce(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

        return hash('sha256', $uuid);
    }

    /**
     * Helper for the two actual plugin functions.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function landingPageDefault(?string $maybeVersion, array $config): array
    {
        $explorerVersion = $maybeVersion ?? self::DEFAULT_EMBEDDED_EXPLORER_VERSION;
        $sandboxVersion = $maybeVersion ?? self::DEFAULT_EMBEDDED_SANDBOX_VERSION;
        $apolloServerLandingPageVersion = $maybeVersion ?? self::DEFAULT_APOLLO_SERVER_LANDING_PAGE_VERSION;
        $apolloServerVersion = '@apollo/server@' . self::PACKAGE_VERSION;

        $scriptSafeList = implode(' ', [
            'https://apollo-server-landing-page.cdn.apollographql.com',
            'https://embeddable-sandbox.cdn.apollographql.com',
            'https://embeddable-explorer.cdn.apollographql.com',
        ]);
        $styleSafeList = implode(' ', [
            'https://apollo-server-landing-page.cdn.apollographql.com',
            'https://embeddable-sandbox.cdn.apollographql.com',
            'https://embeddable-explorer.cdn.apollographql.com',
            'https://fonts.googleapis.com',
        ]);
        $iframeSafeList = implode(' ', [
            'https://explorer.embed.apollographql.com',
            'https://sandbox.embed.apollographql.com',
            'https://embed.apollo.local:3000',
        ]);

        $html = static function () use ($config, $explorerVersion, $sandboxVersion, $apolloServerLandingPageVersion, $apolloServerVersion, $scriptSafeList, $styleSafeList, $iframeSafeList): string {
            $encodedASLandingPageVersion = self::encodeURIComponent($apolloServerLandingPageVersion);
            $nonce = is_string($config['precomputedNonce'] ?? null) ? $config['precomputedNonce'] : self::nonce();
            $scriptCsp = "script-src 'self' 'nonce-{$nonce}' {$scriptSafeList}";
            $styleCsp = "style-src 'nonce-{$nonce}' {$styleSafeList}";
            $imageCsp = 'img-src https://apollo-server-landing-page.cdn.apollographql.com';
            $manifestCsp = 'manifest-src https://apollo-server-landing-page.cdn.apollographql.com';
            $frameCsp = "frame-src {$iframeSafeList}";

            $configForEncoding = $config;
            unset($configForEncoding['precomputedNonce']);

            if (($config['embed'] ?? false) !== false && $config['embed'] !== null) {
                if (array_key_exists('graphRef', $config) && $config['graphRef']) {
                    $body = self::getEmbeddedExplorerHTML($explorerVersion, $config, $apolloServerVersion, $nonce);
                } elseif (!array_key_exists('graphRef', $config)) {
                    $body = self::getEmbeddedSandboxHTML($sandboxVersion, $config, $apolloServerVersion, $nonce);
                } else {
                    $body = self::getNonEmbeddedLandingPageHTML($apolloServerLandingPageVersion, $configForEncoding, $apolloServerVersion, $nonce);
                }
            } else {
                $body = self::getNonEmbeddedLandingPageHTML($apolloServerLandingPageVersion, $configForEncoding, $apolloServerVersion, $nonce);
            }

            return <<<HTML

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta http-equiv="Content-Security-Policy" content="{$scriptCsp}; {$styleCsp}; {$imageCsp}; {$manifestCsp}; {$frameCsp}" />
    <link
      rel="icon"
      href="https://apollo-server-landing-page.cdn.apollographql.com/{$encodedASLandingPageVersion}/assets/favicon.png"
    />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="preconnect" href="https://fonts.gstatic.com" />
    <link
      href="https://fonts.googleapis.com/css2?family=Source+Sans+Pro&display=swap"
      rel="stylesheet"
    />
    <meta name="theme-color" content="#000000" />
    <meta name="description" content="Apollo server landing page" />
    <link
      rel="apple-touch-icon"
      href="https://apollo-server-landing-page.cdn.apollographql.com/{$encodedASLandingPageVersion}/assets/favicon.png"
    />
    <link
      rel="manifest"
      href="https://apollo-server-landing-page.cdn.apollographql.com/{$encodedASLandingPageVersion}/manifest.json"
    />
    <title>Apollo Server</title>
  </head>
  <body>
    <noscript>You need to enable JavaScript to run this app.</noscript>
    <div id="react-root">
      <style nonce={$nonce}>
        body {
          margin: 0;
          overflow-x: hidden;
          overflow-y: hidden;
        }
        .fallback {
          opacity: 0;
          animation: fadeIn 1s 1s;
          animation-iteration-count: 1;
          animation-fill-mode: forwards;
          padding: 1em;
        }
        @keyframes fadeIn {
          0% {opacity:0;}
          100% {opacity:1; }
        }
      </style>
    {$body}
    </div>
  </body>
</html>
          
HTML;
        };

        return [
            '__internal_installed_implicitly__' => false,
            'serverWillStart' => static function (array $server) use ($config, $html): array {
                if (isset($config['precomputedNonce']) && is_callable($server['logger']['warn'] ?? null)) {
                    ($server['logger']['warn'])("The `precomputedNonce` landing page configuration option is deprecated. Removing this option is strictly an improvement to Apollo Server's landing page Content Security Policy (CSP) implementation for preventing XSS attacks.");
                }

                return [
                    'renderLandingPage' => static fn (): array => ['html' => $html],
                ];
            },
        ];
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes;

/**
 * Port of src/utils.ts.
 *
 * `getClientConfig` returns the SESClient config (`region`, `endpoint`, `credentials` and any other
 * AWS SDK option, which SesClient ignores); `buildSendEmailCommandInput` returns the SES
 * `SendEmail` input (`Source`, `Destination`, `Message`, `ReplyToAddresses`, … — the AWS API shape).
 * Keys upstream leaves `undefined` are `null` here.
 *
 * @phpstan-type ProviderCredentials array{key: string, secret: string, sessionToken?: string}
 * @phpstan-type ProviderSettings array{defaultFrom?: string, defaultReplyTo?: string|list<string>}
 */
final class Utils
{
    /** Default SES API host when `amazon` is omitted (same as legacy node-ses). */
    public const string DEFAULT_SES_ENDPOINT = 'https://email.us-east-1.amazonaws.com';

    public const string SES_ENDPOINT_REGION_PATTERN = '/email\.([a-z0-9-]+)\.amazonaws\.com/i';

    private static function resolveEndpointUrl(mixed $endpoint): ?string
    {
        if ($endpoint === null || $endpoint === '' || $endpoint === false) {
            return null;
        }

        if (is_string($endpoint)) {
            return $endpoint;
        }

        if (is_array($endpoint) && is_string($endpoint['url'] ?? null)) {
            return $endpoint['url'];
        }

        return null;
    }

    public static function regionFromEndpoint(mixed $endpoint): ?string
    {
        $endpointUrl = self::resolveEndpointUrl($endpoint);

        if ($endpointUrl === null) {
            return null;
        }

        // new URL(endpointUrl).hostname
        $hostname = parse_url($endpointUrl, PHP_URL_HOST);
        if (!is_string($hostname) || !str_contains($endpointUrl, '://')) {
            return null;
        }

        return preg_match(self::SES_ENDPOINT_REGION_PATTERN, $hostname, $match) === 1 ? $match[1] : null;
    }

    /**
     * Matches node-ses `extractRecipient`: arrays pass through, strings become a single entry.
     *
     * @param string|list<string>|null $value
     * @return list<string>|null
     */
    public static function toAddressList(string|array|null $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }

    /** @return list<array{Name: string, Value: string}>|null */
    private static function mapLegacyMessageTags(mixed $messageTags): ?array
    {
        if (!is_array($messageTags) || !array_is_list($messageTags)) {
            return null;
        }

        $tags = [];
        foreach ($messageTags as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null) && is_string($tag['value'] ?? null)) {
                $tags[] = ['Name' => $tag['name'], 'Value' => $tag['value']];
            }
        }

        return $tags;
    }

    /**
     * Maps legacy node-ses `providerOptions` and AWS SDK v3 `SESClient` config.
     *
     * Rewrites:
     * - `key` / `secret` → `credentials.accessKeyId` / `credentials.secretAccessKey`
     * - `credentials: { key, secret }` → AWS credential object
     * - `amazon` → `endpoint`
     * - region from `amazon` / `endpoint` host (`email.<region>.amazonaws.com`)
     * - `key` + `secret` only → `region: us-east-1` (node-ses default endpoint behavior)
     *
     * @param array<string, mixed> $providerOptions
     * @return array<string, mixed>
     */
    public static function getClientConfig(array $providerOptions): array
    {
        $key = $providerOptions['key'] ?? null;
        $secret = $providerOptions['secret'] ?? null;
        $amazon = $providerOptions['amazon'] ?? null;
        $credentials = $providerOptions['credentials'] ?? null;
        $region = $providerOptions['region'] ?? null;
        $clientConfig = array_diff_key($providerOptions, array_flip(['key', 'secret', 'amazon', 'credentials', 'region']));

        $hasLegacyStaticCredentials = self::truthy($key) && self::truthy($secret);
        $endpoint = self::truthy($amazon)
            ? $amazon
            : (self::truthy($providerOptions['endpoint'] ?? null)
                ? $providerOptions['endpoint']
                : ($hasLegacyStaticCredentials ? self::DEFAULT_SES_ENDPOINT : null));

        $parsedRegionFromEndpoint = self::regionFromEndpoint($endpoint);

        $explicitCredentials = null;
        if (is_array($credentials) && array_key_exists('key', $credentials)) {
            $explicitCredentials = [
                'accessKeyId' => $credentials['key'],
                'secretAccessKey' => $credentials['secret'] ?? null,
                ...(self::truthy($credentials['sessionToken'] ?? null) ? ['sessionToken' => $credentials['sessionToken']] : []),
            ];
        } elseif (self::truthy($credentials)) {
            $explicitCredentials = $credentials;
        }
        if (!self::truthy($explicitCredentials) && self::truthy($key) && self::truthy($secret)) {
            $explicitCredentials = [
                'accessKeyId' => $key,
                'secretAccessKey' => $secret,
            ];
        }

        $unparseableLegacyAmazon = self::truthy($amazon) && $hasLegacyStaticCredentials && $parsedRegionFromEndpoint === null;

        $resolvedRegion = self::truthy($region) ? $region : $parsedRegionFromEndpoint;
        if (!self::truthy($resolvedRegion)
            && ($unparseableLegacyAmazon || ($hasLegacyStaticCredentials && $parsedRegionFromEndpoint === null && !self::truthy($endpoint)))) {
            $resolvedRegion = 'us-east-1';
        }

        // node-ses createClient only consumed key, secret, and amazon — ignore stray options.
        $sdkOnlyOptions = $hasLegacyStaticCredentials ? [] : $clientConfig;

        return [
            ...$sdkOnlyOptions,
            ...(self::truthy($resolvedRegion) ? ['region' => $resolvedRegion] : []),
            ...(self::truthy($endpoint) ? ['endpoint' => $endpoint] : []),
            ...(self::truthy($explicitCredentials) ? ['credentials' => $explicitCredentials] : []),
        ];
    }

    /**
     * Builds SendEmail input (html → Html body, text → Text body; legacy node-ses message/altText).
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function buildSendEmailCommandInput(array $options, array $settings): array
    {
        $from = $options['from'] ?? null;
        $replyTo = $options['replyTo'] ?? null;
        $subject = $options['subject'] ?? null;
        $text = $options['text'] ?? null;
        $html = $options['html'] ?? null;
        $rest = array_diff_key($options, array_flip(['from', 'to', 'cc', 'bcc', 'replyTo', 'subject', 'text', 'html']));

        $configurationSet = $rest['configurationSet'] ?? null;
        $messageTags = $rest['messageTags'] ?? null;
        $sdkRest = array_diff_key($rest, array_flip(['configurationSet', 'messageTags']));

        $commandInput = [
            'Source' => self::truthy($from) ? $from : ($settings['defaultFrom'] ?? null),
            'Destination' => [
                'ToAddresses' => self::toAddressList(self::addressValue($options['to'] ?? null)),
                'CcAddresses' => self::toAddressList(self::addressValue($options['cc'] ?? null)),
                'BccAddresses' => self::toAddressList(self::addressValue($options['bcc'] ?? null)),
            ],
            'ReplyToAddresses' => self::toAddressList(self::addressValue($replyTo !== null ? $replyTo : ($settings['defaultReplyTo'] ?? null))),
            'Message' => [
                'Subject' => [
                    'Data' => $subject,
                    'Charset' => 'UTF-8',
                ],
                'Body' => [
                    ...(self::truthy($html)
                        ? [
                            'Html' => [
                                'Data' => $html,
                                'Charset' => 'UTF-8',
                            ],
                        ]
                        : []),
                    ...(self::truthy($text)
                        ? [
                            'Text' => [
                                'Data' => $text,
                                'Charset' => 'UTF-8',
                            ],
                        ]
                        : []),
                ],
            ],
            ...$sdkRest,
        ];

        if (is_string($configurationSet)) {
            $commandInput['ConfigurationSetName'] = $configurationSet;
        }

        $tags = self::mapLegacyMessageTags($messageTags);

        if ($tags !== null && count($tags) > 0) {
            $commandInput['Tags'] = $tags;
        }

        return $commandInput;
    }

    /** @return string|list<string>|null */
    private static function addressValue(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
        }

        return null;
    }

    private static function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0);
    }
}

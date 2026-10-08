<?php

declare(strict_types=1);

namespace Strapi\Provider\EmailAmazonSes;

/**
 * Not an upstream file: the part of `@aws-sdk/client-ses` the provider uses — `new SESClient(config)`
 * and `client.send(new SendEmailCommand(input))` — as a small HTTP client over `strapi.fetch`.
 *
 * `SendEmail` is SES's classic (v1, `2010-12-01`) Query API action, which `@aws-sdk/client-ses`
 * calls: the input is serialized as `Action=SendEmail&Destination.ToAddresses.member.1=…`,
 * signed with AWS Signature V4 (service `ses`) and POSTed to `endpoint` (default
 * `https://email.<region>.amazonaws.com`). The XML response gives `MessageId`; an `ErrorResponse`
 * throws with the SES error message (`name` = the error code, `$metadata.httpStatusCode`).
 *
 * Config: `region` (else `AWS_REGION` / `AWS_DEFAULT_REGION`), `endpoint` (string or `{ url }`),
 * `credentials` (`{ accessKeyId, secretAccessKey, sessionToken? }`). Without credentials the AWS
 * default chain is followed: `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_SESSION_TOKEN`,
 * the shared credentials file (`AWS_SHARED_CREDENTIALS_FILE`, `~/.aws/credentials`, profile
 * `AWS_PROFILE` or `default`), web identity (`AWS_WEB_IDENTITY_TOKEN_FILE` + `AWS_ROLE_ARN`,
 * i.e. EKS IRSA), ECS container credentials and EC2 instance metadata (IMDSv2). Other AWS SDK
 * options (`maxAttempts`, `requestHandler`, …) are accepted and ignored.
 *
 * @phpstan-type Fetch callable(string, array{method?: string, headers?: array<string, string>, body?: string|null, timeout?: int|float}): array{ok: bool, status: int, headers: array<string, string>, body: string}
 * @phpstan-type Credentials array{accessKeyId: string, secretAccessKey: string, sessionToken?: string|null, expiration?: int|null}
 */
final class SesClient
{
    /** @var Fetch */
    private $fetch;

    /** @var Credentials|null */
    private ?array $credentials = null;

    /**
     * @param array<string, mixed> $config the SESClient config (Utils::getClientConfig)
     * @param Fetch $fetch
     */
    public function __construct(public readonly array $config, callable $fetch)
    {
        $this->fetch = $fetch;
    }

    /**
     * `client.send(new SendEmailCommand(input))`
     *
     * @param array<string, mixed> $input
     * @return array{MessageId: string, '$metadata': array{httpStatusCode: int, requestId: string|null}}
     */
    public function sendEmail(array $input): array
    {
        $region = $this->region();
        $endpoint = $this->endpoint($region);
        $body = http_build_query(
            ['Action' => 'SendEmail', 'Version' => '2010-12-01', ...self::serialize($input)],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        $headers = SignatureV4::sign(
            'POST',
            $endpoint,
            ['content-type' => 'application/x-www-form-urlencoded; charset=utf-8'],
            $body,
            'ses',
            $region,
            $this->credentials(),
        );

        $response = ($this->fetch)($endpoint, ['method' => 'POST', 'headers' => $headers, 'body' => $body, 'timeout' => 30]);
        $xml = self::xml($response['body']);

        if (!$response['ok']) {
            $error = $xml?->Error;
            $code = $error !== null && (string) $error->Code !== '' ? (string) $error->Code : 'UnknownError';
            $message = $error !== null && (string) $error->Message !== '' ? (string) $error->Message : "SES request failed with status {$response['status']}";

            throw new SesServiceException($message, $code, $response['status'], $xml !== null ? ((string) $xml->RequestId ?: null) : null);
        }

        return [
            'MessageId' => $xml !== null ? (string) $xml->SendEmailResult->MessageId : '',
            '$metadata' => [
                'httpStatusCode' => $response['status'],
                'requestId' => $xml !== null ? ((string) $xml->ResponseMetadata->RequestId ?: null) : null,
            ],
        ];
    }

    /**
     * AWS Query protocol: lists become `Name.member.N`, structures `Name.Key`; `null` is left out.
     *
     * @param array<string|int, mixed> $input
     * @return array<string, string>
     */
    public static function serialize(array $input, string $prefix = ''): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if ($value === null) {
                continue;
            }
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value) && array_is_list($value)) {
                if ($value === []) {
                    $out[$name] = '';
                    continue;
                }
                foreach ($value as $i => $item) {
                    $member = "{$name}.member." . ($i + 1);
                    if (is_array($item)) {
                        $out = [...$out, ...self::serialize($item, $member)];
                    } elseif ($item !== null) {
                        $out[$member] = self::scalar($item);
                    }
                }
            } elseif (is_array($value)) {
                $out = [...$out, ...self::serialize($value, $name)];
            } else {
                $out[$name] = self::scalar($value);
            }
        }

        return $out;
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d\TH:i:s\Z'),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private function region(): string
    {
        $region = $this->config['region'] ?? null;
        if (is_string($region) && $region !== '') {
            return $region;
        }
        foreach (['AWS_REGION', 'AWS_DEFAULT_REGION'] as $env) {
            $value = getenv($env);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        throw new \RuntimeException('Region is missing');
    }

    private function endpoint(string $region): string
    {
        $endpoint = $this->config['endpoint'] ?? null;
        if (is_array($endpoint) && is_string($endpoint['url'] ?? null)) {
            $endpoint = $endpoint['url'];
        }
        if (is_string($endpoint) && $endpoint !== '') {
            return $endpoint;
        }

        return 'https://email.' . $region . '.amazonaws.com' . (str_starts_with($region, 'cn-') ? '.cn' : '');
    }

    /** @return Credentials */
    private function credentials(): array
    {
        $configured = $this->config['credentials'] ?? null;
        if (is_array($configured) && is_string($configured['accessKeyId'] ?? null) && is_string($configured['secretAccessKey'] ?? null)) {
            return [
                'accessKeyId' => $configured['accessKeyId'],
                'secretAccessKey' => $configured['secretAccessKey'],
                'sessionToken' => is_string($configured['sessionToken'] ?? null) ? $configured['sessionToken'] : null,
            ];
        }
        if (is_callable($configured)) {
            /** @var Credentials $resolved */
            $resolved = $configured();

            return $resolved;
        }

        if ($this->credentials !== null && ($this->credentials['expiration'] ?? null) !== null && $this->credentials['expiration'] - 300 > time()) {
            return $this->credentials;
        }

        $this->credentials = $this->fromEnv() ?? $this->fromSharedFile() ?? $this->fromWebIdentity() ?? $this->fromContainer() ?? $this->fromInstanceMetadata();
        if ($this->credentials === null) {
            throw new \RuntimeException('Could not load credentials from any providers');
        }

        return $this->credentials;
    }

    /** @return Credentials|null */
    private function fromEnv(): ?array
    {
        $key = getenv('AWS_ACCESS_KEY_ID');
        $secret = getenv('AWS_SECRET_ACCESS_KEY');
        if (!is_string($key) || $key === '' || !is_string($secret) || $secret === '') {
            return null;
        }
        $token = getenv('AWS_SESSION_TOKEN');

        return ['accessKeyId' => $key, 'secretAccessKey' => $secret, 'sessionToken' => is_string($token) && $token !== '' ? $token : null];
    }

    /** @return Credentials|null */
    private function fromSharedFile(): ?array
    {
        $file = getenv('AWS_SHARED_CREDENTIALS_FILE');
        if (!is_string($file) || $file === '') {
            $home = getenv('HOME');
            $file = (is_string($home) && $home !== '' ? $home : '') . '/.aws/credentials';
        }
        if (!is_file($file)) {
            return null;
        }
        $profiles = @parse_ini_file($file, true, INI_SCANNER_RAW);
        $profile = getenv('AWS_PROFILE');
        $profile = is_string($profile) && $profile !== '' ? $profile : 'default';
        $section = is_array($profiles) ? ($profiles[$profile] ?? null) : null;
        if (!is_array($section) || !isset($section['aws_access_key_id'], $section['aws_secret_access_key'])) {
            return null;
        }

        return [
            'accessKeyId' => (string) $section['aws_access_key_id'],
            'secretAccessKey' => (string) $section['aws_secret_access_key'],
            'sessionToken' => isset($section['aws_session_token']) ? (string) $section['aws_session_token'] : null,
        ];
    }

    /** @return Credentials|null */
    private function fromWebIdentity(): ?array
    {
        $tokenFile = getenv('AWS_WEB_IDENTITY_TOKEN_FILE');
        $roleArn = getenv('AWS_ROLE_ARN');
        if (!is_string($tokenFile) || $tokenFile === '' || !is_string($roleArn) || $roleArn === '' || !is_file($tokenFile)) {
            return null;
        }
        $session = getenv('AWS_ROLE_SESSION_NAME');
        $query = http_build_query([
            'Action' => 'AssumeRoleWithWebIdentity',
            'Version' => '2011-06-15',
            'RoleArn' => $roleArn,
            'RoleSessionName' => is_string($session) && $session !== '' ? $session : 'strapi-' . time(),
            'WebIdentityToken' => trim((string) file_get_contents($tokenFile)),
        ], '', '&', PHP_QUERY_RFC3986);
        $region = is_string($this->config['region'] ?? null) ? $this->config['region'] : (getenv('AWS_REGION') ?: 'us-east-1');
        $response = ($this->fetch)("https://sts.{$region}.amazonaws.com/", [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $query,
        ]);
        $xml = self::xml($response['body']);
        $credentials = $xml?->AssumeRoleWithWebIdentityResult->Credentials;
        if (!$response['ok'] || $credentials === null || (string) $credentials->AccessKeyId === '') {
            throw new \RuntimeException('Could not assume role with web identity: ' . ($xml !== null ? (string) $xml->Error->Message : $response['status']));
        }

        return self::temporary((string) $credentials->AccessKeyId, (string) $credentials->SecretAccessKey, (string) $credentials->SessionToken, (string) $credentials->Expiration);
    }

    /** @return Credentials|null */
    private function fromContainer(): ?array
    {
        $relative = getenv('AWS_CONTAINER_CREDENTIALS_RELATIVE_URI');
        $full = getenv('AWS_CONTAINER_CREDENTIALS_FULL_URI');
        $url = is_string($relative) && $relative !== '' ? 'http://169.254.170.2' . $relative : (is_string($full) && $full !== '' ? $full : null);
        if ($url === null) {
            return null;
        }
        $headers = [];
        $token = getenv('AWS_CONTAINER_AUTHORIZATION_TOKEN');
        $tokenFile = getenv('AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE');
        if (is_string($tokenFile) && $tokenFile !== '' && is_file($tokenFile)) {
            $token = trim((string) file_get_contents($tokenFile));
        }
        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = $token;
        }
        $response = ($this->fetch)($url, ['headers' => $headers, 'timeout' => 1]);

        return $response['ok'] ? self::fromJson($response['body']) : null;
    }

    /** @return Credentials|null */
    private function fromInstanceMetadata(): ?array
    {
        if (strtolower((string) getenv('AWS_EC2_METADATA_DISABLED')) === 'true') {
            return null;
        }
        $base = 'http://169.254.169.254/latest';
        try {
            $token = ($this->fetch)("{$base}/api/token", ['method' => 'PUT', 'headers' => ['X-aws-ec2-metadata-token-ttl-seconds' => '21600'], 'timeout' => 1]);
            $headers = $token['ok'] ? ['X-aws-ec2-metadata-token' => trim($token['body'])] : [];
            $role = ($this->fetch)("{$base}/meta-data/iam/security-credentials/", ['headers' => $headers, 'timeout' => 1]);
            if (!$role['ok'] || trim($role['body']) === '') {
                return null;
            }
            $roleName = trim(explode("\n", trim($role['body']))[0]);
            $response = ($this->fetch)("{$base}/meta-data/iam/security-credentials/{$roleName}", ['headers' => $headers, 'timeout' => 1]);
        } catch (\Throwable) {
            return null;
        }

        return $response['ok'] ? self::fromJson($response['body']) : null;
    }

    /** @return Credentials|null */
    private static function fromJson(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !is_string($data['AccessKeyId'] ?? null) || !is_string($data['SecretAccessKey'] ?? null)) {
            return null;
        }

        return self::temporary($data['AccessKeyId'], $data['SecretAccessKey'], is_string($data['Token'] ?? null) ? $data['Token'] : '', is_string($data['Expiration'] ?? null) ? $data['Expiration'] : '');
    }

    /** @return Credentials */
    private static function temporary(string $key, string $secret, string $token, string $expiration): array
    {
        $expires = $expiration !== '' ? strtotime($expiration) : false;

        return [
            'accessKeyId' => $key,
            'secretAccessKey' => $secret,
            'sessionToken' => $token !== '' ? $token : null,
            'expiration' => $expires === false ? null : $expires,
        ];
    }

    private static function xml(string $body): ?\SimpleXMLElement
    {
        if (trim($body) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }
}

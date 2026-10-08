<?php

declare(strict_types=1);

namespace Strapi\Admin\Ai\Services;

use Strapi\Core\Strapi;
use Strapi\Core\Utils\Fetch;

/**
 * Port of server/src/ai/services/ai.ts (`createAiAdminService({ strapi })`, registered as
 * `ai.admin`). It talks to Strapi's AI server over HTTP.
 *
 * Every AI feature needs an Enterprise license (`strapi.ee`), which the PHP port never has
 * (`ee/` is not ported): `isAvailable()` and `isStrapiManagedAiEnabled()` are always false, so the
 * AI routes answer 404 and `getAiToken()`/`getAiUsage()` fail before any request, exactly as
 * upstream does on an unlicensed project.
 */
final class Ai
{
    private const STRAPI_MANAGED_AI_LICENSE_FEATURE = 'cms-ai';
    private const CUSTOM_AI_PROVIDER_LICENSE_FEATURE = 'cms-byok-ai';

    /**
     * In-memory cache for AI tokens
     * Key format: `${projectId}:${userId}`
     *
     * @var array<string, array{token: string, expiresAt?: string|null, expiresAtMs?: int|null}>
     */
    private array $aiTokenCache = [];

    private bool $isCustomProviderRejected = false;

    /** @var (\Closure(string, array<string, mixed>): array{ok: bool, status: int, headers: array<string, string>, body: string})|null test seam: the HTTP client */
    public ?\Closure $fetch = null;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createAiAdminService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    private function isConfigEnabled(): bool
    {
        return $this->strapi->config()->get('admin.ai.enabled', true) === true;
    }

    /** `strapi.ee?.isEE === true` */
    private function isEE(): bool
    {
        return $this->strapi->EE() === true;
    }

    /** `strapi.ee?.features?.isEnabled(feature) === true`: the license registry is EE code, not ported */
    private function isLicenseFeatureEnabled(string $feature): bool
    {
        if (!$this->isEE() || !$this->strapi->has('ee')) {
            return false;
        }

        $ee = $this->strapi->get('ee');
        $features = is_object($ee) && isset($ee->features) ? $ee->features : null;
        $isEnabled = is_object($features) ? [$features, 'isEnabled'] : null;

        return is_callable($isEnabled) && $isEnabled($feature) === true;
    }

    /* Requires `ai.enabled=true` + license + no failed AI provider registration */
    public function isAvailable(): bool
    {
        return $this->isConfigEnabled() && $this->isEE() && !$this->isCustomProviderRejected;
    }

    /* `true` only when the license has the `cms-ai` entitlement to globally enable AI features.
        TODO: once all AI features are migrated to the providers architecture, consider removing this flag */
    public function isStrapiManagedAiEnabled(): bool
    {
        return $this->isConfigEnabled()
            && !$this->isCustomProviderRejected
            && $this->isLicenseFeatureEnabled(self::STRAPI_MANAGED_AI_LICENSE_FEATURE);
    }

    public function authorizeCustomProvider(): bool
    {
        if (!$this->isConfigEnabled()) {
            $this->strapi->log()->info('A custom AI provider was ignored: AI is disabled by the "admin.ai.enabled" config.');

            return false;
        }

        if ($this->isLicenseFeatureEnabled(self::CUSTOM_AI_PROVIDER_LICENSE_FEATURE)) {
            return true;
        }

        $this->isCustomProviderRejected = true;
        $this->strapi->log()->warning('A custom AI provider was rejected: the Strapi license does not include the "' . self::CUSTOM_AI_PROVIDER_LICENSE_FEATURE . '" feature. All AI features are disabled.');

        return false;
    }

    private function isPluginAiFeatureConfigured(string $plugin, string $service): bool
    {
        if (!$this->strapi->hasPlugin($plugin)) {
            return false;
        }

        try {
            $aiService = $this->strapi->plugin($plugin)->service($service);
        } catch (\RuntimeException) {
            return false;
        }

        $isEnabled = [$aiService, 'isEnabled'];
        if (!is_callable($isEnabled)) {
            return false;
        }

        return $isEnabled() === true;
    }

    /**
     * Returns the status of each AI feature, flagging which are enabled.
     *
     * @return array{isAiI18nConfigured: bool, isAiMediaLibraryConfigured: bool}
     */
    public function getAiFeatureConfig(): array
    {
        if (!$this->isAvailable()) {
            return [
                'isAiI18nConfigured' => false,
                'isAiMediaLibraryConfigured' => false,
            ];
        }

        return [
            'isAiI18nConfigured' => $this->isPluginAiFeatureConfigured('i18n', 'ai-localizations'),
            'isAiMediaLibraryConfigured' => $this->isPluginAiFeatureConfigured('upload', 'aiMetadata'),
        ];
    }

    private static function failure(string $errorPrefix): \RuntimeException
    {
        return new \RuntimeException(preg_replace('/:$/', '', $errorPrefix) . '. Check server logs for details.');
    }

    /**
     * Resolves the shared context required by both getAiToken and getAiUsage:
     * EE license, project ID, and AI server URL.
     *
     * @return array{eeLicense: string, projectId: mixed, aiServerUrl: string}
     */
    private function resolveAiContext(string $errorPrefix): array
    {
        if (!$this->isStrapiManagedAiEnabled()) {
            $this->strapi->log()->error("{$errorPrefix} AI is not enabled");
            throw self::failure($errorPrefix);
        }

        if (!$this->isEE()) {
            $this->strapi->log()->error("{$errorPrefix} Enterprise Edition features are not enabled");
            throw self::failure($errorPrefix);
        }

        $eeLicense = getenv('STRAPI_LICENSE');

        if (!is_string($eeLicense) || $eeLicense === '') {
            $licensePath = rtrim($this->strapi->dirs()->root, '/') . '/license.txt';
            // License file doesn't exist or can't be read
            $eeLicense = is_readable($licensePath) ? (string) file_get_contents($licensePath) : '';
        }

        if ($eeLicense === '') {
            $this->strapi->log()->error("{$errorPrefix} No EE license found. Please ensure STRAPI_LICENSE environment variable is set or license.txt file exists.");
            throw self::failure($errorPrefix);
        }

        $projectId = $this->strapi->config()->get('uuid');
        if (empty($projectId)) {
            $this->strapi->log()->error("{$errorPrefix} Project ID not configured");
            throw self::failure($errorPrefix);
        }

        $aiServerUrl = getenv('STRAPI_AI_URL');
        $aiServerUrl = is_string($aiServerUrl) && $aiServerUrl !== '' ? $aiServerUrl : 'https://strapi-ai.apps.strapi.io';

        return ['eeLicense' => $eeLicense, 'projectId' => $projectId, 'aiServerUrl' => $aiServerUrl];
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string}
     */
    private function post(string $url, array $body): array
    {
        $options = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Request-Id' => self::randomUuid(),
            ],
            'body' => (string) json_encode($body),
        ];

        if ($this->fetch !== null) {
            return ($this->fetch)($url, $options);
        }

        return Fetch::createStrapiFetch($this->strapi, false)($url, $options);
    }

    private static function randomUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param array{ok: bool, status: int, headers: array<string, string>, body: string} $response
     */
    private function logErrorResponse(string $errorPrefix, array $response, mixed $projectId): void
    {
        $errorText = $response['body'];
        $errorData = json_decode($errorText, true);
        if (!is_array($errorData)) {
            $errorData = ['error' => $errorText !== '' ? $errorText : 'Failed to parse error response'];
        }

        $error = $errorData['error'] ?? null;
        $this->strapi->log()->error("{$errorPrefix} " . (is_scalar($error) && $error !== '' ? (string) $error : 'Unknown error'), [
            'status' => $response['status'],
            'statusText' => '',
            'error' => $errorData,
            'errorText' => $errorText,
            'projectId' => $projectId,
        ]);
    }

    /** @return array{token: string, expiresAt: string|null} */
    public function getAiToken(): array
    {
        $ERROR_PREFIX = 'AI token request failed:';

        ['eeLicense' => $eeLicense, 'projectId' => $projectId, 'aiServerUrl' => $aiServerUrl] = $this->resolveAiContext($ERROR_PREFIX);

        // Get the current user
        $user = $this->strapi->requestContext()->get()?->state()->get('user');
        if (!is_array($user) || $user === []) {
            $this->strapi->log()->error("{$ERROR_PREFIX} No authenticated user in request context");
            throw new \RuntimeException('AI token request failed. Check server logs for details.');
        }

        // Create a secure user identifier using only user ID
        $userIdentifier = (string) ($user['id'] ?? '');

        // Check cache for existing valid token
        $cacheKey = (is_scalar($projectId) ? (string) $projectId : '') . ":{$userIdentifier}";
        $cachedToken = $this->aiTokenCache[$cacheKey] ?? null;

        if ($cachedToken !== null) {
            $now = (int) floor(microtime(true) * 1000);
            // Check if token is still valid (with buffer so it has time to be used)
            $bufferMs = 2 * 60 * 1000; // 2 minutes

            if (!empty($cachedToken['expiresAtMs']) && $cachedToken['expiresAtMs'] - $bufferMs > $now) {
                $this->strapi->log()->info('Using cached AI token');

                return [
                    'token' => $cachedToken['token'],
                    'expiresAt' => $cachedToken['expiresAt'] ?? null,
                ];
            }

            // Token expired or will expire soon, remove from cache
            unset($this->aiTokenCache[$cacheKey]);
        }

        $this->strapi->log()->debug('Contacting AI Server for token generation');

        $response = $this->post("{$aiServerUrl}/auth/getAiJWT", [
            'eeLicense' => $eeLicense,
            'userIdentifier' => $userIdentifier,
            'projectId' => $projectId,
        ]);

        if (!$response['ok']) {
            $this->logErrorResponse($ERROR_PREFIX, $response, $projectId);

            throw new \RuntimeException('AI token request failed. Check server logs for details.');
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            $this->strapi->log()->error("{$ERROR_PREFIX} Failed to parse AI server response");
            throw new \RuntimeException('AI token request failed. Check server logs for details.');
        }

        if (!is_string($data['jwt'] ?? null) || $data['jwt'] === '') {
            $this->strapi->log()->error("{$ERROR_PREFIX} Invalid response: missing JWT token");
            throw new \RuntimeException('AI token request failed. Check server logs for details.');
        }

        $expiresAt = is_string($data['expiresAt'] ?? null) ? $data['expiresAt'] : null;

        $this->strapi->log()->info('AI token generated successfully', [
            'userId' => $user['id'] ?? null,
            'expiresAt' => $expiresAt,
        ]);

        // Cache the token if it has an expiration time
        if ($expiresAt !== null && $expiresAt !== '') {
            $time = strtotime($expiresAt);
            $this->aiTokenCache[$cacheKey] = [
                'token' => $data['jwt'],
                'expiresAt' => $expiresAt,
                'expiresAtMs' => $time === false ? null : $time * 1000,
            ];
        }

        // Note: Token expires in 1 hour, client should handle refresh
        return [
            'token' => $data['jwt'],
            'expiresAt' => $expiresAt,
        ];
    }

    /** @return array<string, mixed> */
    public function getAiUsage(): array
    {
        $ERROR_PREFIX = 'AI usage data request failed:';

        ['eeLicense' => $eeLicense, 'projectId' => $projectId, 'aiServerUrl' => $aiServerUrl] = $this->resolveAiContext($ERROR_PREFIX);

        $this->strapi->log()->debug('Contacting AI Server for usage data');

        $response = $this->post("{$aiServerUrl}/cms/ai-data", [
            'eeKey' => $eeLicense,
            'projectId' => $projectId,
        ]);

        if (!$response['ok']) {
            $this->logErrorResponse($ERROR_PREFIX, $response, $projectId);

            throw new \RuntimeException('AI usage data request failed. Check server logs for details.');
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            $this->strapi->log()->error("{$ERROR_PREFIX} Failed to parse AI server response");
            throw new \RuntimeException('AI usage data request failed. Check server logs for details.');
        }

        return [
            ...(is_array($data['data'] ?? null) ? $data['data'] : []),
            'subscription' => $data['subscription'] ?? null,
        ];
    }
}

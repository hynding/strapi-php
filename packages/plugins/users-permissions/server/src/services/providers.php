<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\UrlJoin;
use Strapi\Plugin\UsersPermissions\Utils\Utils;

/** Port of server/src/services/providers.js. */
final class Providers
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Helper to get profiles
     *
     * @param array<string, mixed> $oauthData
     * @param array{grantResponse?: mixed} $options
     * @return array<string, mixed>
     */
    private function getProfile(string $provider, array $oauthData, array $options = []): array
    {
        $accessToken = self::firstTruthy($oauthData['access_token'] ?? null, $oauthData['code'] ?? null, $oauthData['oauth_token'] ?? null);

        $providers = $this->strapi->store()->get(['type' => 'plugin', 'name' => 'users-permissions', 'key' => 'grant']);

        return Utils::getService($this->strapi, 'providers-registry')->run([
            'provider' => $provider,
            'query' => $oauthData,
            'accessToken' => $accessToken,
            'providers' => $providers,
            'grantResponse' => $options['grantResponse'] ?? null,
        ]);
    }

    private static function firstTruthy(mixed ...$values): mixed
    {
        $last = null;
        foreach ($values as $value) {
            if ($value !== null && $value !== '' && $value !== false && $value !== 0) {
                return $value;
            }
            $last = $value;
        }

        return $last;
    }

    /**
     * Connect thanks to a third-party provider.
     *
     * @param array<string, mixed> $oauthData
     * @param array{grantResponse?: mixed} $options
     * @return array<string, mixed>
     */
    public function connect(string $provider, array $oauthData, array $options = []): array
    {
        $accessToken = self::firstTruthy($oauthData['access_token'] ?? null, $oauthData['code'] ?? null, $oauthData['oauth_token'] ?? null);
        $idToken = $oauthData['id_token'] ?? null;

        $isSet = static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false && $v !== 0;
        if (!$isSet($accessToken) && !$isSet($idToken)) {
            throw new \RuntimeException('No access_token.');
        }

        // Get the profile.
        $profile = $this->getProfile($provider, $oauthData, $options);

        $email = strtolower(is_scalar($profile['email'] ?? null) ? (string) $profile['email'] : '');

        // We need at least the mail.
        if ($email === '') {
            throw new \RuntimeException('Email was not available.');
        }

        $users = $this->strapi->db()->query('plugin::users-permissions.user')->findMany([
            'where' => ['email' => $email],
        ]);

        $advancedSettings = $this->strapi->store()->get(['type' => 'plugin', 'name' => 'users-permissions', 'key' => 'advanced']);
        $advancedSettings = is_array($advancedSettings) ? $advancedSettings : [];

        $user = null;
        foreach ($users as $candidate) {
            if (($candidate['provider'] ?? null) === $provider) {
                $user = $candidate;
                break;
            }
        }

        if (($user === null || $user === []) && !($advancedSettings['allow_register'] ?? false)) {
            throw new \RuntimeException('Register action is actually not available.');
        }

        if ($user !== null && $user !== []) {
            return $user;
        }

        if ($users !== [] && ($advancedSettings['unique_email'] ?? false)) {
            throw new \RuntimeException('Email is already taken.');
        }

        // Retrieve default role.
        $defaultRole = $this->strapi->db()->query('plugin::users-permissions.role')->findOne(['where' => ['type' => $advancedSettings['default_role'] ?? null]]);

        // Username: prefer profile, else email prefix; findValidUsername ensures valid + unique
        $profileUsername = is_string($profile['username'] ?? null) ? trim($profile['username']) : '';
        $base = $profileUsername !== '' ? $profileUsername : explode('@', $email)[0];
        $username = Utils::findValidUsername($this->strapi, $base);

        // Create the new user.
        $newUser = [
            ...$profile,
            'username' => $username, // use the generated or provided username
            'email' => $email, // overwrite with lowercased email
            'provider' => $provider,
            'role' => $defaultRole['id'] ?? throw new \TypeError("Cannot read properties of null (reading 'id')"),
            'confirmed' => true,
        ];

        return $this->strapi->db()->query('plugin::users-permissions.user')->create(['data' => $newUser]);
    }

    public function buildRedirectUri(string $provider = ''): string
    {
        $apiPrefix = (string) $this->strapi->config()->get('api.rest.prefix');

        return UrlJoin::join(
            (string) $this->strapi->config()->get('server.absoluteUrl'),
            $apiPrefix,
            'connect',
            $provider,
            'callback'
        );
    }
}

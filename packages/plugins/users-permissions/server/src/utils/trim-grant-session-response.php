<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils;

/**
 * Port of server/src/utils/trim-grant-session-response.js.
 *
 * Compact the OAuth token response before it is written to the signed session cookie. The full
 * token exchange payload (especially Cognito's dual JWTs plus a duplicated raw blob) can exceed
 * the browser's ~4 KB per-cookie limit and silently break the auth callback.
 *
 * Keep only fields that auth.callback / providers-registry actually read.
 */
final class TrimGrantSessionResponse
{
    /** `undefined` while compacting (dropped keys); `null` is dropped too, as upstream does. */
    private static function compact(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $entry) {
            $compacted = self::compact($entry);
            if ($compacted !== null) {
                $out[$key] = $compacted;
            }
        }

        return $out;
    }

    public static function trimGrantSessionResponse(mixed $grantResponse, ?string $provider): mixed
    {
        if (!is_array($grantResponse)) {
            return $grantResponse;
        }

        $raw = is_array($grantResponse['raw'] ?? null) ? $grantResponse['raw'] : [];

        return match ($provider) {
            'cognito' => self::compact([
                'id_token' => $grantResponse['id_token'] ?? null,
            ]),
            'twitter' => self::compact([
                'access_token' => $grantResponse['access_token'] ?? null,
                'access_secret' => $grantResponse['access_secret'] ?? null,
                'raw' => isset($raw['screen_name']) && $raw['screen_name'] !== '' && $raw['screen_name'] !== false && $raw['screen_name'] !== 0
                    ? ['screen_name' => $raw['screen_name']]
                    : null,
            ]),
            'vk' => self::compact([
                'access_token' => $grantResponse['access_token'] ?? null,
                'raw' => ($raw['email'] ?? null) !== null && ($raw['user_id'] ?? null) !== null
                    ? ['email' => $raw['email'], 'user_id' => $raw['user_id']]
                    : null,
            ]),
            default => self::compact([
                'access_token' => $grantResponse['access_token'] ?? null,
                'oauth_token' => $grantResponse['oauth_token'] ?? null,
                'id_token' => $grantResponse['id_token'] ?? null,
            ]),
        };
    }
}

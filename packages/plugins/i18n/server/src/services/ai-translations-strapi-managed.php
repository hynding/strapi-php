<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Strapi;

/** Port of server/src/services/ai-translations-strapi-managed.ts. */
final class AiTranslationsStrapiManaged
{
    public static function createStrapiManagedAiTranslationsProvider(Strapi $strapi): object
    {
        // TODO(ai): add a helper function to get the AI server URL
        $aiServerUrl = getenv('STRAPI_AI_URL') ?: 'https://strapi-ai.apps.strapi.io';

        return new class ($strapi, $aiServerUrl) {
            public string $name = 'strapi-managed';

            public function __construct(private readonly Strapi $strapi, private readonly string $aiServerUrl)
            {
            }

            private function getAiToken(): string
            {
                try {
                    $result = AiTranslations::aiAdminCall($this->strapi, 'getAiToken');
                    $token = is_array($result) ? ($result['token'] ?? null) : null;
                    if (!is_string($token)) {
                        throw new \RuntimeException('No token');
                    }

                    return $token;
                } catch (\Throwable $error) {
                    throw new \RuntimeException('Failed to retrieve AI token', 0, $error);
                }
            }

            /**
             * @param array{sourceLocale: string, targetLocales: list<string>, content: array<string, mixed>, contentTypeSchema: array<string, mixed>} $params
             */
            public function generateTranslations(array $params): mixed
            {
                $token = $this->getAiToken();

                $this->strapi->log()->debug('Contacting AI Server for localizations generation');
                $response = ($this->strapi->fetch())("{$this->aiServerUrl}/i18n/generate-localizations", [
                    'method' => 'POST',
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => "Bearer {$token}",
                    ],
                    'body' => json_encode([
                        'content' => $params['content'] === [] ? new \stdClass() : $params['content'],
                        'sourceLocale' => $params['sourceLocale'],
                        'targetLocales' => $params['targetLocales'],
                        'contentTypeSchema' => $params['contentTypeSchema'] === [] ? new \stdClass() : $params['contentTypeSchema'],
                    ], JSON_THROW_ON_ERROR),
                ]);

                if (!$response['ok']) {
                    $statusText = (string) ($response['statusText'] ?? '');
                    $this->strapi->log()->error("AI Localizations request failed: {$response['status']} {$statusText}");

                    throw new \RuntimeException("AI Localizations request failed: {$statusText}");
                }

                return json_decode((string) $response['body'], true);
            }
        };
    }
}

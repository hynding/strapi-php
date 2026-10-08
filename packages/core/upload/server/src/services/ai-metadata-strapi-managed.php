<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;

/**
 * Port of server/src/services/ai-metadata-strapi-managed.ts: the provider talking to the Strapi
 * AI server. The request is a multipart POST of the images under `files`.
 */
final class AiMetadataStrapiManaged
{
    public static function createStrapiManagedAiMetadataProvider(Strapi $strapi): object
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
                    $result = AiMetadataProvider::aiAdminCall($this->strapi, 'getAiToken');
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
             * @param array{images: list<array{data: string, type: string|null}>} $params
             * @return mixed
             */
            public function generateMetadata(array $params): mixed
            {
                $boundary = '----strapi' . bin2hex(random_bytes(8));
                $body = '';
                foreach ($params['images'] as $index => $image) {
                    $body .= "--{$boundary}\r\n"
                        . "Content-Disposition: form-data; name=\"files\"; filename=\"blob{$index}\"\r\n"
                        . 'Content-Type: ' . ($image['type'] ?? 'application/octet-stream') . "\r\n\r\n"
                        . $image['data'] . "\r\n";
                }
                $body .= "--{$boundary}--\r\n";

                $token = $this->getAiToken();

                $this->strapi->log()->info('Contacting AI Server for media metadata generation', [
                    'aiServerUrl' => $this->aiServerUrl,
                    'imageCount' => count($params['images']),
                ]);

                $res = ($this->strapi->fetch())("{$this->aiServerUrl}/media-library/generate-metadata", [
                    'method' => 'POST',
                    'body' => $body,
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                        'Content-Type' => "multipart/form-data; boundary={$boundary}",
                    ],
                ]);

                if (!$res['ok']) {
                    $this->strapi->log()->error("AI metadata generation request failed: {$res['status']}");

                    throw new \RuntimeException('AI metadata generation failed', 0, new \RuntimeException($res['body']));
                }

                return json_decode($res['body'], true);
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Tools;

use Strapi\Core\Services\Mcp\ToolRegistry;
use Strapi\Core\Strapi;
use Strapi\Utils\Sessions;
use Strapi\Utils\Zod\Z as z;
use Strapi\Utils\Zod\ZodType;

/** Port of services/mcp/tools/log.ts: the dev-mode `log` tool. */
final class Log
{
    private const MAX_MESSAGE_LENGTH = 10 * 1024;

    public static function inputSchema(): ZodType
    {
        return z::object([
            'message' => z::string()->describe('Message to log'),
            'level' => z::enum(['info', 'http', 'warn', 'error', 'log'])->default('info')->describe('Log level (default: info)'),
        ]);
    }

    public static function outputSchema(): ZodType
    {
        return z::object([
            'status' => z::string(),
            'message' => z::string(),
            'level' => z::string(),
            'timestamp' => z::string(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function logToolDefinition(): array
    {
        return ToolRegistry::makeMcpToolDefinition([
            'name' => 'log',
            'title' => 'Strapi Log',
            'description' => 'Logs a message to the Strapi logger with specified level',
            'resolveInputSchema' => static fn (): ZodType => self::inputSchema(),
            'resolveOutputSchema' => static fn (): ZodType => self::outputSchema(),
            'telemetry' => ['source' => 'core', 'name' => 'log'],
            'devModeOnly' => true,
            'createHandler' => static fn (Strapi $strapi): \Closure => static function (array $params) use ($strapi): array {
                $message = (string) $params['args']['message'];
                $level = (string) ($params['args']['level'] ?? 'info');

                $sanitizedMessage = self::sanitize($message);

                // Map level to appropriate logger method (`http` is Monolog's info, see strapi/logger)
                match ($level) {
                    'warn' => $strapi->log()->warning("[MCP] {$sanitizedMessage}"),
                    'error' => $strapi->log()->error("[MCP] {$sanitizedMessage}"),
                    default => $strapi->log()->info("[MCP] {$sanitizedMessage}"),
                };

                $result = [
                    'status' => 'logged',
                    'message' => $sanitizedMessage,
                    'level' => $level,
                    'timestamp' => Sessions::toISOString(new \DateTimeImmutable('now')),
                ];

                return [
                    'content' => [['type' => 'text', 'text' => self::stringify($result)]],
                    'structuredContent' => $result,
                ];
            },
        ]);
    }

    /** Security: sanitize the message to prevent log injection. */
    public static function sanitize(string $message): string
    {
        // 1. Limit length to prevent log spam (10KB max)
        $sanitized = mb_strlen($message) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '...[truncated]'
            : $message;

        // 2. Remove ANSI escape sequences, then control characters (C0, DEL, C1)
        $sanitized = (string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/u', '', $sanitized);
        $sanitized = (string) preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', '', $sanitized);

        // 3. Replace newlines with spaces to prevent fake log entry injection
        $sanitized = (string) preg_replace('/[\r\n]+/', ' ', $sanitized);

        // 4. Trim whitespace
        return trim($sanitized);
    }

    /**
     * `JSON.stringify(result, null, 2)`
     *
     * @param array<string, mixed> $value
     */
    private static function stringify(array $value): string
    {
        $json = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);
    }
}

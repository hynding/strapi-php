<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Sdk;

use Strapi\Utils\Zod\ZodType;

/**
 * Not an upstream file: the subset of `McpServer` (and the `Server`/`Protocol` it wraps) of
 * `@modelcontextprotocol/server` 2.0 that Strapi's MCP service uses, synchronous.
 *
 * - JSON-RPC 2.0 dispatch of the 2025-era methods: `initialize` (protocol version negotiation),
 *   `ping`, `logging/setLevel`, `tools/list|call`, `prompts/list|get`, `resources/list|read|
 *   templates/list`, and the `notifications/*` (ignored);
 * - tools: the input is validated against the Zod input schema (`Input validation error: Invalid
 *   arguments for tool <name>: <issues>`), the handler runs, a non-error result is validated
 *   against the output schema; any failure becomes a tool error result (`isError: true`), while an
 *   unknown or disabled tool is refused with JSON-RPC `-32602 Tool <name> not found|disabled`;
 * - schemas are advertised as JSON Schema 2020-12 (`z.toJSONSchema(schema, { target, io })`, the
 *   SDK's `standardSchemaToJsonSchema`).
 *
 * The transport (sdk/streamable-http-transport.php) feeds it one message at a time
 * ({@see self::handleMessage()}).
 *
 * @phpstan-type ServerContext array{sessionId: string|null, mcpReq: array{id: int|string, method: string, _meta?: mixed}, http?: array{req?: array{headers: array<string, string>, url: string}, authInfo?: mixed}}
 */
final class McpServer
{
    public const LATEST_PROTOCOL_VERSION = '2025-11-25';
    public const DEFAULT_NEGOTIATED_PROTOCOL_VERSION = '2025-03-26';
    public const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05', '2024-10-07'];
    private const JSON_SCHEMA_TARGET = 'draft-2020-12';
    private const LOGGING_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** @var array<string, mixed> */
    private array $capabilities;

    /** @var array<string, array{registered: RegisteredCapability, title: mixed, description: mixed, inputSchema: ZodType|null, outputSchema: ZodType|null, handler: callable}> */
    private array $tools = [];

    /** @var array<string, array{registered: RegisteredCapability, title: mixed, description: mixed, argsSchema: ZodType|null, handler: callable}> */
    private array $prompts = [];

    /** @var array<string, array{registered: RegisteredCapability, name: string, metadata: array<string, mixed>, handler: callable}> keyed by uri */
    private array $resources = [];

    private ?StreamableHttpTransport $transport = null;

    private ?string $loggingLevel = null;

    /**
     * @param array{name: string, version: string} $serverInfo
     * @param array{capabilities?: array<string, mixed>, instructions?: string} $options
     */
    public function __construct(private readonly array $serverInfo, private readonly array $options = [])
    {
        $this->capabilities = $options['capabilities'] ?? [];
        // listChanged is advertised as the SDK does when it installs the handlers
        foreach (['tools', 'prompts', 'resources'] as $kind) {
            if (array_key_exists($kind, $this->capabilities)) {
                $this->capabilities[$kind] = [...(array) $this->capabilities[$kind], 'listChanged' => $this->capabilities[$kind]['listChanged'] ?? true];
            }
        }
    }

    /** @return array<string, mixed> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    public function connect(StreamableHttpTransport $transport): void
    {
        $this->transport = $transport;
        $transport->connect($this);
    }

    public function close(): void
    {
        $this->transport = null;
    }

    public function isConnected(): bool
    {
        return $this->transport !== null;
    }

    /**
     * @param array{title?: mixed, description?: mixed, inputSchema?: ZodType|null, outputSchema?: ZodType|null} $config
     * @param callable $handler `(args, ctx)` with an input schema, `(ctx)` without
     */
    public function registerTool(string $name, array $config, callable $handler): RegisteredCapability
    {
        if (isset($this->tools[$name])) {
            throw new \RuntimeException("Tool {$name} is already registered");
        }
        $this->ensureCapability('tools');
        $registered = new RegisteredCapability(function () use ($name): void {
            unset($this->tools[$name]);
        });
        $this->tools[$name] = [
            'registered' => $registered,
            'title' => $config['title'] ?? null,
            'description' => $config['description'] ?? null,
            'inputSchema' => $config['inputSchema'] ?? null,
            'outputSchema' => $config['outputSchema'] ?? null,
            'handler' => $handler,
        ];

        return $registered;
    }

    /**
     * @param array{title?: mixed, description?: mixed, argsSchema?: ZodType|null} $config
     * @param callable $handler `(args, ctx)` with an args schema, `(ctx)` without
     */
    public function registerPrompt(string $name, array $config, callable $handler): RegisteredCapability
    {
        if (isset($this->prompts[$name])) {
            throw new \RuntimeException("Prompt {$name} is already registered");
        }
        $this->ensureCapability('prompts');
        $registered = new RegisteredCapability(function () use ($name): void {
            unset($this->prompts[$name]);
        });
        $this->prompts[$name] = [
            'registered' => $registered,
            'title' => $config['title'] ?? null,
            'description' => $config['description'] ?? null,
            'argsSchema' => $config['argsSchema'] ?? null,
            'handler' => $handler,
        ];

        return $registered;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param callable $readCallback `(uri, ctx)`
     */
    public function registerResource(string $name, string $uri, array $metadata, callable $readCallback): RegisteredCapability
    {
        if (isset($this->resources[$uri])) {
            throw new \RuntimeException("Resource {$uri} is already registered");
        }
        $this->ensureCapability('resources');
        $registered = new RegisteredCapability(function () use ($uri): void {
            unset($this->resources[$uri]);
        });
        $this->resources[$uri] = ['registered' => $registered, 'name' => $name, 'metadata' => $metadata, 'handler' => $readCallback];

        return $registered;
    }

    /**
     * Dispatches one JSON-RPC message. Returns the response for a request, `null` for a
     * notification or a response.
     *
     * @param array<string, mixed> $message
     * @param array{headers?: array<string, string>, url?: string, authInfo?: mixed} $requestInfo
     * @return array<string, mixed>|null
     */
    public function handleMessage(array $message, array $requestInfo = []): ?array
    {
        if (!isset($message['method']) || !array_key_exists('id', $message)) {
            // a notification (notifications/initialized, cancelled...) or a response: nothing to answer
            return null;
        }

        $id = $message['id'];
        $method = (string) $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        /** @var ServerContext $ctx */
        $ctx = [
            'sessionId' => null,
            'mcpReq' => ['id' => $id, 'method' => $method, ...(isset($params['_meta']) ? ['_meta' => $params['_meta']] : [])],
            'http' => [
                ...(isset($requestInfo['headers']) ? ['req' => ['headers' => $requestInfo['headers'], 'url' => $requestInfo['url'] ?? '']] : []),
                ...(isset($requestInfo['authInfo']) ? ['authInfo' => $requestInfo['authInfo']] : []),
            ],
        ];

        try {
            $result = $this->dispatch($method, $params, $ctx);

            return ['result' => $result, 'jsonrpc' => '2.0', 'id' => $id];
        } catch (ProtocolError $error) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage(), ...($error->data !== null ? ['data' => $error->data] : [])]];
        } catch (\Throwable $error) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => ProtocolError::INTERNAL_ERROR, 'message' => $error->getMessage() !== '' ? $error->getMessage() : 'Internal error']];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param ServerContext $ctx
     */
    private function dispatch(string $method, array $params, array $ctx): mixed
    {
        return match ($method) {
            'initialize' => $this->onInitialize($params),
            'ping' => new \stdClass(),
            'logging/setLevel' => $this->onSetLevel($params),
            'tools/list' => $this->requireCapability('tools', fn (): array => ['tools' => $this->listTools()]),
            'tools/call' => $this->requireCapability('tools', fn (): array => $this->callTool($params, $ctx)),
            'prompts/list' => $this->requireCapability('prompts', fn (): array => ['prompts' => $this->listPrompts()]),
            'prompts/get' => $this->requireCapability('prompts', fn (): array => $this->getPrompt($params, $ctx)),
            'resources/list' => $this->requireCapability('resources', fn (): array => ['resources' => $this->listResources()]),
            'resources/templates/list' => $this->requireCapability('resources', fn (): array => ['resourceTemplates' => []]),
            'resources/read' => $this->requireCapability('resources', fn (): array => $this->readResource($params, $ctx)),
            default => throw new ProtocolError(ProtocolError::METHOD_NOT_FOUND, 'Method not found'),
        };
    }

    /**
     * @param \Closure(): array<string, mixed> $handler
     * @return array<string, mixed>
     */
    private function requireCapability(string $capability, \Closure $handler): array
    {
        if (!array_key_exists($capability, $this->capabilities)) {
            throw new ProtocolError(ProtocolError::METHOD_NOT_FOUND, 'Method not found');
        }

        return $handler();
    }

    private function ensureCapability(string $capability): void
    {
        if (!array_key_exists($capability, $this->capabilities)) {
            $this->capabilities[$capability] = ['listChanged' => true];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function onInitialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $protocolVersion = is_string($requested) && in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true) ? $requested : self::SUPPORTED_PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $protocolVersion,
            'capabilities' => self::objects($this->capabilities),
            'serverInfo' => $this->serverInfo,
            ...(isset($this->options['instructions']) && $this->options['instructions'] !== '' ? ['instructions' => $this->options['instructions']] : []),
        ];
    }

    /** @param array<string, mixed> $params */
    private function onSetLevel(array $params): \stdClass
    {
        $level = $params['level'] ?? null;
        if (is_string($level) && in_array($level, self::LOGGING_LEVELS, true)) {
            $this->loggingLevel = $level;
        }

        return new \stdClass();
    }

    public function loggingLevel(): ?string
    {
        return $this->loggingLevel;
    }

    /** @return list<array<string, mixed>> */
    private function listTools(): array
    {
        $tools = [];
        foreach ($this->tools as $name => $tool) {
            if (!$tool['registered']->enabled) {
                continue;
            }
            $definition = [
                'name' => $name,
                ...($tool['title'] !== null ? ['title' => $tool['title']] : []),
                ...($tool['description'] !== null ? ['description' => $tool['description']] : []),
                'inputSchema' => $tool['inputSchema'] !== null ? self::standardSchemaToJsonSchema($tool['inputSchema'], 'input') : ['type' => 'object', 'properties' => new \stdClass()],
            ];
            if ($tool['outputSchema'] !== null) {
                $definition['outputSchema'] = self::standardSchemaToJsonSchema($tool['outputSchema'], 'output');
            }
            $tools[] = $definition;
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $params
     * @param ServerContext $ctx
     * @return array<string, mixed>
     */
    private function callTool(array $params, array $ctx): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name)) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, 'Invalid params: name must be a string');
        }
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Tool {$name} not found");
        }
        if (!$tool['registered']->enabled) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Tool {$name} disabled");
        }

        try {
            $result = null;
            if ($tool['inputSchema'] !== null) {
                $args = self::validate($tool['inputSchema'], $params['arguments'] ?? [], "Input validation error: Invalid arguments for tool {$name}");
                $result = ($tool['handler'])($args, $ctx);
            } else {
                $result = ($tool['handler'])($ctx);
            }
            if (!is_array($result)) {
                throw new \UnexpectedValueException("Tool {$name} returned an invalid result");
            }

            if ($tool['outputSchema'] !== null && ($result['isError'] ?? false) !== true) {
                if (!array_key_exists('structuredContent', $result)) {
                    throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Output validation error: Tool {$name} has an output schema but no structured content was provided");
                }
                self::validate($tool['outputSchema'], $result['structuredContent'], "Output validation error: Invalid structured content for tool {$name}");
            }

            return self::projectCallToolResult($result);
        } catch (\Throwable $error) {
            return ['content' => [['type' => 'text', 'text' => $error->getMessage()]], 'isError' => true];
        }
    }

    /**
     * SEP-2106 §4.3 TextContent auto-append and the 2025-era `{ result }` wrap of a non-object
     * `structuredContent`.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function projectCallToolResult(array $result): array
    {
        if (!array_key_exists('structuredContent', $result)) {
            return $result;
        }
        $sc = $result['structuredContent'];
        $isObject = (is_array($sc) && ($sc === [] || !array_is_list($sc))) || $sc instanceof \stdClass || $sc instanceof \JsonSerializable;
        if ($isObject) {
            return $result;
        }
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $hasText = false;
        foreach ($content as $block) {
            $hasText = $hasText || (is_array($block) && ($block['type'] ?? null) === 'text');
        }
        if (!$hasText) {
            $content[] = ['type' => 'text', 'text' => (string) json_encode($sc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }

        return [...$result, 'content' => $content, 'structuredContent' => ['result' => $sc]];
    }

    /** @return list<array<string, mixed>> */
    private function listPrompts(): array
    {
        $prompts = [];
        foreach ($this->prompts as $name => $prompt) {
            if (!$prompt['registered']->enabled) {
                continue;
            }
            $prompts[] = [
                'name' => $name,
                ...($prompt['title'] !== null ? ['title' => $prompt['title']] : []),
                ...($prompt['description'] !== null ? ['description' => $prompt['description']] : []),
                ...($prompt['argsSchema'] !== null ? ['arguments' => self::promptArguments($prompt['argsSchema'])] : []),
            ];
        }

        return $prompts;
    }

    /** @return list<array{name: string, description?: string, required: bool}> */
    private static function promptArguments(ZodType $schema): array
    {
        $json = self::standardSchemaToJsonSchema($schema, 'input');
        $properties = is_array($json['properties'] ?? null) ? $json['properties'] : [];
        $required = is_array($json['required'] ?? null) ? $json['required'] : [];
        $out = [];
        foreach ($properties as $name => $prop) {
            $out[] = [
                'name' => (string) $name,
                ...(is_array($prop) && isset($prop['description']) ? ['description' => (string) $prop['description']] : []),
                'required' => in_array($name, $required, true),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @param ServerContext $ctx
     * @return array<string, mixed>
     */
    private function getPrompt(array $params, array $ctx): array
    {
        $name = (string) ($params['name'] ?? '');
        $prompt = $this->prompts[$name] ?? null;
        if ($prompt === null) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Prompt {$name} not found");
        }
        if (!$prompt['registered']->enabled) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Prompt {$name} disabled");
        }
        if ($prompt['argsSchema'] === null) {
            return ($prompt['handler'])($ctx);
        }
        $args = self::validate($prompt['argsSchema'], $params['arguments'] ?? [], "Invalid arguments for prompt {$name}", ProtocolError::INVALID_PARAMS);

        return ($prompt['handler'])($args, $ctx);
    }

    /** @return list<array<string, mixed>> */
    private function listResources(): array
    {
        $resources = [];
        foreach ($this->resources as $uri => $resource) {
            if (!$resource['registered']->enabled) {
                continue;
            }
            $resources[] = ['uri' => (string) $uri, 'name' => $resource['name'], ...array_filter($resource['metadata'], static fn (mixed $v): bool => $v !== null)];
        }

        return $resources;
    }

    /**
     * @param array<string, mixed> $params
     * @param ServerContext $ctx
     * @return array<string, mixed>
     */
    private function readResource(array $params, array $ctx): array
    {
        $uri = (string) ($params['uri'] ?? '');
        if (parse_url($uri, PHP_URL_SCHEME) === null || parse_url($uri, PHP_URL_SCHEME) === false) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Resource URI {$uri} is invalid", ['uri' => $uri, 'reason' => 'invalid_uri']);
        }
        $resource = $this->resources[$uri] ?? null;
        if ($resource === null) {
            throw new ProtocolError(ProtocolError::RESOURCE_NOT_FOUND, "Resource {$uri} not found", ['uri' => $uri]);
        }
        if (!$resource['registered']->enabled) {
            throw new ProtocolError(ProtocolError::INVALID_PARAMS, "Resource {$uri} disabled");
        }

        return ($resource['handler'])($uri, $ctx);
    }

    /**
     * `validateStandardSchema`: the parsed data, or a ProtocolError with the issues formatted as
     * `path.to.key: message`, joined by `, `.
     */
    private static function validate(ZodType $schema, mixed $data, string $prefix, int $code = ProtocolError::INVALID_PARAMS): mixed
    {
        $result = $schema->safeParse($data);
        if ($result['success'] === true) {
            return $result['data'];
        }
        $issues = $result['error']->issues ?? [];
        $messages = [];
        foreach ($issues as $issue) {
            $path = is_array($issue['path'] ?? null) ? $issue['path'] : [];
            $message = (string) ($issue['message'] ?? '');
            $messages[] = $path === [] ? $message : implode('.', array_map(static fn (mixed $p): string => is_scalar($p) ? (string) $p : '', $path)) . ': ' . $message;
        }

        throw new ProtocolError($code, $prefix . ': ' . implode(', ', $messages));
    }

    /**
     * `standardSchemaToJsonSchema(schema, io)`: Zod → JSON Schema 2020-12. An input schema must
     * describe an object (`type: "object"` is stamped when absent); an output schema keeps its root.
     *
     * @param 'input'|'output' $io
     * @return array<string, mixed>
     */
    public static function standardSchemaToJsonSchema(ZodType $schema, string $io = 'input'): array
    {
        $result = $schema->toJSONSchema(['target' => self::JSON_SCHEMA_TARGET, 'io' => $io]);

        if ($io === 'output') {
            if (array_key_exists('type', $result)) {
                return $result;
            }

            return self::isProvablyObjectShapedRoot($result) ? ['type' => 'object', ...$result] : $result;
        }

        if (array_key_exists('type', $result) && $result['type'] !== 'object') {
            throw new \InvalidArgumentException('MCP tool and prompt schemas must describe objects (got type: ' . json_encode($result['type']) . '). Wrap your schema in z.object({...}) or equivalent.');
        }

        return ['type' => 'object', ...$result];
    }

    /** @param array<string, mixed> $schema */
    private static function isProvablyObjectShapedRoot(array $schema): bool
    {
        foreach (['properties', 'patternProperties', 'additionalProperties', 'required'] as $key) {
            if (array_key_exists($key, $schema)) {
                return true;
            }
        }
        foreach (['oneOf', 'anyOf', 'allOf'] as $key) {
            $members = $schema[$key] ?? null;
            if (is_array($members) && $members !== []) {
                foreach ($members as $m) {
                    if (!is_array($m) || (($m['type'] ?? null) !== 'object' && !self::isProvablyObjectShapedRoot($m))) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    /**
     * JSON objects for the capability maps: `{}` must not encode as `[]`.
     *
     * @param array<string, mixed> $map
     */
    private static function objects(array $map): \stdClass
    {
        $out = new \stdClass();
        foreach ($map as $key => $value) {
            $out->{$key} = is_array($value) && ($value === [] || !array_is_list($value)) ? self::objects($value) : $value;
        }

        return $out;
    }
}

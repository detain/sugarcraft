# CandyCrush

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-crush)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-crush)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-crush?label=packagist)](https://packagist.org/packages/sugarcraft/candy-crush)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

TUI AI coding assistant — multi-provider, multi-agent, skill-aware.

```sh
composer require sugarcraft/candy-crush
```

## Overview

CandyCrush is a terminal UI application for AI-assisted coding. It provides a
batteries-included interface for interacting with multiple AI providers
(OpenAI, SGLANG, Claude Code, etc.) with support for custom skills, hooks,
and MCP tool discovery.

## Status

🟢 Step 3.1 complete — OpenAI Provider implementation.
🟢 Step 3.2 complete — SGLANG Provider implementation.
🟢 Step 3.3 complete — Claude Code Provider implementation.
🟢 Step 3.4 complete — AWS Bedrock Provider implementation.

## Step 3.1: OpenAI Provider

Step 3.1 implements the `OpenAIProvider` — a `ProviderInterface` implementation that translates CandyCrush requests into OpenAI Chat Completions API calls.

### OpenAIProvider Class

```php
final readonly class OpenAIProvider implements ProviderInterface
{
    public function __construct(
        private Client $client,
        private string $defaultModel = 'gpt-4o',
    ) {}
}
```

The provider requires an OpenAI `Client` instance (from the `openai-php/client` package) and optionally accepts a default model name.

### Provider Interface Implementation

The `OpenAIProvider` implements all `ProviderInterface` methods:

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'openai'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | OpenAI supports streaming |
| `supportsFunctionCalling()` | Returns `true` | OpenAI supports tool calls |
| `supportsVision()` | Returns `true` | GPT-4o supports image input |
| `supportsJsonSchema()` | Returns `false` | OpenAI uses `additionalKwargs` |
| `contextWindow()` | Varies by model | 128k for GPT-4o, 8k for GPT-4, etc. |
| `costPer1kTokens()` | Model-based pricing | Input vs output differentiation |

### Context Window by Model

```php
public function contextWindow(): int
{
    return match ($this->defaultModel) {
        'gpt-4o' => 128_000,
        'gpt-4-turbo' => 128_000,
        'gpt-4' => 8_192,
        'gpt-3.5-turbo' => 16_385,
        default => 8_192,
    };
}
```

### Pricing by Model

```php
public function costPer1kTokens(string $model, string $direction): float
{
    return match ($model) {
        'gpt-4o' => $direction === 'input' ? 0.005 : 0.015,
        'gpt-4-turbo' => $direction === 'input' ? 0.01 : 0.03,
        'gpt-4' => $direction === 'input' ? 0.03 : 0.06,
        'gpt-3.5-turbo' => $direction === 'input' ? 0.0005 : 0.0015,
        default => 0.01,
    };
}
```

### complete() Method

The `complete()` method handles synchronous chat completions:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
    ];

    if ($request->tools !== null) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    if ($request->systemPrompt !== null) {
        $params['messages'] = array_merge(
            [['role' => 'system', 'content' => $request->systemPrompt]],
            $params['messages']
        );
    }

    $response = $this->client->chat()->create($params);

    return $this->parseResponse($response);
}
```

**Key behaviors:**
- System prompts are prepended to the messages array
- Tools are formatted as OpenAI function calling schema
- Temperature defaults to 0.7 when not specified
- Max tokens default to 4096 when not specified

### completeStream() Method

The `completeStream()` method handles streaming responses using a generator:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
        'stream' => true,
    ];

    if ($request->tools !== null) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    $stream = $this->client->chat()->createStreamed($params);

    foreach ($stream as $chunk) {
        yield $this->parseChunk($chunk);
    }
}
```

**Streaming design notes:**
- Returns a `\Generator` that yields `CompleteResponse` objects per chunk
- Each chunk contains only the delta content from that iteration
- `tokensUsed` and `costUsd` are always 0 for streaming chunks (usage data only available at stream completion)
- Caller is responsible for accumulating content across chunks

### Message Formatting

The `formatMessages()` method translates CandyCrush `Message` objects to OpenAI format:

```php
private function formatMessages(array $messages): array
{
    return array_map(function (Message $msg) {
        return match (true) {
            $msg instanceof UserMessage => ['role' => 'user', 'content' => $msg->content()],
            $msg instanceof AssistantMessage => array_filter([
                'role' => 'assistant',
                'content' => $msg->content(),
                'tool_calls' => $msg->toolCalls(),
            ]),
            $msg instanceof SystemMessage => ['role' => 'system', 'content' => $msg->content()],
            $msg instanceof ToolResultMessage => [
                'role' => 'tool',
                'tool_call_id' => $msg->toolCallId(),
                'content' => $msg->content(),
            ],
            default => ['role' => 'user', 'content' => $msg->content()],
        };
    }, $messages);
}
```

**Key translation rules:**
- `UserMessage` → `{role: 'user', content: ...}`
- `AssistantMessage` → `{role: 'assistant', content: ..., tool_calls: [...]}` (filtered to remove nulls)
- `SystemMessage` → `{role: 'system', content: ...}`
- `ToolResultMessage` → `{role: 'tool', tool_call_id: ..., content: ...}`

### Tool Formatting

The `formatTools()` method translates CandyCrush `Tool` objects to OpenAI function schema:

```php
private function formatTools(array $tools): array
{
    return array_map(function (Tool $tool) {
        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->inputSchema(),
            ],
        ];
    }, $tools);
}
```

### Response Parsing

The `parseResponse()` method extracts data from the OpenAI response:

```php
private function parseResponse(CompletionResponse $response): CompleteResponse
{
    $choices = $response->toArray()['choices'][0] ?? [];
    $message = $choices['message'] ?? [];

    $toolCalls = null;
    if (isset($message['tool_calls'])) {
        $toolCalls = array_map(
            fn($tc) => ToolCall::fromArray([
                'id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
            ]),
            $message['tool_calls']
        );
    }

    return new CompleteResponse(
        content: $message['content'] ?? '',
        reasoning: null,
        toolCalls: $toolCalls,
        tokensUsed: $response->usage['total_tokens'] ?? 0,
        costUsd: $this->calculateCost($response),
    );
}
```

### Streaming Chunk Parsing

The `parseChunk()` method handles individual streaming chunks:

```php
private function parseChunk(mixed $chunk): CompleteResponse
{
    $delta = $chunk->toArray()['choices'][0]['delta'] ?? [];

    return new CompleteResponse(
        content: $delta['content'] ?? '',
        reasoning: null,
        toolCalls: null,
        tokensUsed: 0,
        costUsd: 0.0,
    );
}
```

**Important**: Streaming chunks return empty `toolCalls`, `tokensUsed`, and `costUsd` because:
- Tool calls in streams arrive incrementally and would require assembly across chunks
- Usage statistics are only available in the final chunk
- Cost calculation requires usage data unavailable per-chunk

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    $response = $this->client->embeddings()->create([
        'model' => $request->model,
        'input' => $request->input,
    ]);

    return new EmbeddingsResponse(
        embeddings: array_map(
            fn($item) => $item['embedding'],
            $response->toArray()['data'] ?? []
        )
    );
}
```

### Usage Example

```php
use OpenAI\Client;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create provider
$client = Client::factory(['api_key' => 'sk-...']);
$provider = new OpenAIProvider($client, 'gpt-4o');

// Make a completion request
$request = new CompleteRequest(
    model: 'gpt-4o',
    messages: [new UserMessage('Hello, world!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
echo $response->tokensUsed;  // Token count
echo $response->costUsd;  // Calculated cost
```

### Provider Selection in App

The `App` state holds a `ProviderInterface`, allowing runtime provider swapping:

```php
final readonly class App
{
    public function __construct(
        public readonly ProviderInterface $provider,
        // ...
    ) {}
}
```

This design enables:
- Single `App` class works with any provider implementation
- Providers are injected at construction time
- Switching providers creates a new `App` instance via `withProvider()`

## Step 3.2: SGLANG Provider

Step 3.2 implements the `SglangProvider` — a `ProviderInterface` implementation that connects to SGLANG endpoints using the OpenAI-compatible API format. SGLANG is a fast serving framework for large language models that exposes an OpenAI-compatible REST API.

### SglangProvider Class

```php
final readonly class SglangProvider implements ProviderInterface
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private ?string $apiKey,
        private Client $httpClient,
    ) {}

    public static function openAiCompatible(
        string $baseUrl,
        string $model = 'MiniMax-M2.7',
        ?string $apiKey = null,
    ): self {
        // Factory method for OpenAI-compatible endpoints
    }
}
```

The provider requires a base URL pointing to a SGLANG server, a model name, and optionally an API key for authentication.

### Factory Method: openAiCompatible()

```php
public static function openAiCompatible(
    string $baseUrl,
    string $model = 'MiniMax-M2.7',
    ?string $apiKey = null,
): self {
    $headers = [
        'Content-Type' => 'application/json',
    ];

    if ($apiKey !== null) {
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $client = new Client([
        'base_uri' => $baseUrl,
        'headers' => $headers,
    ]);

    return new self($baseUrl, $model, $apiKey, $client);
}
```

**Key design decisions:**
- Uses `GuzzleHttp\Client` directly rather than the OpenAI SDK, since SGLANG is OpenAI-compatible but not OpenAI-specific
- Default model is `MiniMax-M2.7` (the model's name in SGLANG's configuration)
- API key is optional — SGLANG servers may not require authentication
- `base_uri` ensures all requests are relative to the SGLANG endpoint

### Provider Interface Implementation

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'sglang'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | SGLANG supports streaming |
| `supportsFunctionCalling()` | Returns `true` | SGLANG supports tool calls |
| `supportsVision()` | Returns `false` | SGLANG text models only |
| `supportsJsonSchema()` | Returns `false` | SGLANG uses additional_kwargs |
| `contextWindow()` | Returns `128_000` | Varies by model |
| `costPer1kTokens()` | Returns `0.0` | Self-hosted, no cost |

**Self-hosted rationale**: SGLANG is typically deployed for self-hosted models where cost tracking is not applicable. The zero cost is a placeholder that consumers can override if needed.

### complete() Method

The `complete()` method handles synchronous chat completions:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
    ];

    if ($request->tools !== null) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    try {
        $response = $this->httpClient->post('/chat/completions', [
            'json' => $params,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $this->parseResponse($data);
    } catch (GuzzleException $e) {
        throw new \RuntimeException('SGLANG request failed: ' . $e->getMessage(), 0, $e);
    }
}
```

**Key behaviors:**
- Posts to `/chat/completions` endpoint (OpenAI-compatible)
- Formats messages and tools identically to OpenAIProvider
- Uses Guzzle's `json` option for automatic JSON encoding
- Wraps `GuzzleException` in `RuntimeException` for provider-agnostic error handling

### completeStream() Method

The `completeStream()` method handles streaming responses using a generator:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    $params = [
        'model' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'temperature' => $request->temperature ?? 0.7,
        'max_tokens' => $request->maxTokens ?? 4096,
        'stream' => true,
    ];

    if ($request->tools !== null) {
        $params['tools'] = $this->formatTools($request->tools);
    }

    try {
        $response = $this->httpClient->post('/chat/completions', [
            'json' => $params,
            'stream' => true,
        ]);

        $stream = $response->getBody();

        while (!$stream->eof()) {
            $line = $stream->readLine();
            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
                if ($data !== null && isset($data['choices'][0]['delta'])) {
                    yield $this->parseChunk($data);
                }
            }
        }
    } catch (GuzzleException $e) {
        throw new \RuntimeException('SGLANG request failed: ' . $e->getMessage(), 0, $e);
    }
}
```

**SSE line parsing**: SGLANG uses Server-Sent Events (SSE) format where each line starts with `data: `. The code:
1. Reads lines from the stream via `readLine()`
2. Filters to lines starting with `data: `
3. Strips the prefix and JSON-decodes the payload
4. Yields only chunks with `choices[0].delta` content

**Why manual parsing?** Unlike the OpenAI SDK's `createStreamed()` which returns an iterator of parsed chunks, raw Guzzle requires manual SSE parsing. This gives full control over the streaming protocol.

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    try {
        $response = $this->httpClient->post('/embeddings', [
            'json' => [
                'model' => $request->model,
                'input' => $request->input,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return new EmbeddingsResponse(
            embeddings: array_map(
                fn($item) => $item['embedding'],
                $data['data'] ?? []
            )
        );
    } catch (GuzzleException $e) {
        return new EmbeddingsResponse(embeddings: []);
    }
}
```

**Design note**: Embeddings failures return an empty array rather than throwing. This differs from `complete()` which throws on failure. Embeddings are optional functionality — empty results are preferable to exceptions.

### Message Formatting

The `formatMessages()` method uses the same translation logic as OpenAIProvider:

```php
private function formatMessages(array $messages): array
{
    return array_map(function (Message $msg) {
        return match (true) {
            $msg instanceof UserMessage => ['role' => 'user', 'content' => $msg->content()],
            $msg instanceof AssistantMessage => array_filter([
                'role' => 'assistant',
                'content' => $msg->content(),
                'tool_calls' => $msg->toolCalls(),
            ]),
            $msg instanceof SystemMessage => ['role' => 'system', 'content' => $msg->content()],
            $msg instanceof ToolResultMessage => [
                'role' => 'tool',
                'tool_call_id' => $msg->toolCallId(),
                'content' => $msg->content(),
            ],
            default => ['role' => 'user', 'content' => $msg->content()],
        };
    }, $messages);
}
```

### Response Parsing

The `parseResponse()` method extracts data from SGLANG's JSON response:

```php
private function parseResponse(array $data): CompleteResponse
{
    $choice = $data['choices'][0] ?? [];
    $message = $choice['message'] ?? [];

    $toolCalls = null;
    if (isset($message['tool_calls'])) {
        $toolCalls = array_map(
            fn($tc) => ToolCall::fromArray([
                'id' => $tc['id'],
                'name' => $tc['function']['name'],
                'arguments' => is_string($tc['function']['arguments'] ?? '')
                    ? json_decode($tc['function']['arguments'], true) ?? []
                    : ($tc['function']['arguments'] ?? []),
            ]),
            $message['tool_calls']
        );
    }

    return new CompleteResponse(
        content: $message['content'] ?? '',
        reasoning: null,
        toolCalls: $toolCalls,
        tokensUsed: $data['usage']['total_tokens'] ?? 0,
        costUsd: 0.0,
    );
}
```

**Tool call argument handling**: SGLANG may return `arguments` as either a JSON string or already-decoded array. The code handles both cases:

```php
arguments: is_string($tc['function']['arguments'] ?? '')
    ? json_decode($tc['function']['arguments'], true) ?? []
    : ($tc['function']['arguments'] ?? [])
```

### Usage Example

```php
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create provider pointing to local SGLANG server
$provider = SglangProvider::openAiCompatible(
    baseUrl: 'http://localhost:30000',
    model: 'MiniMax-M2.7',
    apiKey: null,  // No auth for local dev
);

// Make a completion request
$request = new CompleteRequest(
    model: 'MiniMin-M2.7',
    messages: [new UserMessage('Hello, world!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
```

### SGLANG Server Requirements

SGLANG must be running with OpenAI-compatible endpoints enabled:

```bash
python -m sglang.launch_server \
    --model-path <model> \
    --port 30000 \
    --chat-template chatml
```

The server exposes:
- `POST /chat/completions` — Chat completions (synchronous and streaming)
- `POST /embeddings` — Text embeddings

### Why OpenAI-Compatible?

SGLANG's API is designed to be OpenAI-compatible, meaning:
- Same request/response format as OpenAI Chat Completions API
- Same tool calling schema
- Same streaming format (SSE with `data: ` prefix)

This compatibility allows CandyCrush to support SGLANG with minimal provider-specific code — the message formatting and tool formatting logic is identical to OpenAIProvider.

## Step 3.3: Claude Code Provider

Step 3.3 implements the `ClaudeCodeProvider` — a `ProviderInterface` implementation that wraps the Claude Code CLI (`claude`) as a local AI backend. Unlike the OpenAI and SGLANG providers that make HTTP requests, the Claude Code provider spawns a subprocess and communicates via stdin/stdout.

### ClaudeCodeInvocation Class

The `ClaudeCodeInvocation` class encapsulates the subprocess lifecycle:

```php
final readonly class ClaudeCodeInvocation
{
    public function __construct(
        private string $claudePath = 'claude',
        private string $configDir = '~/.claude',
        private ?string $sessionId = null,
    ) {}
}
```

**Key design decisions:**
- `claudePath` defaults to `'claude'` expecting the CLI to be in PATH
- `configDir` allows specifying a custom Claude configuration directory
- `sessionId` enables session resumption via `--resume` flag

### baseArgs() Method

Builds the base arguments for all Claude Code invocations:

```php
public function baseArgs(): array
{
    $args = ['--output-format', 'json'];

    if ($this->sessionId !== null) {
        $args[] = '--resume';
        $args[] = $this->sessionId;
    }

    return $args;
}
```

**Key behaviors:**
- `--output-format json` is always included for machine-readable output
- `--resume` with session ID enables continuing a prior conversation

### printModeArgs() Method

Builds arguments for headless print mode (`-p`):

```php
public function printModeArgs(string $prompt, array $options = []): array
{
    $args = ['-p', $prompt];
    $args[] = '--output-format';
    $args[] = $options['format'] ?? 'json';

    if ($options['bare'] ?? false) {
        $args[] = '--bare';
    }

    if ($options['allowedTools'] !== null) {
        $args[] = '--allowedTools';
        $args[] = $options['allowedTools'];
    }

    if ($options['systemPrompt'] !== null) {
        $args[] = '--system-prompt';
        $args[] = $options['systemPrompt'];
    }

    // ... additional options: maxBudgetUsd, maxTurns, permissionMode
    return $args;
}
```

**Options supported:**
- `format` — Output format (`json` or `stream-json` for streaming)
- `bare` — Excludes decorative elements from output
- `allowedTools` — Comma-separated list of permitted tools
- `systemPrompt` — System prompt to inject
- `maxBudgetUsd` — Spending limit
- `maxTurns` — Maximum conversation turns
- `permissionMode` — Permission handling mode (`auto`, `bypassPermissions`)
- `continue` — Continue the last agent message

### execute() Method

Spawns the Claude Code process and returns output:

```php
public function execute(array $args, ?callable $onChunk = null): string
{
    $cmd = array_merge([$this->claudePath], $this->baseArgs(), $args);

    $process = proc_open(
        $cmd,
        [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ],
        $pipes,
        null,
        [
            'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
            'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
            'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
        ]
    );

    // ... stream handling
}
```

**Process management:**
- Uses `proc_open()` for full control over stdin/stdout/stderr
- Passes Anthropic environment variables to the subprocess
- Supports optional streaming callback for real-time output processing
- Handles exit codes 0 and -1 as success (graceful termination)

### ClaudeCodeProvider Class

```php
final readonly class ClaudeCodeProvider implements ProviderInterface
{
    public function __construct(
        private ClaudeCodeInvocation $invocation,
        private string $defaultModel = 'claude-sonnet-4-6',
    ) {}
}
```

The provider delegates subprocess management to `ClaudeCodeInvocation` and handles response parsing.

### Provider Interface Implementation

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'claude-code'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | Claude Code supports streaming |
| `supportsFunctionCalling()` | Returns `true` | Claude Code supports tool calls |
| `supportsVision()` | Returns `false` | CLI doesn't support image input |
| `supportsJsonSchema()` | Returns `true` | Claude Code supports JSON schema |
| `contextWindow()` | Returns `200_000` | All Claude models share 200k context |
| `costPer1kTokens()` | Returns `0.0` | Claude Code handles its own billing |

### complete() Method

Handles synchronous chat completions using print mode:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $prompt = $this->buildPrompt($request->messages);

    $options = [
        'format' => 'json',
        'bare' => true,
        'systemPrompt' => $request->systemPrompt,
    ];

    if ($request->tools !== null) {
        $toolNames = array_map(fn($t) => $t->name(), $request->tools);
        $options['allowedTools'] = implode(',', $toolNames);
    }

    $output = $this->invocation->execute(
        $this->invocation->printModeArgs($prompt, $options)
    );

    return $this->parseJsonResponse($output);
}
```

**Key behaviors:**
- Messages are concatenated into a single prompt string
- Tools are passed as comma-separated names via `--allowedTools`
- JSON response is parsed for content, tool calls, and usage

### completeStream() Method

Handles streaming responses via SSE-like newline-delimited JSON:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    // ... setup similar to complete()

    $process = proc_open($cmd, [...], $pipes, ...);

    $buffer = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 8192);
        $buffer .= $chunk;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
                if ($data !== null) {
                    yield $this->parseChunk($data);
                }
            }
        }
    }
}
```

**Streaming design notes:**
- Cannot use `yield` inside a closure passed to `execute()`, so streaming opens the process directly
- Buffer accumulates chunks until a newline is found
- Each line starting with `data: ` is parsed as a JSON object
- Text deltas are extracted from `event.delta.type === 'text_delta'`

### buildPrompt() Method

Converts message objects to a plain text prompt:

```php
private function buildPrompt(array $messages): string
{
    $parts = [];

    foreach ($messages as $msg) {
        $parts[] = match (true) {
            $msg instanceof UserMessage => "User: {$msg->content()}",
            $msg instanceof AssistantMessage => "Assistant: {$msg->content()}",
            $msg instanceof SystemMessage => "System: {$msg->content()}",
            $msg instanceof ToolResultMessage => "Tool Result: {$msg->content()}",
            default => "User: {$msg->content()}",
        };
    }

    return implode("\n\n", $parts);
}
```

**Format:** Messages are joined with double newlines, prefixed with their role. This differs from the OpenAI provider which uses structured JSON — the Claude Code CLI expects plain text.

### parseJsonResponse() Method

Parses the JSON output from Claude Code:

```php
private function parseJsonResponse(string $output): CompleteResponse
{
    $data = json_decode($output, true);

    if ($data === null) {
        return new CompleteResponse(
            content: $output,  // Return raw on parse failure
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    if (isset($data['error'])) {
        $errorMsg = is_string($data['error']) ? $data['error'] : ($data['error']['message'] ?? 'Unknown error');
        return new CompleteResponse(
            content: "[Error: $errorMsg]",
            // ...
        );
    }

    return new CompleteResponse(
        content: $data['result'] ?? $data['content'] ?? '',
        reasoning: $data['reasoning'] ?? null,
        toolCalls: $this->parseToolCalls($data['tool_calls'] ?? []),
        tokensUsed: $data['usage']['total_tokens'] ?? 0,
        costUsd: $data['total_cost_usd'] ?? 0.0,
    );
}
```

**Key behaviors:**
- Parse failure returns raw output as content (graceful degradation)
- Error responses are wrapped in a descriptive content string
- Supports both `result` and `content` keys for main text
- Tool calls are parsed if present

### parseToolCalls() Method

Converts Claude Code tool call format to `ToolCall` objects:

```php
private function parseToolCalls(array $toolCalls): ?array
{
    if (empty($toolCalls)) {
        return null;
    }

    return array_map(function ($tc) {
        return ToolCall::fromArray([
            'id' => $tc['id'] ?? uniqid('tool_'),
            'name' => $tc['name'] ?? $tc['function']['name'] ?? '',
            'arguments' => is_string($tc['arguments'] ?? null)
                ? json_decode($tc['arguments'], true) ?? []
                : ($tc['arguments'] ?? []),
        ]);
    }, $toolCalls);
}
```

**Dual format handling:** Arguments may be a JSON string or already-decoded array, similar to the SGLANG provider pattern.

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    // Claude Code doesn't directly support embeddings
    return new EmbeddingsResponse(embeddings: []);
}
```

Claude Code CLI is a conversational interface and doesn't provide an embeddings endpoint.

### Usage Example

```php
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create invocation (or use defaults)
$invocation = new ClaudeCodeInvocation(
    claudePath: 'claude',
    configDir: '~/.claude',
    sessionId: null,
);

// Create provider
$provider = new ClaudeCodeProvider($invocation);

// Make a completion request
$request = new CompleteRequest(
    model: 'claude-sonnet-4-6',
    messages: [new UserMessage('Hello, world!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
```

### CLI Wrapper Pattern

The `ClaudeCodeInvocation` class implements a **CLI wrapper pattern** for integrating command-line tools:

```
ProviderInterface (contract)
        │
        ▼
ClaudeCodeProvider (translates requests to prompt format)
        │
        ▼
ClaudeCodeInvocation (spawns subprocess, handles I/O)
        │
        ▼
claude CLI (external executable in PATH)
```

**Why a separate class?**
1. **Separation of concerns** — Invocation logic (process spawning, I/O) is isolated from provider logic (prompt building, response parsing)
2. **Testability** — Mock `ClaudeCodeInvocation` in unit tests without spawning real processes
3. **Reusability** — The invocation wrapper could be used for other CLI tools

**Environment variable propagation:**
```php
[
    'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
    'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
    'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
]
```

These are passed to the subprocess so the Claude Code CLI can authenticate and connect to Anthropic's API.

### Process Lifecycle Comparison

| Provider | Transport | Protocol |
|----------|-----------|----------|
| OpenAIProvider | HTTP | OpenAI REST API |
| SglangProvider | HTTP | OpenAI-compatible REST API |
| ClaudeCodeProvider | Subprocess | CLI stdin/stdout |

The Claude Code provider differs fundamentally from HTTP-based providers:
- No HTTP client needed — uses `proc_open()` directly
- Process is spawned per-request (no persistent connection)
- Communication is line-by-line JSON rather than HTTP streaming

## Step 3.4: AWS Bedrock Provider

Step 3.4 implements the `BedrockProvider` — a `ProviderInterface` implementation that connects to AWS Bedrock using the AWS SDK for PHP. Unlike HTTP-based providers that use REST APIs directly, the Bedrock provider uses AWS's official SDK which handles authentication, region routing, and request signing.

### BedrockProvider Class

```php
final readonly class BedrockProvider implements ProviderInterface
{
    private const REGION_US = 'us-east-1';
    private const REGION_EU = 'eu-west-1';

    public function __construct(
        private BedrockClient $client,
        private string $region = self::REGION_US,
        private string $defaultModel = 'anthropic.claude-sonnet-4-6',
    ) {}

    public static function create(string $region = self::REGION_US, ?string $model = null): self
    {
        $client = new BedrockClient([
            'region' => $region,
            'version' => 'latest',
        ]);

        return new self($client, $region, $model ?? 'anthropic.claude-sonnet-4-6');
    }
}
```

The provider requires an AWS `BedrockClient` instance (from the `aws/aws-sdk-php` package) along with region and model configuration.

### Provider Interface Implementation

| Method | Implementation | Notes |
|--------|----------------|-------|
| `name()` | Returns `'bedrock'` | Provider identifier |
| `supportsStreaming()` | Returns `true` | Bedrock supports streaming |
| `supportsFunctionCalling()` | Returns `false` | Depends on model capability |
| `supportsVision()` | Returns `false` | Vision requires separate model |
| `supportsJsonSchema()` | Returns `false` | Bedrock uses native inference config |
| `contextWindow()` | Varies by model | 200k for Claude, 8k for Llama |
| `costPer1kTokens()` | Model-based pricing | Approximate AWS pricing |

### Region Configuration

```php
public function __construct(
    private BedrockClient $client,
    private string $region = self::REGION_US,  // or self::REGION_EU
    private string $defaultModel = 'anthropic.claude-sonnet-4-6',
) {}
```

AWS Bedrock is available in multiple regions. The provider defaults to `us-east-1` but supports `eu-west-1` and other AWS regions.

### Supported Models

```php
public function contextWindow(): int
{
    return match ($this->defaultModel) {
        'anthropic.claude-opus-4-6' => 200_000,
        'anthropic.claude-sonnet-4-6' => 200_000,
        'anthropic.claude-haiku-4-7' => 200_000,
        'meta.llama3-70b-instruct' => 8_192,
        'meta.llama3-8b-instruct' => 8_192,
        default => 8_192,
    };
}
```

**Note**: Context windows vary significantly between providers. Claude models offer 200k context while Llama models offer 8k.

### Pricing by Model

```php
public function costPer1kTokens(string $model, string $direction): float
{
    // Pricing varies by model and region - these are approximations
    return match ($model) {
        'anthropic.claude-opus-4-6' => $direction === 'input' ? 0.015 : 0.075,
        'anthropic.claude-sonnet-4-6' => $direction === 'input' ? 0.003 : 0.015,
        'anthropic.claude-haiku-4-7' => $direction === 'input' ? 0.00025 : 0.00125,
        'meta.llama3-70b-instruct' => $direction === 'input' ? 0.00065 : 0.00275,
        'meta.llama3-8b-instruct' => $direction === 'input' ? 0.00022 : 0.00088,
        default => 0.01,
    };
}
```

**Important**: AWS Bedrock pricing varies by region and can change. These values are approximations for cost estimation.

### complete() Method

The `complete()` method handles synchronous chat completions:

```php
public function complete(CompleteRequest $request): CompleteResponse
{
    $params = [
        'modelId' => $request->model,
        'messages' => $this->formatMessages($request->messages),
    ];

    if ($request->systemPrompt !== null) {
        $params['system'] = [['text' => $request->systemPrompt]];
    }

    if ($request->maxTokens !== null) {
        $params['inferenceConfig'] = [
            'maxTokens' => $request->maxTokens,
            'temperature' => $request->temperature ?? 0.7,
        ];
    }

    try {
        $result = $this->client->invokeModel($params);
        $data = $result->toArray();

        return $this->parseResponse($data);
    } catch (AwsException $e) {
        throw new \RuntimeException('Bedrock completion failed: ' . $e->getMessage(), 0, $e);
    }
}
```

**Key behaviors:**
- Uses `modelId` instead of `model` (AWS parameter naming)
- System prompts are formatted as `system: [[{text: string}]]`
- `inferenceConfig` wraps maxTokens and temperature
- AWS exceptions are wrapped in `RuntimeException` for provider-agnostic error handling

### completeStream() Method

The `completeStream()` method handles streaming responses using a generator:

```php
public function completeStream(CompleteRequest $request): \Generator
{
    $params = [
        'modelId' => $request->model,
        'messages' => $this->formatMessages($request->messages),
        'guardrailConfig' => [],
        'inferenceConfig' => [
            'maxTokens' => $request->maxTokens ?? 4096,
            'temperature' => $request->temperature ?? 0.7,
        ],
    ];

    if ($request->systemPrompt !== null) {
        $params['system'] = [['text' => $request->systemPrompt]];
    }

    try {
        $result = $this->client->invokeModelWithResponseStream($params);
        $stream = $result->get('body');

        foreach ($stream as $chunk) {
            if (isset($chunk['chunk']['bytes'])) {
                $data = json_decode($chunk['chunk']['bytes'], true);
                if ($data !== null) {
                    yield $this->parseChunk($data);
                }
            }
        }
    } catch (AwsException $e) {
        throw new \RuntimeException('Bedrock streaming failed: ' . $e->getMessage(), 0, $e);
    }
}
```

**Streaming design notes:**
- Uses `invokeModelWithResponseStream()` for streaming
- The stream body contains chunks with `chunk.bytes` containing JSON
- Each chunk's JSON is decoded to extract completion deltas
- `tokensUsed` and `costUsd` are always 0 for streaming chunks (usage data only available at stream completion)

### Message Formatting

The `formatMessages()` method translates CandyCrush `Message` objects to Bedrock format:

```php
private function formatMessages(array $messages): array
{
    return array_map(function (Message $msg) {
        $role = match (true) {
            $msg instanceof UserMessage => 'user',
            $msg instanceof AssistantMessage => 'assistant',
            $msg instanceof SystemMessage => 'user', // System wrapped as user
            $msg instanceof ToolResultMessage => 'user',
            default => 'user',
        };

        return [
            'role' => $role,
            'content' => [['text' => $msg->content()]],
        ];
    }, $messages);
}
```

**Key translation rules:**
- Content is always an array of `[{text: string}]` objects
- System messages are wrapped as user messages (Bedrock convention)
- Each message has `role` and `content` fields

**Differences from HTTP-based providers:**
- Content is an array `[{text: string}]` rather than a simple string
- System messages don't use a separate `role: 'system'` — they're wrapped as user messages

### Response Parsing

The `parseResponse()` method extracts data from the Bedrock response:

```php
private function parseResponse(array $data): CompleteResponse
{
    $output = $data['output']['message'] ?? [];
    $content = $output['content'] ?? [];

    return new CompleteResponse(
        content: $content[0]['text'] ?? '',
        reasoning: null,
        toolCalls: null,
        tokensUsed: ($data['usage']['inputTokens'] ?? 0) + ($data['usage']['outputTokens'] ?? 0),
        costUsd: 0.0, // Calculate from usage if needed
    );
}
```

**Response structure differences:**
- Output is nested under `output.message` (AWS structure)
- Content is an array `content[0].text` (Bedrock format)
- Usage uses `inputTokens` and `outputTokens` (not `total_tokens`)

### Streaming Chunk Parsing

The `parseChunk()` method handles individual streaming chunks:

```php
private function parseChunk(array $data): CompleteResponse
{
    if (isset($data['completion'])) {
        return new CompleteResponse(
            content: $data['completion'],
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    return new CompleteResponse(
        content: '',
        reasoning: null,
        toolCalls: null,
        tokensUsed: 0,
        costUsd: 0.0,
    );
}
```

**Streaming chunk format:**
- Deltas arrive in `data['completion']` field
- Empty chunks may arrive without content
- `tokensUsed` and `costUsd` are always 0 for streaming

### embeddings() Method

```php
public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
{
    // Use Titan or Cohere for embeddings via Bedrock
    return new EmbeddingsResponse(embeddings: []);
}
```

**Design note**: Embeddings via Bedrock require separate model configuration (Titan or Cohere). This stub returns empty results — actual implementation would need model-specific client calls.

### AWS SDK Integration

The provider uses the official AWS SDK for PHP:

```php
use Aws\Bedrock\BedrockClient;
use Aws\Exception\AwsException;

$client = new BedrockClient([
    'region' => 'us-east-1',
    'version' => 'latest',
]);
```

**Benefits of AWS SDK:**
- Automatic request signing (AWS Signature Version 4)
- Credential resolution via IAM roles, environment variables, or config files
- Retry logic and timeout handling
- Region-based endpoint routing

**Authentication**: The SDK automatically finds AWS credentials via:
1. Environment variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`)
2. AWS credentials file (`~/.aws/credentials`)
3. IAM role (when running on EC2/ECS/Lambda)

### Usage Example

```php
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

// Create provider via factory
$provider = BedrockProvider::create('us-east-1', 'anthropic.claude-sonnet-4-6');

// Make a completion request
$request = new CompleteRequest(
    model: 'anthropic.claude-sonnet-4-6',
    messages: [new UserMessage('Hello, world!')],
    temperature: 0.7,
);

$response = $provider->complete($request);
echo $response->content;  // AI response
echo $response->tokensUsed;  // Token count (combined input + output)
```

### Provider Transport Comparison

| Provider | Transport | Protocol |
|----------|-----------|----------|
| OpenAIProvider | HTTP | OpenAI REST API |
| SglangProvider | HTTP | OpenAI-compatible REST API |
| ClaudeCodeProvider | Subprocess | CLI stdin/stdout |
| BedrockProvider | AWS SDK | AWS Bedrock API |

**Bedrock differs from HTTP-based providers:**
- Uses official AWS SDK rather than raw HTTP
- AWS handles authentication and request signing
- Different request/response schema (AWS-native)
- Region-based routing instead of base URL

### How Bedrock Differs from HTTP-Based Providers

1. **Authentication**: AWS SDK handles SigV4 signing automatically; HTTP providers need manual Bearer token or API key headers.

2. **Request Format**:
   - HTTP providers: `{"model": "...", "messages": [...]}`
   - Bedrock: `{"modelId": "...", "messages": [...]}`

3. **Response Format**:
   - HTTP providers: `{"choices": [{"message": {"content": "..."}}]}`
   - Bedrock: `{"output": {"message": {"content": [{"text": "..."}]}}}`

4. **System Prompt**:
   - HTTP providers: `{"role": "system", "content": "..."}`
   - Bedrock: `{"system": [{"text": "..."}]}`

5. **Streaming**:
   - HTTP providers: SSE with `data: ` prefix
   - Bedrock: Binary frame with `chunk.bytes` containing JSON

## Step 2.5: Keyboard Handling System

Step 2.5 implements a centralized keyboard handling system that routes keypresses to appropriate handlers and dispatches commands through the TEA model. The system supports navigation, menu shortcuts, and Ctrl key combinations.

### KeyboardHandler Class

The `KeyboardHandler` is the central dispatcher for all keyboard input:

```php
final class KeyboardHandler
{
    /**
     * Process a keypress and return updated App and optional command.
     *
     * @return array{0: App, 1: ?KeyCmd} [newApp, command]
     */
    public function handle(string $key, App $app): array
}
```

The handler routes input through a priority chain:

1. **Tab** — Cycle pane focus
2. **Arrow/Vim keys** — Navigation within panes
3. **Menu shortcuts** — When a menu is active
4. **Escape** — Close menu and return to Chat
5. **Ctrl+key combinations** — Application commands

### KeyCmd Interface

All keyboard commands implement the `KeyCmd` marker interface:

```php
interface KeyCmd
{
}
```

This empty interface serves as a type marker for the TEA command system. Commands are processed by the runtime after the update cycle completes.

### Available Commands

| Command | Ctrl Key | Purpose |
|---------|----------|---------|
| `NewSessionCmd` | `Ctrl+N` | Start a new chat session |
| `CancelCmd` | `Ctrl+C` | Cancel current operation |
| `GroupInputCmd` | `Ctrl+G` | Enable multi-line input mode |
| `CommandPaletteCmd` | `Ctrl+K` | Open command palette |
| `SourceSkillCmd` | `Ctrl+S` | Apply/source a skill |
| `ProviderSelectCmd` | `Ctrl+P` | Open provider selection |
| `SelectPaneMsg` | `Ctrl+A` | Switch to Agents pane |
| `SelectPaneMsg` | `Ctrl+,` | Switch to Settings pane |

### Complete Key Mapping

| Key(s) | Action | Result |
|--------|--------|--------|
| `Tab` | Cycle pane | `App::withPane(Pane::next())` |
| `↑` / `k` | Navigate up | (delegated to pane) |
| `↓` / `j` | Navigate down | (delegated to pane) |
| `←` / `h` | Navigate left | (delegated to pane) |
| `→` / `l` | Navigate right | (delegated to pane) |
| `Escape` | Close menu | `MenuBar::closeMenu()`, return to Chat |
| `Ctrl+N` | New session | `NewSessionCmd` |
| `Ctrl+C` | Cancel | `CancelCmd` |
| `Ctrl+G` | Group input | `GroupInputCmd` |
| `Ctrl+K` | Command palette | `CommandPaletteCmd` |
| `Ctrl+S` | Source skill | `SourceSkillCmd` |
| `Ctrl+A` | Agents pane | `SelectPaneMsg(Agents)` |
| `Ctrl+P` | Provider select | `ProviderSelectCmd` |
| `Ctrl+,` | Settings pane | `SelectPaneMsg(Settings)` |

### Vim Key Bindings

Navigation supports both arrow keys and vim motion keys:

```php
if (in_array($key, ['up', 'k', 'down', 'j', 'left', 'h', 'right', 'l'], true)) {
    return $this->handleNavigation($key, $app);
}
```

This follows the established convention in the project (see `candy-pty/src/Lang.php`) and accommodates power users familiar with modal editing.

### Ctrl+Key Handling

Ctrl combinations are extracted and dispatched via a match expression:

```php
if (str_starts_with($key, 'ctrl+')) {
    return $this->handleCtrl(substr($key, 5), $app);
}

private function handleCtrl(string $key, App $app): array
{
    return match ($key) {
        'n' => [$app, new NewSessionCmd()],
        'c' => [$app, new CancelCmd()],
        // ...
    };
}
```

### TEA Model Integration

The keyboard handler integrates with the TEA pattern as the input layer:

```
┌─────────────────────────────────────────────────────────────────────┐
│  Terminal Input (keypress)                                          │
└─────────────────────────────────┬───────────────────────────────────┘
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  KeyboardHandler::handle(key, app)                                   │
│  - Tab → pane cycling                                                │
│  - Arrows/Vim → navigation                                           │
│  - Ctrl+* → command dispatch                                         │
│  - Escape → menu close                                               │
└─────────────────────────────────┬───────────────────────────────────┘
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Returns [newApp, ?KeyCmd]                                           │
│  - newApp: potentially updated application state (e.g., pane change) │
│  - KeyCmd: command to execute (or null for pure state changes)      │
└─────────────────────────────────┬───────────────────────────────────┘
                                  ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Runtime processes KeyCmd                                             │
│  - NewSessionCmd → clears messages, resets session                   │
│  - CancelCmd → aborts in-progress operation                         │
│  - GroupInputCmd → enables multi-line input mode                     │
│  - etc.                                                              │
└─────────────────────────────────────────────────────────────────────┘
```

### Command Processing Pattern

Commands follow the marker interface pattern:

```php
final readonly class NewSessionCmd implements KeyCmd
{
}
```

Each command is a `final readonly` class implementing `KeyCmd`. The empty interface is intentional — commands carry no data (the action is the class type itself). This provides:

- **Type safety**: Only `KeyCmd` instances can be returned from `KeyboardHandler`
- **Extensibility**: Add new commands by creating new classes
- **Pattern matching**: `match` on command class for dispatch

### Menu Bar Shortcuts

When a menu is active, keypresses are first routed to `MenuBar::handleKey()`:

```php
$currentMenu = MenuBar::getActiveMenu();
if ($currentMenu > 0) {
    $result = MenuBar::handleKey($key, $currentMenu);
    if ($result[1] !== null) {
        return [$app, $result[1]];
    }
}
```

If the menu handler returns a command, it is propagated to the caller. If the menu handler returns `null` (unhandled key or navigation that didn't produce a menu selection), processing continues.

### Escape Handling

Escape closes any open menu and returns focus to the Chat pane:

```php
if ($key === 'escape') {
    MenuBar::closeMenu();
    return [$app->withPane(Pane::Chat), null];
}
```

This provides a consistent way to dismiss menus and return to the primary interaction mode.

## Step 2.4: Enhanced Menu System

Step 2.4 implements an interactive menu system with full keyboard navigation, integrated with the TEA model for state management.

### Menu Structure

The menu bar provides access to seven application areas:

| Menu | Items |
|------|-------|
| **File** | New Session, Open Session, Save Transcript, Export Chat, ---, Preferences, Quit |
| **Edit** | Copy, Paste, Select All, Clear History |
| **Session** | Continue, New Session, Session History, Attach Context |
| **Provider** | OpenAI, Anthropic, Claude Code, SGLANG, Bedrock, Vertex, ---, Custom... |
| **Skills** | Browse Skills, Enable Skill..., Manage Built-in Skills |
| **Agents** | Create Agent, Manage Agents, Active Agents |
| **Help** | Keyboard Shortcuts, Documentation, About |

The `---` separator denotes menu dividers for logical grouping.

### MenuBar Component

The `MenuBar` class provides both rendering and keyboard handling:

```php
final class MenuBar
{
    private const MENUS = [
        'File' => ['New Session', 'Open Session', ...],
        'Edit' => ['Copy', 'Paste', ...],
        // ...
    ];

    public static function render(App $a): string
    {
        // Renders menu names with active menu highlighted in cyan
        // Active menu: Color::hex('#00ffaa')
        // Inactive menu: Color::hex('#fde68a')
    }

    public static function handleKey(string $key, int $currentMenu): array
    {
        // Returns [newMenuIndex, ?MenuSelectedMsg]
    }
}
```

### Keyboard Handling Flow

The menu system responds to these keys:

| Key | Action | Result |
|-----|--------|--------|
| `←` / `h` | Cycle left | Move to previous menu |
| `→` / `l` | Cycle right | Move to next menu |
| `Enter` / `o` | Select | Dispatch `MenuSelectedMsg` |
| `Escape` / `q` | Close menu | Reset to no active menu |

```php
public static function handleKey(string $key, int $currentMenu): array
{
    return match ($key) {
        'left', 'h' => [self::cycleMenu($currentMenu, -1), null],
        'right', 'l' => [self::cycleMenu($currentMenu, 1), null],
        'enter', 'o' => self::selectMenuItem($currentMenu),
        'escape', 'q' => [self::closeMenu(), null],
        default => [$currentMenu, null],
    };
}
```

**Key bindings follow vim conventions** (`h`/`l` for left/right) alongside arrow keys, accommodating power users familiar with modal editing.

### Menu Cycling

Menu navigation wraps around — going left from the first menu lands on the last, and vice versa:

```php
private static function cycleMenu(int $currentMenu, int $direction): int
{
    $count = count(self::MENUS);
    $new = $currentMenu + $direction;

    if ($new < 1) {
        $new = $count;  // Wrap to last
    }
    if ($new > $count) {
        $new = 1;       // Wrap to first
    }

    return $new;
}
```

### MenuSelectedMsg Integration

Selecting a menu item produces a `MenuSelectedMsg` that flows into the TEA update cycle:

```php
final readonly class MenuSelectedMsg
{
    public function __construct(
        public string $menu,
        public string $item,
    ) {}
}
```

The `App::update()` method handles this message:

```php
public function update(Msg $msg): array
{
    return match (true) {
        // ...
        $msg instanceof MenuSelectedMsg => $this->handleMenuSelection($msg),
        // ...
    };
}
```

### TEA Model Integration

The menu system integrates with the TEA pattern as follows:

```
┌─────────────────────────────────────────────────────────────┐
│  User presses →/l                                           │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  MenuBar::handleKey('right', 1) → [2, null]                 │
│  Menu index updates, no message dispatched                  │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  Renderer re-renders with new active menu highlighted       │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  User presses Enter on "File > New Session"                 │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  MenuBar::handleKey('enter', 1) → [1, MenuSelectedMsg]       │
│  MenuSelectedMsg{message: 'File', item: 'New Session'}      │
└─────────────────────┬───────────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────────┐
│  App::update(MenuSelectedMsg) → handleMenuSelection()       │
│  Returns [newApp, ?Cmd] for side effects                    │
└─────────────────────────────────────────────────────────────┘
```

### Active Menu State

The active menu index is stored as a static property:

```php
private static int $activeMenu = 0;
```

- `0` means no menu is active (menu bar is in "closed" state)
- `1` through `7` correspond to the seven menus

When a menu is active, its name is rendered in cyan (`#00ffaa`); inactive menus render in amber (`#fde68a`). The `closeMenu()` method resets the index to `0`, dismissing any open menu.

### Design Rationale

**Why static state for active menu?**
Unlike `App` (which holds application state), the active menu is a transient UI state that doesn't affect business logic. Using a static property avoids the overhead of threading menu state through the TEA model for what is essentially a presentational concern.

**Why return array from handleKey?**
The `[newMenuIndex, ?MenuSelectedMsg]` return type cleanly separates two concerns:
- Menu index update (UI state change)
- Message dispatch (business logic trigger)

This matches the TEA pattern where `update()` returns `[newModel, command]`.

## TUI Renderer Architecture

Step 2.2 implements a **stateless, composable TUI rendering framework** that assembles multiple panes into a full terminal interface. The renderer follows a pure function pattern — given the same `App` state, it always produces the same output bytes.

### Renderer Class

The `Renderer` class is the composition layer that orchestrates pane rendering:

```php
final class Renderer
{
    public static function render(App $a): string
    {
        $size = self::getTerminalSize();
        $cols = $size['cols'];
        $rows = $size['rows'];

        // Build panes based on focused pane
        $menuBar = MenuBar::render($a);
        $chatPane = ChatPane::render($a, $cols, $rows);
        $inputPane = InputPane::render($a, $cols);
        $statusBar = self::statusBar($a);

        // Side panes
        $leftPane = self::leftSidebar($a, $cols, $rows);
        $rightPane = self::rightSidebar($a, $cols, $rows);

        // Compose: top bar + left + chat + right + input + status
        $top = $menuBar;
        $middle = Layout::joinHorizontal(Position::TOP, $leftPane, $chatPane, $rightPane);
        $bottom = $inputPane . "\n" . $statusBar;

        return $top . "\n" . $middle . "\n" . $bottom;
    }
}
```

### Layout Composition

The renderer uses `sugar-sprinkles` layout primitives to compose panes:

- **`Layout::joinHorizontal(Position::TOP, ...$panes)`** — Arranges panes horizontally, aligning them to the top
- **`Position::TOP`** — Alignment constant from `SugarCraft\Sprinkles\Position`

### Multi-Pane Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│  Menu: [Chat] [Files] [Tools] [Skills] [Agents]   Currently: Chat    │
├────────────────────┬─────────────────────────────────────────────────┤
│                    │                                                 │
│   Files / Context  │            Chat / Messages                      │
│   (file tree,      │   (scrollable message history with             │
│    selected files) │    assistant/user/tool messages)               │
│                    │                                                 │
├────────────────────┼─────────────────────────────────────────────────┤
│   Tools / History  │            Input                               │
│   (tool calls,      │   (multi-line input with markdown preview)    │
│    recent actions) │                                                 │
├────────────────────┴─────────────────────────────────────────────────┤
│  openai | claude-sonnet-4-6 | [Tab] Switch Pane                      │
└──────────────────────────────────────────────────────────────────────┘
```

### Pane Components

Each pane is a standalone static renderer:

| Component | Purpose |
|-----------|---------|
| `MenuBar` | Top menu showing pane tabs and current selection |
| `ChatPane` | Main conversation area with message history |
| `InputPane` | User input area with border styling |
| `FilesPane` | Left sidebar showing loaded context files |
| `ToolsPane` | Left sidebar showing available tools |
| `SkillsPane` | Right sidebar showing enabled skills |
| `AgentsPane` | Right sidebar showing active agents |

### Sidebar Delegation

The renderer delegates sidebar rendering based on the focused pane:

```php
private static function leftSidebar(App $a, int $cols, int $rows): string
{
    $width = (int) floor($cols / 4);
    $width = max(20, $width);

    if ($a->pane === Pane::Files) {
        return FilesPane::render($a, $width, $rows);
    }

    if ($a->pane === Pane::Tools) {
        return ToolsPane::render($a, $width, $rows);
    }

    return FilesPane::render($a, $width, $rows);
}
```

This pattern allows left/right sidebars to show contextually relevant information without needing separate layout regions — the same physical area adapts its content based on focus.

### Terminal Size Detection

The renderer caches terminal dimensions to avoid repeated syscalls:

```php
private static function getTerminalSize(): array
{
    if (self::$terminalSize !== null) {
        return self::$terminalSize;
    }

    try {
        $size = (new Tty(STDOUT))->size();
        if ($size['cols'] > 0 && $size['rows'] > 0) {
            self::$terminalSize = ['rows' => $size['rows'], 'cols' => $size['cols']];
            return self::$terminalSize;
        }
    } catch (\Throwable) {}

    self::$terminalSize = ['rows' => 60, 'cols' => 200];
    return self::$terminalSize;
}
```

**Fallback**: Returns 200x60 when size detection fails (redirected output, non-TTY environment).

### Status Bar

The status bar displays provider context and navigation hints:

```php
private static function statusBar(App $a): string
{
    $provider = Style::new()->foreground(Color::hex('#9ece6a'))->render($a->provider->name());
    $model = Style::new()->foreground(Color::hex('#e0af68'))->render($a->model);

    $status = $a->error
        ? Style::new()->foreground(Color::hex('#f7768e'))->bold()->render('error: ' . $a->error)
        : ($a->status
            ? Style::new()->foreground(Color::hex('#9ece6a'))->render($a->status)
            : '');

    return " $provider | $model | [Tab] Switch Pane | $status";
}
```

The status bar shows:
- Provider name (green)
- Model name (amber)
- Tab navigation hint
- Error or status message (green on success, red+bold on error)

### Pane Interface Contract

All pane components follow the same static render contract:

```php
final class SomePane
{
    public static function render(App $a, int $width, int $rows): string
    {
        // Build lines array
        // Pad to fill height
        // Return composed string
    }
}
```

Parameters vary by pane role:
- **Main panes** (`ChatPane`, `InputPane`): Take `$cols, $rows` for dimension calculations
- **Sidebar panes** (`FilesPane`, `ToolsPane`, `SkillsPane`, `AgentsPane`): Take `$width, $rows` for constrained rendering
- **MenuBar**: Takes only `App` — dimensions handled internally

### Static Stateful Rendering

While the renderer is a **static** class (no instance state), it maintains **cached terminal size** as a static property:

```php
private static ?array $terminalSize = null;
```

This caching is intentional — it avoids repeated `Tty::size()` calls during a single render cycle while maintaining the pure function contract within a session. The cache is resettable via `resetSizeCache()` for testing.

### Integration with TEA Model

The renderer receives the complete `App` state as input:

```php
public static function render(App $a): string
```

This means:
1. **No internal state** — rendering is purely derivable from `App`
2. **Testable** — pass a known `App` and assert on the output string
3. **Composable** — each pane accesses only the `App` fields it needs

## TUI Pane System

CandyCrush implements a multi-pane terminal UI for navigating between different functional areas. The `Pane` enum defines all available panes and their navigation order.

### Pane Enum

```php
enum Pane: string
{
    case Chat     = 'chat';
    case Input    = 'input';
    case Skills   = 'skills';
    case Agents   = 'agents';
    case Files    = 'files';
    case Tools    = 'tools';
    case Settings = 'settings';
    case Help     = 'help';
}
```

### Pane Navigation

Each pane knows its successor in the navigation cycle via `next()`:

```
Chat → Input → Files → Tools → Skills → Agents → Settings → Help → (back to Chat)
```

The `label()` method returns a human-readable name for display:

```php
echo Pane::Chat->label();   // 'Chat'
echo Pane::Skills->label(); // 'Skills'
```

### Integration with TEA Model

The `App` state holds the currently focused pane:

```php
final readonly class App
{
    public function __construct(
        // ...
        public readonly Pane $pane,
        // ...
    ) {}
}
```

Pane changes dispatch a `SelectPaneMsg` message:

```php
final readonly class SelectPaneMsg implements Msg
{
    public function __construct(public Pane $pane) {}
}
```

The `update()` method handles pane transitions:

```php
public function update(Msg $msg): array
{
    return match (true) {
        // ...
        $msg instanceof SelectPaneMsg => [$this->withPane($msg->pane)->withError(null), null],
        // ...
    };
}
```

### Multi-Pane Layout

The TUI is organized into regions:

```
┌──────────────────────────────────────────────────────────────────────┐
│  Menu: [File] [Edit] [Session] [Provider] [Skills] [Agents] [Help] │
├────────────────────┬─────────────────────────────────────────────────┤
│                    │                                                 │
│   Files / Context  │            Chat / Messages                      │
│   (file tree,      │   (scrollable message history with             │
│    selected files) │    assistant/user/tool messages)               │
│                    │                                                 │
├────────────────────┼─────────────────────────────────────────────────┤
│   Tools / History  │            Input                               │
│   (tool calls,      │   (multi-line input with markdown preview)    │
│    recent actions) │                                                 │
├────────────────────┴─────────────────────────────────────────────────┤
│  Provider: Claude Code │ Model: claude-sonnet-4-6 │ Tokens: 1,234   │
│  Skills: php-best-practices │ [Tab] Switch Pane                     │
└──────────────────────────────────────────────────────────────────────┘
```

**Tab** cycles focus through panes in order. The status bar shows current context and hints for navigation.

## Step 2.3: TUI Component Classes

Step 2.3 implemented full `Tui\Components` classes for all seven pane types. Each component is a `final static` renderer with a consistent interface and unified pane focus highlighting.

### Component Overview

| Component | File | Purpose |
|-----------|------|---------|
| `MenuBar` | `MenuBar.php` | Top menu bar with application menus |
| `ChatPane` | `ChatPane.php` | Main conversation area with message history |
| `InputPane` | `InputPane.php` | User input area at bottom |
| `FilesPane` | `FilesPane.php` | Left sidebar showing context files |
| `ToolsPane` | `ToolsPane.php` | Left sidebar showing tool history |
| `SkillsPane` | `SkillsPane.php` | Right sidebar showing enabled skills |
| `AgentsPane` | `AgentsPane.php` | Right sidebar showing active agents |

### Component Interface Contract

All components follow the same static render signature:

```php
final class SomePane
{
    public static function render(App $a, int ...$dims): string
    {
        // Build styled content
        // Apply conditional border color based on focus
        // Return rendered string
    }
}
```

Parameter conventions:
- **Main panes** (`ChatPane`, `InputPane`): Take `$cols` and optionally `$rows` for dimension calculations
- **Sidebar panes** (`FilesPane`, `ToolsPane`, `SkillsPane`, `AgentsPane`): Take `$width` and `$rows` for constrained rendering
- **MenuBar**: Takes only `App` — dimensions derived internally

### Pane Focus Highlighting System

Each component implements a consistent visual feedback pattern for showing which pane has keyboard focus. The border color changes based on whether the pane matches the current focused pane in `App`:

```php
$st = $a->pane === \SugarCraft\Crush\Tui\Pane::SomePane
    ? $st->borderForeground(Color::hex('#00ffaa'))  // Cyan — focused
    : $st->borderForeground(Color::hex('#ff66aa')); // Pink — unfocused
```

**Color semantics**:
- `#00ffaa` (cyan-green): Active pane with keyboard focus
- `#ff66aa` (pink): Inactive pane

This two-color system provides immediate visual feedback about which pane will respond to keyboard input, following the same pattern across all seven components.

### ChatPane

The main conversation area renders message history with role-based color coding:

```php
private static function formatMessage(Message $msg): string
{
    $role = Style::new()->bold()->foreground(Color::hex('#fde68a'))->render($msg->role() . ':');
    $content = Style::new()->foreground(Color::hex('#c5b6dd'))->render($msg->content());
    return "$role $content";
}
```

**Content behavior**:
- Empty state: Shows "Welcome to CandyCrush! Start typing to chat..."
- Message list: Renders each message with yellow role prefix and lavender content
- Width: Dynamically calculated as `max(40, $cols - 80)` to fill remaining space after sidebars

### InputPane

The input area provides a placeholder prompt and adapts width to terminal size:

```php
$placeholder = Style::new()->foreground(Color::hex('#7d6e98'))
    ->render('Type your message... (Enter to send, Ctrl+G for group)');

// Width adapts to terminal, minimum 20 columns
$width = max(20, $cols - 6);
```

### Sidebar Panes (FilesPane, ToolsPane, SkillsPane, AgentsPane)

Sidebar panes share a common structure:
1. Fetch data from `App` state (`$a->contextFiles`, `$a->enabledSkills`, etc.)
2. Display empty state message when data is absent
3. Render list items with consistent styling when data exists
4. Apply focus-highlighted border

**FilesPane** uses emoji file icons:
```php
$lines[] = Style::new()
    ->foreground(Color::hex('#c5b6dd'))
    ->render('📄 ' . basename($file));
```

**SkillsPane** uses bullet markers:
```php
$lines[] = Style::new()
    ->foreground(Color::hex('#c5b6dd'))
    ->render('• ' . $skill);
```

### MenuBar

The menu bar renders a static list of application menus without interactivity (Tab navigation handles pane switching):

```php
$menus = [
    'File' => 'New,Open,Save,Export,Quit',
    'Edit' => 'Copy,Paste,Clear',
    'Session' => 'Continue,New,History',
    'Provider' => 'OpenAI,Claude Code,SGLANG,Bedrock',
    'Skills' => 'Browse,Enable',
    'Agents' => 'Create,Manage',
    'Help' => 'Shortcuts,Docs,About',
];
```

Menu items are rendered in amber (`#fde68a`) bold text, separated by three spaces.

### Common Styling Pattern

All panes use `sugar-sprinkles` primitives for consistent border and padding behavior:

```php
$st = Style::new()
    ->border(Border::rounded()->withTitle(' pane-name '))
    ->padding(0, 1)
    ->width($width);

$st = $a->pane === Pane::X
    ? $st->borderForeground(Color::hex('#00ffaa'))
    : $st->borderForeground(Color::hex('#ff66aa'));

return $st->render($body);
```

The `Border::rounded()->withTitle(' title ')` pattern creates a rounded border with centered title, giving each pane a distinct visual identity.

## AppBuilder Fluent Builder

While `App` uses immutable `with*()` methods for state transitions during the TEA
update cycle, **AppBuilder** provides a fluent interface for constructing `App` instances
from scratch. The two patterns serve different purposes:

| Aspect | `App::with*()` | `AppBuilder` |
|--------|----------------|--------------|
| Purpose | Modify existing state | Construct new instances |
| Context | TEA update cycle | Initial object creation |
| Required Fields | N/A (existing state) | Enforced at `build()` |
| Defaults | N/A | Sensible defaults provided |

### Why Both Patterns?

The TEA pattern requires immutable state transitions — each `update()` call produces
a new `App` instance. The `with*()` methods are perfect for this because they operate
on an already-valid `App` instance.

However, constructing an initial `App` requires gathering all required fields (especially
the `provider`) and optional configurations. AppBuilder provides a cleaner API for this
bootstrapping phase:

```php
// Manual construction — verbose with many arguments
$app = new App(
    provider: $provider,
    model: 'claude-sonnet-4-6',
    messages: [],
    tools: [],
    pane: Pane::Chat,
    error: null,
    status: null,
    sessionId: null,
    contextFiles: [],
    enabledSkills: [],
    activeHooks: [],
);

// AppBuilder — readable, progressive configuration
$app = (new AppBuilder())
    ->withProvider($provider)
    ->withModel('claude-sonnet-4-6')
    ->withPane(Pane::Chat)
    ->withSessionId('abc123')
    ->build();
```

### Fluent Interface

AppBuilder follows the **fluent builder pattern** — each `with*()` method returns
`self` (the same builder instance) after modifying internal state:

```php
public function withModel(string $model): self
{
    $clone = clone $this;
    $clone->model = $model;
    return $clone;
}
```

This enables method chaining:

```php
$app = (new AppBuilder())
    ->withProvider($openAIProvider)
    ->withModel('gpt-4')
    ->withTools([new Read(), new Bash()])
    ->withContextFiles(['/path/to/context.md'])
    ->build();
```

### Validation at Build Time

The `build()` method enforces required fields:

```php
public function build(): App
{
    if ($this->provider === null) {
        throw new \LogicException('provider is required');
    }

    return new App(
        provider: $this->provider,
        // ... all other fields
    );
}
```

This defers validation until the consumer is done configuring the builder, providing
a better user experience than validating on each `with*()` call.

### Default Values

AppBuilder provides sensible defaults that reduce boilerplate:

- `model`: `'claude-sonnet-4-6'`
- `messages`: `[]`
- `tools`: `[]`
- `pane`: `Pane::Chat`
- All nullable fields default to `null`

### Key Insight: Cloning for Immutability

Like `App::mutate()`, AppBuilder uses `clone $this` to ensure each `with*()` call
returns a new builder instance. The original builder remains unchanged:

```php
$builder = new AppBuilder();
$builder2 = $builder->withModel('gpt-4');

echo $builder->model;  // 'claude-sonnet-4-6' — original unchanged
echo $builder2->model; // 'gpt-4'
```

This immutability matters because builders may be shared (e.g., passed to multiple
threads or stored for later use).

## App State Class (TEA Pattern)

CandyCrush implements **The Elm Architecture (TEA)** — a unidirectional data flow pattern
originating from the Elm language. The architecture consists of three core concepts:

1. **Model** — The single source of truth for application state
2. **Update** — A function that transforms the model based on messages
3. **Msg (Messages)** — Immutable descriptions of events that trigger state changes

### The App State Model

The `App` class holds all application state as `readonly` properties:

```php
final class App
{
    public function __construct(
        public readonly ProviderInterface $provider,
        public readonly string $model,
        public readonly array $messages,
        public readonly array $tools,
        public readonly Pane $pane,
        public readonly ?string $error,
        public readonly ?string $status,
        public readonly ?string $sessionId,
        public readonly array $contextFiles,
        public readonly array $enabledSkills,
        public readonly array $activeHooks,
    ) {}
}
```

### Immutable Updates via with*() Builders

State changes return a **new** `App` instance rather than mutating existing state.
This approach provides:

- **Predictability**: No hidden state changes — every update is explicit
- **Debuggability**: State history is preserved; trace any state to its origin
- **Testability**: Each with*() method is independently testable

```php
// Create initial state
$app = App::new($provider, 'gpt-4');

// with*() methods return NEW instances — original unchanged
$app2 = $app->withSessionId('abc123');
$app3 = $app2->withStatus('Processing...');

echo $app->sessionId;  // null — original unchanged
echo $app2->sessionId; // 'abc123'
echo $app3->status;     // 'Processing...'
```

### The update() Method

The `update(Msg $msg): array{self, ?Cmd}` method is the core of the TEA pattern.
It receives a message, transforms the model, and returns a command for side-effects:

```php
public function update(Msg $msg): array
{
    return match (true) {
        $msg instanceof UserInputMsg => $this->handleUserInput($msg),
        $msg instanceof SelectPaneMsg => [$this->withPane($msg->pane)->withError(null), null],
        $msg instanceof ToolResultMsg => $this->handleToolResult($msg),
        $msg instanceof ErrorMsg => [$this->withError($msg->message), null],
        $msg instanceof StatusMsg => [$this->withStatus($msg->message), null],
        default => [$this, null],
    };
}
```

**Returns**: A tuple of `[newApp, command]` where:
- `newApp` is the updated state (or same instance if no changes)
- `command` is a `Cmd` to execute (or `null` for pure state-only updates)

### Message Types (Msg)

Messages are internal immutable DTOs describing user actions or system events:

```php
interface Msg {}  // marker interface

final readonly class UserInputMsg implements Msg
{
    public function __construct(public string $content) {}
}

final readonly class SelectPaneMsg implements Msg
{
    public function __construct(public Pane $pane) {}
}

final readonly class ToolResultMsg implements Msg
{
    public function __construct(
        public string $toolCallId,
        public string $content,
        public bool $isError = false,
    ) {}
}

final readonly class ErrorMsg implements Msg
{
    public function __construct(public string $message) {}
}

final readonly class StatusMsg implements Msg
{
    public function __construct(public string $message) {}
}
```

### Command Types (Cmd)

Commands represent **side-effects** that the runtime must execute:

```php
interface Cmd {}  // marker interface

final readonly class RunCompletionCmd implements Cmd
{
    public function __construct(public Message $userMessage) {}
}

final readonly class CallToolCmd implements Cmd
{
    public function __construct(public string $toolName, public array $args) {}
}
```

The runtime loop processes commands after each update, executing them and
dispatching the result back as a new message.

### Flow Summary

```
┌─────────────────────────────────────────────────────────┐
│  User Input → UserInputMsg                              │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────┐
│  App::update(UserInputMsg) → [newApp, RunCompletionCmd] │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────┐
│  Runtime executes RunCompletionCmd                       │
│  → AI Provider → AI Response                            │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────┐
│  Response → AssistantMessage → ToolResultMsg            │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─────────────────────────────────────────────────────────┐
│  App::update(ToolResultMsg) → [newApp, ?Cmd]             │
└─────────────────────────────────────────────────────────┘
```

This unidirectional flow ensures that state changes are always traceable
and the application remains predictable even under concurrent operations.

## Provider Interface

The `ProviderInterface` defines the contract for all AI provider implementations.
Each provider translates CandyCrush requests into provider-specific API calls.

### Interface Contract

```php
interface ProviderInterface
{
    public function name(): string;
    public function supportsStreaming(): bool;
    public function supportsFunctionCalling(): bool;
    public function supportsVision(): bool;
    public function supportsJsonSchema(): bool;
    public function contextWindow(): int;
    public function costPer1kTokens(string $model, 'input'|'output'): float;
    public function complete(CompleteRequest $request): CompleteResponse;
    public function completeStream(CompleteRequest $request): \Generator;
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse;
}
```

### Request/Response DTOs

```php
// Immutable request for chat completions
final readonly class CompleteRequest
{
    public function __construct(
        public string $model,
        public array $messages,
        public ?array $tools = null,
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?string $jsonSchema = null,
    ) {}
}

// Immutable response from chat completions
final readonly class CompleteResponse
{
    public function __construct(
        public string $content,
        public ?string $reasoning = null,
        public ?array $toolCalls = null,
        public int $tokensUsed = 0,
        public float $costUsd = 0.0,
    ) {}
}

// Immutable request for embeddings
final readonly class EmbeddingsRequest
{
    public function __construct(
        public string $model,
        public array $input,
    ) {}
}

// Immutable response from embeddings
final readonly class EmbeddingsResponse
{
    public function __construct(
        public array $embeddings,
    ) {}
}
```

## Message Classes

All messages implement the `Message` interface:

```php
interface Message
{
    public function role(): string;
    public function content(): string;
    public function toArray(): array;
}
```

### Available Implementations

| Class | Role | Purpose |
|-------|------|---------|
| `UserMessage` | `user` | User input messages |
| `AssistantMessage` | `assistant` | AI responses with optional tool calls |
| `SystemMessage` | `system` | System prompts |
| `ToolResultMessage` | `tool` | Results from tool executions |

### Usage Example

```php
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;

// Build a conversation
$messages = [
    new SystemMessage('You are a PHP expert.'),
    new UserMessage('How do I implement PSR-12?'),
    new AssistantMessage('PSR-12 requires...'),
    new ToolResultMessage(
        toolCallId: 'tool_123',
        content: 'Analysis complete.',
        isError: false,
    ),
];

// Serialize for API calls
$array = array_map(fn(Message $m) => $m->toArray(), $messages);
```

### Design Notes

- **Immutable**: All message classes are `final readonly` — properties set via constructor, no setters.
- **Serializable**: `toArray()` returns provider-compatible array structure for OpenAI/Anthropic APIs.
- **Extensible**: Add new message types by implementing `Message` interface.

## Tool Interface

The `Tool` interface defines the contract for all executable tools available to the AI.
Tools provide capabilities like file operations, command execution, and web fetching.

### Interface Contract

```php
interface Tool
{
    public function name(): string;
    public function description(): string;
    public function inputSchema(): array;
    public function execute(array $args): ToolResult;
}
```

### name()

Returns the tool identifier, used by the AI to select which tool to invoke.

### description()

Returns a human-readable description of the tool's purpose and behavior.
The AI uses this to determine when to recommend the tool.

### inputSchema()

Returns a JSON Schema structure describing the arguments the tool accepts.
Mirrors the tool schema format used by OpenAI's function calling API.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'file_path' => ['type' => 'string', 'description' => 'Path to file to read'],
    ],
    'required' => ['file_path'],
];
```

### execute(array $args): ToolResult

Executes the tool with the provided arguments and returns a `ToolResult`.

## ToolCall Value Object

Represents a request to invoke a tool, providing the tool name and arguments.

```php
final readonly class ToolCall
{
    public function __construct(
        private string $id,
        private string $name,
        private array $arguments,
    ) {}

    public function id(): string => $this->id;
    public function name(): string => $this->name;
    public function arguments(): array => $this->arguments;

    public static function fromArray(array $data): self;
    public function toArray(): array;
}
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | Unique identifier for this tool call |
| `name` | `string` | Name of the tool to invoke |
| `arguments` | `array` | Arguments to pass to the tool |

### Factory Methods

- `fromArray(array $data): self` — Creates a `ToolCall` from an array, typically deserialized from JSON
- `toArray(): array` — Serializes the `ToolCall` to an array structure

## ToolResult Value Object

Represents the result of a tool execution.

```php
final readonly class ToolResult
{
    public function __construct(
        private string $toolCallId,
        private string $content,
        private bool $isError = false,
        private ?int $durationMs = null,
    ) {}

    public function toolCallId(): string => $this->toolCallId;
    public function content(): string => $this->content;
    public function isError(): bool => $this->isError;
    public function durationMs(): ?int => $this->durationMs;

    public function toArray(): array;
}
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `toolCallId` | `string` | ID of the tool call that produced this result |
| `content` | `string` | The output content from tool execution |
| `isError` | `bool` | Whether the tool execution failed |
| `durationMs` | `?int` | Execution time in milliseconds (optional) |

## BuiltIn Tools

CandyCrush provides six built-in tools for common operations:

| Tool | Name | Description |
|------|------|-------------|
| `Read` | `Read` | Read contents of a file |
| `Bash` | `Bash` | Execute a bash command |
| `Edit` | `Edit` | Edit a file by replacing text |
| `Grep` | `Grep` | Search for a pattern in files |
| `Glob` | `Glob` | Find files matching a glob pattern |
| `WebFetch` | `WebFetch` | Fetch content from a URL |

### Read

Reads the complete contents of a file.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'file_path' => ['type' => 'string', 'description' => 'Path to file to read'],
    ],
    'required' => ['file_path'],
];
```

**Error Handling**: Uses `set_error_handler` to convert PHP errors (permission denied, file not found) into `ToolResult` with `isError: true`.

### Bash

Executes a bash command and returns the output.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'command' => ['type' => 'string', 'description' => 'The bash command to execute'],
    ],
    'required' => ['command'],
];
```

**Security**: Uses `escapeshellarg()` to prevent command injection. Commands are executed via `bash -c` to ensure shell syntax interpretation.

### Edit

Performs string replacement within a file.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'file_path' => ['type' => 'string', 'description' => 'Path to file to edit'],
        'old_string' => ['type' => 'string', 'description' => 'The text to replace'],
        'new_string' => ['type' => 'string', 'description' => 'The replacement text'],
    ],
    'required' => ['file_path', 'old_string', 'new_string'],
];
```

**Notes**: Uses `str_replace()` which replaces all occurrences. Returns an error if `old_string` is empty or the file does not exist.

### Grep

Searches for a regex pattern within files in a directory.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'pattern' => ['type' => 'string', 'description' => 'The regex pattern to search for'],
        'path' => ['type' => 'string', 'description' => 'Directory path to search in'],
        'include' => ['type' => 'string', 'description' => 'File pattern to match (e.g., *.php)'],
    ],
    'required' => ['pattern', 'path'],
];
```

**Security**: Uses `escapeshellarg()` when constructing the grep command.

### Glob

Finds files matching a glob pattern within a directory.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'pattern' => ['type' => 'string', 'description' => 'The glob pattern to match (e.g., **/*.php)'],
        'path' => ['type' => 'string', 'description' => 'Base directory path'],
    ],
    'required' => ['pattern', 'path'],
];
```

### WebFetch

Fetches HTML or text content from a URL.

```php
public function inputSchema(): array => [
    'type' => 'object',
    'properties' => [
        'url' => ['type' => 'string', 'description' => 'The URL to fetch'],
    ],
    'required' => ['url'],
];
```

**Validation**: Requires URLs to start with `http://` or `https://`. Uses a 30-second timeout via `stream_context_create()`.

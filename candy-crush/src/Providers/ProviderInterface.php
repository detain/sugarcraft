<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Tools\ToolCall;

interface ProviderInterface
{
    public function name(): string;

    public function supportsStreaming(): bool;

    public function supportsFunctionCalling(): bool;

    public function supportsVision(): bool;

    public function supportsJsonSchema(): bool;

    public function contextWindow(): int;

    /**
     * @param 'input'|'output' $direction
     */
    public function costPer1kTokens(string $model, string $direction): float;

    public function complete(CompleteRequest $request): CompleteResponse;

    public function completeStream(CompleteRequest $request): \Generator;

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse;
}

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

final readonly class EmbeddingsRequest
{
    public function __construct(
        public string $model,
        public array $input,
    ) {}
}

final readonly class EmbeddingsResponse
{
    public function __construct(
        public array $embeddings,
    ) {}
}

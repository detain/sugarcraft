<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

final readonly class CompleteRequest
{
    public function __construct(
        public string $model,
        public array $messages,
        public ?array $tools = null,
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public string|array|null $jsonSchema = null,
    ) {}
}

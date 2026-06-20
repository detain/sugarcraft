<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

final readonly class CompleteResponse
{
    public function __construct(
        public string $content,
        public ?string $reasoning = null,
        public ?array $toolCalls = null,
        public int $tokensUsed = 0,
        public float $costUsd = 0.0,
        public bool $isError = false,
        public ?string $errorMessage = null,
    ) {}
}

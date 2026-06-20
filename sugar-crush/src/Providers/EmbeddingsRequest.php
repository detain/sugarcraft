<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

final readonly class EmbeddingsRequest
{
    /**
     * @param string|array<string> $input A single string or a batch of strings to embed.
     */
    public function __construct(
        public string $model,
        public string|array $input,
    ) {}
}

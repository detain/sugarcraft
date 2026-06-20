<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

final readonly class EmbeddingsResponse
{
    public function __construct(
        public array $embeddings,
    ) {}
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

final readonly class EmbeddingsResponse
{
    public function __construct(
        public array $embeddings,
    ) {}
}

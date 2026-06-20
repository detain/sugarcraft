<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

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

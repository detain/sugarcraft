<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;

/**
 * A streaming provider whose attempts are played by `$script->stream()` —
 * chunks, then a failure — for {@see DroppedStreamContinueTest}. Not final:
 * the test extends it with {@see \SugarCraft\Crush\Providers\AcceptsAssistantPrefill}.
 */
class DroppedStreamProvider implements ProviderInterface
{
    /** @param object{requests: list<CompleteRequest>} $script */
    public function __construct(public readonly object $script)
    {
    }

    public function name(): string
    {
        return 'dropped-stream';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return true;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return 100_000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        throw new \LogicException('streaming only');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        return $this->script->stream($request);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse([]);
    }
}

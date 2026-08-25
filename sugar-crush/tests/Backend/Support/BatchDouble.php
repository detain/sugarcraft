<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend\Support;

use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;

/**
 * E497 — lifted out of `tests/Backend/ReasoningProgressTest.php`, where it sat
 * at top level in the SHARED `SugarCraft\Crush\Tests\Backend` namespace under
 * a name generic enough that the next lane to write one would collide with it
 * — a fatal at autoload time, in a file neither lane had touched. Nothing
 * collided; the hazard was that nothing had YET.
 *
 * A namespace of its own, rather than a longer name: renaming would have to be
 * done again by the next person who wants the obvious name, whereas a namespace
 * makes `ScaledClockLoop` and someone else's `ScaledClockLoop` different
 * classes by construction.
 */
/** A non-streaming provider that thinks and then answers in one call. */
final class BatchDouble implements ProviderInterface
{
    public function name(): string { return 'batch'; }
    public function supportsStreaming(): bool { return false; }
    public function supportsFunctionCalling(): bool { return false; }
    public function supportsVision(): bool { return false; }
    public function supportsJsonSchema(): bool { return false; }
    public function contextWindow(): int { return 1000; }
    public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: 'the answer', reasoning: 'thought it through');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield new CompleteResponse(content: 'the answer');
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse([]);
    }
}

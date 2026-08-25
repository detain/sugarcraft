<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend\Support;

use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * A streaming provider whose pre-answer chunks all carry `content: ''` — one
 * shape per family member, plus a `silent` shape that emits nothing at all and
 * is the known-positive control.

 *
 * ## Where this class came from
 *
 * E497 — lifted out of `tests/Backend/ReasoningProgressTest.php`, where it sat
 * at top level in the SHARED `SugarCraft\Crush\Tests\Backend` namespace under
 * a name generic enough that the next lane to write one would collide with it
 * — a fatal at autoload time, in a file neither lane had touched. Nothing
 * collided; the hazard was that nothing had YET.
 *
 * A namespace of its own, rather than a longer name: renaming would have to be
 * done again by the next person who wants the obvious name, whereas a namespace
 * makes `StreamingDouble` and someone else's `StreamingDouble` different classes by
 * construction.
 */
final class StreamingDouble implements ProviderInterface
{
    public function __construct(
        private int $chunks,
        private int $pauseMicros,
        private string $shape,
        private string $answer,
    ) {}

    public function name(): string { return 'double'; }
    public function supportsStreaming(): bool { return true; }
    public function supportsFunctionCalling(): bool { return false; }
    public function supportsVision(): bool { return false; }
    public function supportsJsonSchema(): bool { return false; }
    public function contextWindow(): int { return 1000; }
    public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: $this->answer);
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        if ($this->shape === 'mixed') {
            yield new CompleteResponse(content: $this->answer, reasoning: 'thought alongside ');

            return;
        }

        for ($i = 0; $i < $this->chunks; $i++) {
            if ($this->pauseMicros > 0) {
                // A guaranteed LOWER bound on real elapsed time, which is what
                // makes "the clock crossed the ceiling" unflakeable.
                usleep($this->pauseMicros);
            }
            if ($this->shape === 'silent') {
                continue;
            }

            yield match ($this->shape) {
                'reasoning' => new CompleteResponse(content: '', reasoning: 'think ' . $i . ' '),
                // E496. The chunk shape whose progress crosses the fork as a
                // `token` frame rather than a `reasoning` one: real assistant
                // text, arriving slowly. Every OTHER shape here announces
                // itself through EngineBackend's reasoning branch, so a
                // regression that re-armed the idle deadline only in THAT
                // branch would leave all of them green and kill a long, slowly
                // streamed answer.
                'content' => new CompleteResponse(content: 'word ' . $i . ' '),
                'usage' => new CompleteResponse(content: '', tokensUsed: 1),
                // NO payload of any kind - see the test that uses it. Not
                // `toolCalls: []`, which reads like a tool call and is not one.
                'blank' => new CompleteResponse(content: ''),
                // The REAL tool-call-only shape, as VertexProvider flushes a
                // buffered `input_json_delta` and as SglangProvider emits a
                // recovered call: empty content, one actual ToolCall on it.
                'toolcall' => new CompleteResponse(content: '', toolCalls: [
                    ToolCall::fromArray([
                        'id' => 'call_r56a',
                        'name' => 'NoSuchToolExistsHere',
                        'arguments' => ['probe' => $i],
                    ]),
                ]),
                default => throw new \LogicException('unknown shape ' . $this->shape),
            };
        }

        yield new CompleteResponse(content: $this->answer, tokensUsed: 7);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse([]);
    }
}

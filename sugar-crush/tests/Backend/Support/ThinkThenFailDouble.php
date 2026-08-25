<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend\Support;

use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;

/**
 * A streaming provider whose FIRST attempt thinks and then dies transiently,
 * and whose second answers.
 *
 * Two failure shapes because {@see Runtime::runStreaming()} has two gates on
 * `$emitted` and real providers use both: a thrown 503
 * ({@see \SugarCraft\Crush\Providers\SglangProvider},
 * {@see \SugarCraft\Crush\Providers\BedrockProvider}) and an `isError` chunk
 * on an otherwise-successful stream
 * ({@see \SugarCraft\Crush\Providers\VertexProvider},
 * {@see \SugarCraft\Crush\Providers\CustomProvider}).
 *
 * The reasoning chunk goes out BEFORE the failure in both, which is the whole
 * fixture: it is the only thing on the wire in the window where a thinking
 * model's connection drops.

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
 * makes `ThinkThenFailDouble` and someone else's different classes by
 * construction.
 */
final class ThinkThenFailDouble implements ProviderInterface
{
    private int $attempts = 0;

    public function __construct(
        private string $answer,
        private bool $throwOnFirstAttempt,
        /**
         * Put CONTENT on the thinking chunk as well, so the announcement takes
         * runStreaming()'s `elseif` branch (a chunk that both spoke and
         * thought) instead of its `content === ''` branch.
         */
        private bool $speakWhileThinking = false,
    ) {}

    public function name(): string { return 'thinkthenfail'; }
    public function supportsStreaming(): bool { return true; }
    public function supportsFunctionCalling(): bool { return false; }
    public function supportsVision(): bool { return false; }
    public function supportsJsonSchema(): bool { return false; }
    public function contextWindow(): int { return 1000; }
    public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

    public function attempts(): int { return $this->attempts; }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: $this->answer);
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        $this->attempts++;

        if ($this->attempts === 1) {
            yield new CompleteResponse(
                content: $this->speakWhileThinking ? 'half a ' : '',
                reasoning: 'a first thought ',
            );

            if ($this->throwOnFirstAttempt) {
                throw new ServerException(
                    '503 Service Unavailable',
                    new Request('POST', 'https://example.invalid/v1'),
                    new Response(503),
                );
            }

            yield new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: 'overloaded',
                errorTransient: true,
            );

            return;
        }

        yield new CompleteResponse(content: $this->answer);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse([]);
    }
}

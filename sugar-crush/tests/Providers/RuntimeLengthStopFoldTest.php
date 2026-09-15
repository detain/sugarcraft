<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;

/**
 * E707 (round 81), the fold half: CompleteResponse::$truncated - parsed at
 * the wire but historically dropped on the floor - now reaches the assistant
 * message the agentic loop and the transcript consume. Both Runtime seams are
 * pinned: the batch yield passes the single response's verdict through, and
 * the streaming loop ORs it ACROSS the attempt's frames (the ceiling can ride
 * any one of them) into the assembled message.
 *
 * The per-attempt half matters as much as the flag itself: a FAILED attempt
 * that saw a length stop must not stain the clean retry that follows, the
 * same way $usages must not carry a dead attempt's bill forward - the retry
 * resets every accumulator, and this file proves the fifth one is in that set.
 */
final class RuntimeLengthStopFoldTest extends TestCase
{
    public function testTheBatchFoldPassesTheCeilingVerdictThrough(): void
    {
        $message = $this->runTurn($this->stopAtCeilingBatchDouble());

        $this->assertTrue($message->lengthStopped(), 'a batch answer the wire stopped at the ceiling arrives marked');
        $this->assertSame('the text stops mid', $message->content(), 'the partial text is still the whole truth of what arrived');
    }

    public function testTheBatchFoldKeepsACleanAnswerUnstained(): void
    {
        $message = $this->runTurn($this->cleanBatchDouble());

        $this->assertFalse($message->lengthStopped(), 'default false is load-bearing: every provider that never sets the flag must fold to a clean turn');
    }

    public function testTheStreamingFoldOrsTheVerdictAcrossFrames(): void
    {
        // The Vertex/Bedrock topology: the stop arrives on ONE late frame
        // among content deltas and usage emissions, not on the first.
        $message = $this->runTurn($this->streamDouble([
            new CompleteResponse(content: 'cut'),
            new CompleteResponse(content: '', tokensUsed: 4, costUsd: 0.001, truncated: true),
        ]));

        $this->assertTrue($message->lengthStopped());
        $this->assertSame('cut', $message->content(), 'the flag frame carried no text and the buffer did not invent any');
        $this->assertNotNull($message->usage(), 'the usage fold next to the new accumulator is untouched');
    }

    public function testTheStreamingFoldStaysCleanWhenNoFrameSpeaks(): void
    {
        $message = $this->runTurn($this->streamDouble([
            new CompleteResponse(content: 'a'),
            new CompleteResponse(content: 'b', tokensUsed: 2, costUsd: 0.0),
        ]));

        $this->assertFalse($message->lengthStopped());
        $this->assertSame('ab', $message->content());
    }

    public function testALengthStopSeenByAFailedAttemptDoesNotStainTheCleanRetry(): void
    {
        $message = $this->runTurn($this->dyingThenCleanStreamDouble());

        $this->assertTrue($message->lengthStopped() === false, 'attempt two closed cleanly - the retry law (every accumulator resets per attempt) covers the fifth accumulator too');
        $this->assertSame('whole second try', $message->content(), 'and the buffer reset proves it did more than the flag');
    }

    // =========================================================================
    // harness
    // =========================================================================

    private function runTurn(ProviderInterface $provider): AssistantMessage
    {
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(App::new($provider, 'e707-fold')));

        $this->assertNotEmpty($messages, 'the turn must settle an assistant message');
        $first = $messages[0];
        $this->assertInstanceOf(AssistantMessage::class, $first);

        return $first;
    }

    private function stopAtCeilingBatchDouble(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string
            {
                return 'e707-batch-stopped';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
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
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: 'the text stops mid', tokensUsed: 8, costUsd: 0.001, truncated: true);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    private function cleanBatchDouble(): ProviderInterface
    {
        return new class implements ProviderInterface {
            public function name(): string
            {
                return 'e707-batch-clean';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
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
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: 'all of it', tokensUsed: 3, costUsd: 0.0);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    /** @param list<CompleteResponse> $frames */
    private function streamDouble(array $frames): ProviderInterface
    {
        return new class ($frames) implements ProviderInterface {
            /** @param list<CompleteResponse> $answerFrames */
            public function __construct(private readonly array $answerFrames) {}

            public function name(): string
            {
                return 'e707-stream-double';
            }

            public function supportsStreaming(): bool
            {
                return true;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
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
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: 'unused batch arm');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                foreach ($this->answerFrames as $frame) {
                    yield $frame;
                }
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    /**
     * First attempt: one text frame, a flag frame, then a transient error
     * RESPONSE (the shape Vertex and Custom report failure as) so the loop
     * retries without ever having handed a byte to $onToken. Second attempt:
     * a clean full reply. If $lengthStopped were call-scoped instead of
     * attempt-scoped, the retry would inherit the dead attempt's verdict.
     */
    private function dyingThenCleanStreamDouble(): ProviderInterface
    {
        return new class implements ProviderInterface {
            private int $visits = 0;

            public function name(): string
            {
                return 'e707-dying-stream';
            }

            public function supportsStreaming(): bool
            {
                return true;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
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
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: 'unused batch arm');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                $this->visits++;

                if ($this->visits === 1) {
                    yield new CompleteResponse(content: 'par');
                    yield new CompleteResponse(content: '', truncated: true);
                    yield new CompleteResponse(
                        content: '',
                        isError: true,
                        errorMessage: 'overloaded',
                        errorTransient: true,
                    );

                    return;
                }

                yield new CompleteResponse(content: 'whole second try');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }
}

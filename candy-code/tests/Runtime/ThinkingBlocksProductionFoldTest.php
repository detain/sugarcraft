<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\ProviderStreamException;
use SugarCraft\Code\Providers\ReplyContinuation;
use SugarCraft\Code\Runtime;

/**
 * CTX-1 (§6.3) through the PRODUCTION path: a signed thinking block that
 * arrives on a CompleteResponse must reach the yielded AssistantMessage
 * (batch and streaming alike) and from there lead the next Anthropic request
 * body - the wire refuses a replayed thinking turn whose blocks were dropped.
 *
 * The provider-level tests ({@see \SugarCraft\Code\Tests\Providers\AnthropicExtendedThinkingTest})
 * build their AssistantMessage by hand, so they could not see the fold
 * missing from the Runtime itself; every test here drives Runtime::run and
 * judges only what it yields.
 */
final class ThinkingBlocksProductionFoldTest extends TestCase
{
    private const FOLD_MODEL = 'claude-sonnet-4-6';

    /** @var array<string, string> */
    private const FIRST_BLOCK = [
        'type' => 'thinking',
        'thinking' => 'weighing the first option',
        'signature' => 'Eq4BCnEIARABGAIqQ-first-signature-opaque',
    ];

    /** @var array<string, string> */
    private const SECOND_BLOCK = [
        'type' => 'redacted_thinking',
        'data' => 'Em8CCnEIARABGAIqQ-second-opaque-payload',
    ];

    // -------------------------------------------------------------------------
    // The fold: blocks ride the yielded message, in order
    // -------------------------------------------------------------------------

    public function testTheBatchAnswerCarriesItsSignedBlocksOntoTheYieldedMessage(): void
    {
        $reply = self::runTurn(self::batchProvider(new CompleteResponse(
            content: 'done',
            thinkingBlocks: [self::FIRST_BLOCK, self::SECOND_BLOCK],
        )));

        self::assertSame([self::FIRST_BLOCK, self::SECOND_BLOCK], $reply->thinkingBlocks());
    }

    public function testTheStreamFoldsBlocksAcrossChunksInWireOrder(): void
    {
        $script = self::streamScript([
            [['first', new CompleteResponse(content: '', thinkingBlocks: [self::FIRST_BLOCK])], null],
        ]);
        $reply = self::runTurn(new DroppedStreamProvider($script));

        self::assertSame('first', $reply->content());

        $script2 = self::streamScript([
            [[new CompleteResponse(content: 'a ', thinkingBlocks: [self::FIRST_BLOCK]),
              new CompleteResponse(content: 'b', thinkingBlocks: [self::SECOND_BLOCK])], null],
        ]);
        $reply2 = self::runTurn(new DroppedStreamProvider($script2));

        self::assertSame([self::FIRST_BLOCK, self::SECOND_BLOCK], $reply2->thinkingBlocks(),
            'carriers MERGE in wire order - a later chunk never replaces an earlier block');
    }

    // -------------------------------------------------------------------------
    // The replay: blocks from the yielded message lead the next request
    // -------------------------------------------------------------------------

    public function testTheFoldedBlocksLeadTheNextAnthropicRequestBody(): void
    {
        $reply = self::runTurn(self::batchProvider(new CompleteResponse(
            content: 'done',
            thinkingBlocks: [self::FIRST_BLOCK, self::SECOND_BLOCK],
        )));

        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::FOLD_MODEL,
            self::capturingSeam(['content' => []], $captured),
            null,
            [],
            true,
            null,
            2048,
        );

        $provider->complete(new CompleteRequest(
            model: self::FOLD_MODEL,
            messages: [new UserMessage('go'), $reply],
        ));

        $blocks = $captured[1]['messages'][1]['content'];
        self::assertSame(self::FIRST_BLOCK, $blocks[0], 'the fold is pointless unless the next body replays the block first');
        self::assertSame(self::SECOND_BLOCK, $blocks[1]);
        self::assertSame('text', $blocks[2]['type']);
        self::assertSame('done', $blocks[2]['text']);
    }

    // -------------------------------------------------------------------------
    // Per-attempt discipline: carried blocks merge, unstained blocks die
    // -------------------------------------------------------------------------

    public function testADroppedAttemptCarriesItsBlocksIntoTheContinuedReply(): void
    {
        $script = self::streamScript([
            [['Hel', new CompleteResponse(content: '', thinkingBlocks: [self::FIRST_BLOCK])], ProviderStreamException::prematureEnd()],
            [[new CompleteResponse(content: 'lo', thinkingBlocks: [self::SECOND_BLOCK])], null],
        ]);

        $reply = self::runTurn(new DroppedStreamProvider($script));

        self::assertSame('Hello', $reply->content());
        self::assertSame([self::FIRST_BLOCK, self::SECOND_BLOCK], $reply->thinkingBlocks(),
            'the conversation received both blocks, so the replay must carry both, partial first');
    }

    public function testAFailedAttemptThatSaidNothingStainsTheReplyWithNoBlocks(): void
    {
        $script = self::streamScript([
            [[new CompleteResponse(content: '', thinkingBlocks: [self::FIRST_BLOCK])], ProviderStreamException::prematureEnd()],
            [[new CompleteResponse(content: 'fresh', thinkingBlocks: [self::SECOND_BLOCK])], null],
        ]);

        $reply = self::runTurn(new DroppedStreamProvider($script));

        self::assertSame('fresh', $reply->content());
        self::assertSame([self::SECOND_BLOCK], $reply->thinkingBlocks(),
            'a restarted attempt replays only what the final reply actually said');
    }

    public function testMergingAPartialWithItsContinuationJoinsTheirBlocks(): void
    {
        $partial = new AssistantMessage('Hel', null, null, null, false, [self::FIRST_BLOCK]);
        $next = new AssistantMessage('lo', null, null, null, false, [self::SECOND_BLOCK]);

        $joined = ReplyContinuation::merge($partial, $next, false);

        self::assertSame([self::FIRST_BLOCK, self::SECOND_BLOCK], $joined->thinkingBlocks());
    }

    // -------------------------------------------------------------------------
    // Rehydration reads: legacy rows rehydrate empty, new rows restore
    // -------------------------------------------------------------------------

    public function testTheWorkerRehydrationReadsTheBlocksKeyTolerantly(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Agents/ProcessExecutor.php');

        self::assertSame(1, substr_count($source, "is_array(\$entry['thinkingBlocks'] ?? null) ? \$entry['thinkingBlocks'] : []"),
            'the generated worker restores blocks when the row carries them and rehydrates [] for legacy rows');
    }

    public function testTheTypedRowRebuildStatesTheBlocksLossInsteadOfHidingIt(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Backend/EngineBackend.php');

        self::assertSame(0, substr_count($source, '$assistant->reasoning);'),
            'no AssistantMessage rebuild may leave the blocks argument to its default silently');
        self::assertSame(1, substr_count($source, '$assistant->reasoning, null, false, []);'));
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    /** Drive one turn through the production Runtime and return its reply. */
    private static function runTurn(ProviderInterface $provider): AssistantMessage
    {
        $messages = iterator_to_array(
            (new Runtime($provider, new HookManager(new HookRegistry())))->run(
                App::new($provider, 'm')->withMessages([new UserMessage('go')]),
                null,
                null,
                static function (string $delta): void {
                },
            ),
            false,
        );

        self::assertInstanceOf(AssistantMessage::class, $messages[0]);

        return $messages[0];
    }

    /**
     * A non-streaming provider answering with one canned response.
     */
    private static function batchProvider(CompleteResponse $response): ProviderInterface
    {
        return new class ($response) implements ProviderInterface {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            public function __construct(private readonly CompleteResponse $response)
            {
            }

            public function name(): string
            {
                return 'fold-batch';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsDocuments(): bool
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
                return 100_000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->requests[] = $request;

                return $this->response;
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                throw new \LogicException('batch only');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    /**
     * The attempt script for {@see DroppedStreamProvider}: per call, its
     * chunks and the failure that ends it (null: a clean end).
     *
     * @param list<array{0: list<string|CompleteResponse>, 1: ?\Throwable}> $attempts
     */
    private static function streamScript(array $attempts): object
    {
        return new class ($attempts) {
            /** @var list<CompleteRequest> */
            public array $requests = [];

            public function __construct(private readonly array $attempts)
            {
            }

            public function stream(CompleteRequest $request): \Generator
            {
                [$chunks, $failure] = $this->attempts[count($this->requests)] ?? [[], new \LogicException('out of script')];
                $this->requests[] = $request;
                foreach ($chunks as $chunk) {
                    yield $chunk instanceof CompleteResponse ? $chunk : new CompleteResponse(content: $chunk);
                }
                if ($failure !== null) {
                    throw $failure;
                }
            }
        };
    }

    /**
     * @param array<string, mixed> $response
     * @param array{0: string, 1: array<string, mixed>} $captured
     */
    private static function capturingSeam(array $response, array &$captured): callable
    {
        $captured = ['', []];

        return static function (string $endpoint, array $body) use ($response, &$captured): array {
            $captured = [$endpoint, $body];

            return $response;
        };
    }
}

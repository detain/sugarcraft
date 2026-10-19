<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachment;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\HistorySanitizer;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\VertexProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\ToolCall;

/**
 * CTX-1 (§6.3): Anthropic extended thinking, both halves in one round trip.
 *
 * The request side emits `thinking: {type: enabled, budget_tokens: N}` under
 * the protocol triangle (temperature forced to 1, max_tokens kept above the
 * budget); the reply side keeps the SIGNED thinking blocks on
 * CompleteResponse::$thinkingBlocks, AssistantMessage stores them, and the
 * next request replays them as the assistant turn's leading content blocks -
 * the shape the Messages API requires for thinking turns that end in tool
 * calls, and the reason the two halves could only ship together: a budget
 * without the replay 400s on the first tool turn.
 *
 * The byte-identity of the OFF shape (no `thinking` key, untouched
 * temperature/max_tokens, no replayed blocks) is pinned here because the
 * shared wire also carries Vertex's `:rawPredict` arm, whose budget hook is
 * tier-only since PRV-2 (`VertexProvider` keeps `thinkingBudget` Gemini-side only
 * and an unnamed effort tier answers null).
 */
final class AnthropicExtendedThinkingTest extends TestCase
{
    use HomeSandboxTrait;

    private const THINKING_MODEL = 'claude-sonnet-4-6';

    private const SIGNED_BLOCK = [
        'type' => 'thinking',
        'thinking' => 'weighing the options',
        'signature' => 'Eq4BCnEIARABGAIqQ...',
    ];

    /** Owned-name spelling: byte-identical data under a shared name would trip the const-drift census. */
    private const MINI_PDF = '%PDF-1.7' . "\n" . "\x00\x00\x00" . 'body' . "\n%%EOF\n";

    private const REDACTED_BLOCK = [
        'type' => 'redacted_thinking',
        'data' => 'Em8CCnEIARABGAIqQL1 opaque-payload',
    ];

    private string $tmpDir = '';
    private string $configDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/anthropic_thinking_' . uniqid('', true);
        $home = $this->tmpDir . '/home';
        $this->configDir = $home . '/.candy-code';
        mkdir($this->configDir, 0o700, true);

        $this->useHomeSandbox($home);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        $this->removeTree($this->tmpDir);

        parent::tearDown();
    }

    /**
     * Drive one unary call through the seam, capturing [endpoint, body].
     *
     * @param array<string, mixed>   $response the canned response document
     * @param array{0: string, 1: array<string, mixed>} $captured filled by reference
     */
    private function nativeUnarySeam(array $response, array &$captured): callable
    {
        $captured = ['', []];

        return static function (string $endpoint, array $body) use ($response, &$captured): array {
            $captured = [$endpoint, $body];

            return $response;
        };
    }

    /**
     * A stream seam yielding the given canned events; kept as a helper so
     * every stream test below balances its own parentheses independently.
     *
     * @param list<array<string, mixed>> $events
     */
    private function streamSeam(array $events): callable
    {
        return static function (string $endpoint, array $body) use ($events): iterable {
            yield from $events;
        };
    }

    /** @param array<string, mixed> $response */
    private function providerWithBudget(?int $budget, array $response, array &$captured): AnthropicProvider
    {
        return new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::THINKING_MODEL,
            $this->nativeUnarySeam($response, $captured),
            null,
            [],
            true,
            null,
            $budget,
        );
    }

    private function turnRequest(?array $messages = null, ?float $temperature = null, ?int $maxTokens = null, string $model = self::THINKING_MODEL, ?string $reasoningEffort = null): CompleteRequest
    {
        return new CompleteRequest(
            model: $model,
            messages: $messages ?? [new UserMessage('go')],
            temperature: $temperature,
            maxTokens: $maxTokens,
            reasoningEffort: $reasoningEffort,
        );
    }

    /** @param list<array<string, mixed>> $events */
    private function streamFrames(array $events, ?int $budget): array
    {
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::THINKING_MODEL,
            null,
            $this->streamSeam($events),
            [],
            true,
            null,
            $budget,
        );

        return iterator_to_array($provider->completeStream($this->turnRequest()), false);
    }

    // -------------------------------------------------------------------------
    // Request side: the body triangle
    // -------------------------------------------------------------------------

    public function testTheOffShapeSendsNoThinkingFieldAndKeepsEveryDefaultByteItSentBefore(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(null, ['content' => []], $captured);

        $provider->complete($this->turnRequest(temperature: 0.2, maxTokens: 300));

        $body = $captured[1];
        self::assertArrayNotHasKey('thinking', $body, 'no budget means the pre-CTX-1 body, key for key');
        self::assertSame(0.2, $body['temperature'], 'without thinking the caller keeps its temperature');
        self::assertSame(300, $body['max_tokens'], 'without thinking an explicit ceiling is an explicit ceiling');
    }

    public function testAnEnabledBudgetRidesTheBodyAndForcesTheProtocolTriangle(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(8192, ['content' => []], $captured);

        $provider->complete($this->turnRequest(temperature: 0.2, maxTokens: 500));

        $body = $captured[1];
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 8192], $body['thinking']);
        self::assertSame(1.0, $body['temperature'], 'the API 400s on any other temperature while thinking is on');
        self::assertSame(12288, $body['max_tokens'], 'the 500 ask is raised over the 8192 budget (x1.5 floor)');
    }

    public function testTheCeilingIsNeverShrunkAndTheDefaultOnlyWinsWhenItIsAlreadyAbove(): void
    {
        $captured = [];
        $this->providerWithBudget(1024, ['content' => []], $captured)
            ->complete($this->turnRequest(maxTokens: 32000));
        self::assertSame(32000, $captured[1]['max_tokens'], 'a ceiling above the floor stays exactly where the caller put it');

        $captured = [];
        $this->providerWithBudget(2048, ['content' => []], $captured)
            ->complete($this->turnRequest());
        self::assertSame(4096, $captured[1]['max_tokens'], 'the 4096 default already clears a 2048 budget x1.5, so nothing moves');
    }

    public function testWithModelCarriesTheThinkingBudgetAcrossTheModelSwitch(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(4096, ['content' => []], $captured);

        $provider->withModel('claude-opus-4-1')->complete($this->turnRequest(model: ''));

        self::assertSame('claude-opus-4-1', $captured[1]['model']);
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 4096], $captured[1]['thinking'], 'a model switch is not a settings reset');
    }

    // -------------------------------------------------------------------------
    // PRV-2: the effort tier rides the same budget the configured key owns
    // -------------------------------------------------------------------------

    public function testAnEffortTierThinksWithoutAnyConfiguredBudget(): void
    {
        $captured = [];
        $this->providerWithBudget(null, ['content' => []], $captured)
            ->complete($this->turnRequest(reasoningEffort: 'medium'));

        self::assertSame(['type' => 'enabled', 'budget_tokens' => 4096], $captured[1]['thinking'], "medium's token count is the budget");
        self::assertSame(1.0, $captured[1]['temperature'], 'the thinking triangle is enforced from the tier too');
        self::assertSame(6144, $captured[1]['max_tokens'], '4096 plus half of it clears the default ceiling, so it rises to 6144');
    }

    public function testTheConfiguredBudgetWinsWhereAnEffortTierAlsoSpeaks(): void
    {
        $captured = [];
        $this->providerWithBudget(2048, ['content' => []], $captured)
            ->complete($this->turnRequest(reasoningEffort: 'max'));

        self::assertSame(['type' => 'enabled', 'budget_tokens' => 2048], $captured[1]['thinking'], 'max would say 32768; the key says 2048 and the key is authority');
    }

    public function testAnUnnamedTierLeavesTheOffShapeByteIdenticalThroughTheNewHook(): void
    {
        $captured = [];
        $this->providerWithBudget(null, ['content' => []], $captured)
            ->complete($this->turnRequest());

        self::assertArrayNotHasKey('thinking', $captured[1], 'no key, no tier: the field is absent, not null');
        self::assertSame(0.7, $captured[1]['temperature'], 'the ordinary default temperature rides');
        self::assertSame(4096, $captured[1]['max_tokens'], 'the ordinary default ceiling rides');
    }

    public function testTheEffortTierOpensTheSignedBlockReplayGateToo(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(null, ['content' => []], $captured);

        $provider->complete($this->turnRequest(
            messages: [
                new UserMessage('go'),
                new AssistantMessage('', [new ToolCall('tu_1', 'Read', ['path' => 'a'])], null, null, false, [self::SIGNED_BLOCK]),
            ],
            reasoningEffort: 'high',
        ));

        $blocks = $captured[1]['messages'][1]['content'];
        self::assertSame(self::SIGNED_BLOCK, $blocks[0], 'a tier-built budget replays the chain of custody exactly like a configured one');
    }

    public function testNoBudgetAndNoTierKeepsTheReplayGateShut(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(null, ['content' => []], $captured);

        $provider->complete($this->turnRequest(messages: [
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('tu_1', 'Read', ['path' => 'a'])], null, null, false, [self::SIGNED_BLOCK]),
        ]));

        $blocks = $captured[1]['messages'][1]['content'];
        self::assertSame('tool_use', $blocks[0]['type'], 'thinking was never on, so the un-replayable block stays off the wire');
    }

    #[DataProvider('refusedBudgets')]
    public function testTheConstructorRefusesBudgetsTheMessagesApiWouldRefuse(mixed $budget, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($needle);

        /** @phpstan-ignore-next-line intentionally invalid */
        new AnthropicProvider('sk-test', 'https://api.anthropic.com', self::THINKING_MODEL, null, null, [], true, null, $budget);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function refusedBudgets(): iterable
    {
        yield 'just under the floor' => [1023, 'below the protocol floor of 1024'];
        yield 'one token' => [1, 'below the protocol floor of 1024'];
        yield "gemini's dynamic" => [-1, 'below the protocol floor of 1024'];
    }

    // -------------------------------------------------------------------------
    // Reply side: the signed blocks leave the parse on the carrier
    // -------------------------------------------------------------------------

    public function testASignedUnaryThinkingBlockFoldsToReasoningAndRidesTheCarrier(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(2048, [
            'content' => [
                ['type' => 'thinking', 'thinking' => 'weighing the options', 'signature' => 'Eq4BCnEIARABGAIqQ...'],
                ['type' => 'text', 'text' => 'done'],
            ],
        ], $captured);

        $response = $provider->complete($this->turnRequest());

        self::assertSame('weighing the options', $response->reasoning, 'the human ticker keeps folding plain text');
        self::assertSame([self::SIGNED_BLOCK], $response->thinkingBlocks, 'the signature rides out verbatim, block shape and all');
        self::assertSame('done', $response->content);
    }

    public function testAnUnsignedThinkingBlockFoldsButIsNotCollected(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(2048, [
            'content' => [['type' => 'thinking', 'thinking' => 'no signature here']],
        ], $captured);

        $response = $provider->complete($this->turnRequest());

        self::assertSame('no signature here', $response->reasoning);
        self::assertSame([], $response->thinkingBlocks, 'a block that could not be replayed is not carried as if it could');
    }

    public function testARedactedBlockCarriesItsOpaqueDataAndFoldsNothing(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(2048, [
            'content' => [['type' => 'redacted_thinking', 'data' => 'Em8CCnEIARABGAIqQL1 opaque-payload']],
        ], $captured);

        $response = $provider->complete($this->turnRequest());

        self::assertNull($response->reasoning, 'redacted thinking has no text, before or after CTX-1');
        self::assertSame([self::REDACTED_BLOCK], $response->thinkingBlocks);
    }

    public function testAReplyWithoutThinkingBlocksLeavesTheCarrierEmpty(): void
    {
        $captured = [];
        $response = $this->providerWithBudget(null, ['content' => [['type' => 'text', 'text' => 'hi']]], $captured)
            ->complete($this->turnRequest());

        self::assertSame([], $response->thinkingBlocks, 'the ordinary answer stays the ordinary []');
    }

    // -------------------------------------------------------------------------
    // Reply side, streaming: the block assembles across deltas and leaves on stop
    // -------------------------------------------------------------------------

    public function testTheStreamedSignedBlockAssemblesFromDeltasAndLeavesOnItsClosingFrame(): void
    {
        $frames = $this->streamFrames([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'weighing ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'the options']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'Eq4BCnEIARABGAIqQ...']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'done']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
            ['type' => 'message_stop'],
        ], budget: 2048);

        $carriers = array_values(array_filter($frames, static fn (CompleteResponse $f): bool => $f->thinkingBlocks !== []));
        self::assertCount(1, $carriers, 'exactly the closing block frame carries the block');
        self::assertSame([self::SIGNED_BLOCK], $carriers[0]->thinkingBlocks);

        $ticker = array_values(array_filter($frames, static fn (CompleteResponse $f): bool => is_string($f->reasoning)));
        self::assertSame(['weighing ', 'the options'], array_map(static fn (CompleteResponse $f): string => (string) $f->reasoning, $ticker), 'the live ticker contract is untouched');

        $text = array_values(array_filter($frames, static fn (CompleteResponse $f): bool => $f->content !== ''));
        self::assertSame(['done'], array_map(static fn (CompleteResponse $f): string => $f->content, $text));
    }

    public function testAStreamedBlockWhoseSignatureNeverArrivedIsNeverCollected(): void
    {
        $frames = $this->streamFrames([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'cut short']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
            ['type' => 'message_stop'],
        ], budget: 2048);

        self::assertSame([], array_values(array_filter($frames, static fn (CompleteResponse $f): bool => $f->thinkingBlocks !== [])));
        self::assertContains('cut short', array_map(static fn (CompleteResponse $f): ?string => $f->reasoning, $frames), 'the text still streamed; only the replay is withheld');
    }

    public function testARedactedStreamBlockArrivesWholeInItsStartEvent(): void
    {
        $frames = $this->streamFrames([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'redacted_thinking', 'data' => 'Em8CCnEIARABGAIqQL1 opaque-payload']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
            ['type' => 'message_stop'],
        ], budget: 2048);

        $carriers = array_values(array_filter($frames, static fn (CompleteResponse $f): bool => $f->thinkingBlocks !== []));
        self::assertCount(1, $carriers);
        self::assertSame([self::REDACTED_BLOCK], $carriers[0]->thinkingBlocks);
    }

    // -------------------------------------------------------------------------
    // Replay: stored blocks lead the assistant turn they belong to
    // -------------------------------------------------------------------------

    public function testSignedBlocksLeadTheReplayedAssistantTurnAheadOfTheirToolUse(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(2048, ['content' => []], $captured);

        $provider->complete($this->turnRequest(messages: [
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('tu_1', 'Read', ['path' => 'a'])], null, null, false, [self::SIGNED_BLOCK]),
        ]));

        $blocks = $captured[1]['messages'][1]['content'];
        self::assertSame(self::SIGNED_BLOCK, $blocks[0], 'the signed block replays FIRST, verbatim - the chain of custody');
        self::assertSame('tool_use', $blocks[1]['type']);
        self::assertSame('tu_1', $blocks[1]['id']);
    }

    /**
     * plan_comparison CTX-2 review MAJOR: the combined shape of the two
     * families in ONE wire body. Documents ride USER turns' content arrays
     * ({@see \SugarCraft\Code\Providers\AttachmentEncoding::anthropicBlocks()});
     * signed thinking blocks lead ASSISTANT turns. They cannot collide today
     * - and that is exactly why the shape needs a pin: a future refactor
     * merging thinking into the user turn (or documents into the assistant
     * turn) would 400 at Anthropic with zero test red.
     */
    public function testThinkingReplayLeadsTheAssistantTurnWhileTheDocumentRidesTheUserTurn(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(2048, ['content' => []], $captured);

        $provider->complete($this->turnRequest(messages: [
            (new UserMessage('read the first spec'))
                ->withAttachment(new Attachment('spec.pdf', AttachmentType::Document, self::MINI_PDF, 'application/pdf')),
            new AssistantMessage('reading now', [new ToolCall('tu_1', 'Read', ['path' => 'spec.pdf'])], null, null, false, [self::SIGNED_BLOCK]),
            new ToolResultMessage('tu_1', 'the contents'),
            (new UserMessage('and the second'))
                ->withAttachment(new Attachment('plan.pdf', AttachmentType::Document, self::MINI_PDF, 'application/pdf')),
        ]));

        $turns = $captured[1]['messages'];
        self::assertCount(3, $turns, 'tool_result is a user turn, so it merges with the user turn that follows it');

        self::assertSame('user', $turns[0]['role']);
        self::assertSame(['text', 'document'], array_column($turns[0]['content'], 'type'), 'text first, then the document block');
        self::assertSame('spec.pdf', $turns[0]['content'][1]['title']);

        self::assertSame('assistant', $turns[1]['role']);
        $assistantTypes = array_column($turns[1]['content'], 'type');
        self::assertSame(['thinking', 'text', 'tool_use'], $assistantTypes, 'the signed block LEADS the assistant turn, ahead of its text and tool_use');
        self::assertSame(self::SIGNED_BLOCK, $turns[1]['content'][0]);
        self::assertArrayHasKey('signature', $turns[1]['content'][0], 'the chain of custody rides verbatim');

        self::assertSame('user', $turns[2]['role']);
        self::assertSame(['tool_result', 'text', 'document'], array_column($turns[2]['content'], 'type'), 'the tool_result precedes the merged user content, and the second document rides a user turn too');

        foreach ($turns as $turn) {
            if ($turn['role'] === 'assistant') {
                self::assertNotContains('document', array_column($turn['content'], 'type'), 'no PDF block ever enters an assistant turn - the API takes thinking there, not documents');
            }
        }
        $leadings = array_map(static fn (array $t): array => array_column($t['content'], 'type'), array_values(array_filter($turns, static fn (array $t): bool => $t['role'] === 'user')));
        foreach ($leadings as $i => $types) {
            self::assertNotContains('thinking', $types, "user turn {$i} carries a replayed thinking block - that shape 400s");
        }
    }

    public function testStoredBlocksStayOutOfRequestsThatDidNotAskForThinking(): void
    {
        $captured = [];
        $provider = $this->providerWithBudget(null, ['content' => []], $captured);

        $provider->complete($this->turnRequest(messages: [
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('tu_1', 'Read', ['path' => 'a'])], null, null, false, [self::SIGNED_BLOCK]),
        ]));

        $blocks = $captured[1]['messages'][1]['content'];
        self::assertCount(1, $blocks, 'a host with the hook off replays exactly the pre-CTX-1 turn');
        self::assertSame('tool_use', $blocks[0]['type']);
    }

    public function testALegacyAssistantRowWithoutTheKeyReplaysWithNothingAndBreaksNothing(): void
    {
        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::THINKING_MODEL,
            $this->nativeUnarySeam(['content' => []], $captured),
            null,
            [],
            false,
            null,
            2048,
        );

        $provider->complete($this->turnRequest(messages: [
            new UserMessage('go'),
            new AssistantMessage('older than CTX-1'),
        ]));

        self::assertSame([['type' => 'text', 'text' => 'older than CTX-1']], $captured[1]['messages'][1]['content']);
    }

    // -------------------------------------------------------------------------
    // The DTOs carry it: defaults, toArray, the sanitizer rename
    // -------------------------------------------------------------------------

    public function testAssistantMessagesExposeTheWireKeyTolerantlyForLegacyRows(): void
    {
        $legacy = new AssistantMessage('old');
        self::assertSame([], $legacy->thinkingBlocks());
        self::assertSame([], $legacy->toArray()['thinkingBlocks'], 'every pre-CTX-1 transcript reads as [] through the new key');

        $thinking = new AssistantMessage('', null, null, null, false, [self::SIGNED_BLOCK]);
        self::assertSame([self::SIGNED_BLOCK], $thinking->toArray()['thinkingBlocks']);
    }

    public function testTheIdRenameCarriesSignedBlocksBecauseStrandingThemWouldBreakTheReplay(): void
    {
        $out = HistorySanitizer::sanitize([
            new UserMessage('go'),
            new AssistantMessage('one', [new ToolCall('x', 'Read', ['path' => 'a'])], null, null, false, [self::SIGNED_BLOCK]),
            new ToolResultMessage('x', 'A'),
            new AssistantMessage('two', [new ToolCall('x', 'Read', ['path' => 'b'])], null, null, false, [self::REDACTED_BLOCK]),
            new ToolResultMessage('x', 'B'),
        ]);

        self::assertSame('x_r2', $out[3]->toolCalls()[0]->id(), 'the rename happened (otherwise this pins nothing)');
        self::assertSame([self::REDACTED_BLOCK], $out[3]->thinkingBlocks(), 'renamed row keeps its own block');
        self::assertSame([self::SIGNED_BLOCK], $out[1]->thinkingBlocks(), 'the untouched row is the same row, blocks and all');
    }

    public function testSignedBlocksLeaveWithTheirRowBecauseSanitizerOnlyEverMovesWholeRows(): void
    {
        // The sanitizer's dropping half operates on orphaned tool_result rows,
        // which cannot hold thinking blocks; an assistant row it keeps is
        // either returned as-is or rebuilt through withToolCalls, and the
        // previous test pins that rebuild carrying blocks. What this pins is
        // that a block never outlives its row as a detached array: rows are
        // the unit of survival.
        $history = [
            new UserMessage('go'),
            new AssistantMessage('thought and spoke', null, 'why', null, false, [self::SIGNED_BLOCK]),
        ];

        self::assertSame($history, HistorySanitizer::sanitize($history), 'a clean history is untouched object-for-object');
        self::assertSame([self::SIGNED_BLOCK], $history[1]->thinkingBlocks());
    }

    // -------------------------------------------------------------------------
    // The factory boundary: provider block, user tier, strict refusal, Vertex
    // tolerance untouched
    // -------------------------------------------------------------------------

    public function testTheProviderBlockBudgetReachesTheProviderUntouched(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'anthropic',
            'apiKey' => 'sk-test',
            'model' => self::THINKING_MODEL,
            'thinkingBudget' => 1024,
        ]);

        self::assertInstanceOf(AnthropicProvider::class, $provider);
        self::assertSame(1024, self::privateValue($provider, 'thinkingBudget'));
    }

    public function testTheUserTierBudgetReachesAnthropicWhenTheBlockIsSilent(): void
    {
        $this->writeUserConfig(['thinkingBudget' => 2048]);

        $provider = (new ProviderFactory())->create([
            'type' => 'anthropic',
            'apiKey' => 'sk-test',
            'model' => self::THINKING_MODEL,
        ]);

        self::assertSame(2048, self::privateValue($provider, 'thinkingBudget'), 'the same key serves both arms, per-arm parsed');
    }

    public function testZeroAndAbsentMeanOffAndNeverReachTheRefusingConstructor(): void
    {
        $zero = (new ProviderFactory())->create(['type' => 'anthropic', 'apiKey' => 'k', 'thinkingBudget' => 0]);
        self::assertNull(self::privateValue($zero, 'thinkingBudget'));

        $unset = (new ProviderFactory())->create(['type' => 'anthropic', 'apiKey' => 'k']);
        self::assertNull(self::privateValue($unset, 'thinkingBudget'));
    }

    public function testTheStringFormOfTheBudgetParsesAsTheNumberItIs(): void
    {
        $provider = (new ProviderFactory())->create(['type' => 'anthropic', 'apiKey' => 'k', 'thinkingBudget' => '4096']);

        self::assertSame(4096, self::privateValue($provider, 'thinkingBudget'));
    }

    #[DataProvider('refusedFactoryBudgets')]
    public function testBudgetsTheArmCannotSendAreRefusedLoudlyAtTheBoundary(mixed $budget, string $needle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($needle);

        (new ProviderFactory())->create(['type' => 'anthropic', 'apiKey' => 'k', 'thinkingBudget' => $budget]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function refusedFactoryBudgets(): iterable
    {
        yield 'sub-floor' => [500, 'at least 1024'];
        yield "gemini's dynamic on the wrong arm" => [-1, 'at least 1024'];
        yield 'not a whole number' => ['plenty', 'must be a whole token count'];
        yield 'a float with a fraction' => [1024.5, 'must be a whole token count'];
    }

    public function testVertexKeepsItsTolerantParseUnchangedByTheAnthropicStrictness(): void
    {
        $vertex = (new ProviderFactory())->create([
            'type' => 'vertex',
            'projectId' => 'p',
            'model' => 'claude-made-up-4',
            'thinkingBudget' => -1,
        ]);

        self::assertInstanceOf(VertexProvider::class, $vertex);
        self::assertSame(-1, self::privateValue($vertex, 'thinkingBudget'), 'A21 tolerance survives on its own arm');

        $garbage = (new ProviderFactory())->create([
            'type' => 'vertex',
            'projectId' => 'p',
            'model' => 'claude-made-up-4',
            'thinkingBudget' => 'plenty',
        ]);
        self::assertNull(self::privateValue($garbage, 'thinkingBudget'), 'vertex still COSTS THE SETTING, never the launch');
    }

    public function testTheVertexAnthropicArmStillSendsNoThinkingFieldEvenWithABudgetSet(): void
    {
        $captured = [];
        $vertex = new VertexProvider(
            'p',
            'us-east1',
            'claude-made-up-4',
            predictor: static function (string $endpoint, string $method, array $body) use (&$captured): array {
                $captured = $body;

                return $body;
            },
            thinkingBudget: 2048,
        );

        // A Gemini thinking value must not leak onto the shared Anthropic
        // wire: the override reads the effort TIER only, and this request
        // names none, so the rawPredict body keeps its exact pre-CTX-1
        // shape despite the configured Gemini budget.
        $vertex->complete($this->turnRequest());

        self::assertArrayHasKey('anthropic_version', $captured, 'guard: the seam really received the Anthropic-arm body');
        self::assertArrayNotHasKey('thinking', $captured, "the same-number setting is Gemini's; the Anthropic arm stays silent");
        self::assertSame(0.7, $captured['temperature'], 'the pre-CTX-1 defaults ride untouched');
    }

    public function testTheVertexAnthropicArmDerivesItsThinkingFromTheEffortTierAlone(): void
    {
        $captured = [];
        $vertex = new VertexProvider(
            'p',
            'us-east1',
            'claude-made-up-4',
            predictor: static function (string $endpoint, string $method, array $body) use (&$captured): array {
                $captured = $body;

                return $body;
            },
            thinkingBudget: 2048,
        );

        // The tier rides; the same-number Gemini key still does not: 1024
        // is low's token count, not the configured 2048.
        $vertex->complete($this->turnRequest(reasoningEffort: 'low'));

        self::assertArrayHasKey('anthropic_version', $captured, 'guard: the seam really received the Anthropic-arm body');
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 1024], $captured['thinking']);
    }

    private static function privateValue(object $provider, string $property): mixed
    {
        return (new \ReflectionProperty($provider, $property))->getValue($provider);
    }

    /** @param array<string, mixed> $data */
    private function writeUserConfig(array $data): void
    {
        $path = $this->configDir . '/config.json';
        file_put_contents($path, (string) json_encode($data));
        chmod($path, 0o600);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}

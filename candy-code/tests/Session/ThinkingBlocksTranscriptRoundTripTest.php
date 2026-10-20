<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\ToolCall;

/**
 * CTX-1: the signed thinking-block column round-trips through the durable
 * transcript. Anthropic extended-thinking replies must be replayed VERBATIM
 * with their signature on the next request; before the column existed,
 * `EngineBackend::toTypedMessages()` reconstructed with `[]` and every
 * resume/serve replay of a signed turn lost the blocks. These pins hold the
 * three laws the fold rides: the carry is exact, it is additive by absence
 * (a block-less row serialises byte-for-byte as it did pre-column, so old
 * checkpoint hashes survive), and legacy/foreign blobs read back empty
 * rather than crash or trust.
 */
final class ThinkingBlocksTranscriptRoundTripTest extends TestCase
{
    /** @var array<string, mixed> one signed block, signature bytes and all */
    private const BLOCK = [
        'type' => 'thinking',
        'thinking' => 'first the model weighed options',
        'signature' => 'EsUBCnNpZ25lZC10aGlua2luZy1zaGFwZS1ieXRlcw',
    ];

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/ctx1_tb_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tempDir);
    }

    public function testTheTranscriptRoundTripCarriesSignedBlocksByteExactly(): void
    {
        $store = new EnhancedSessionStore($this->tempDir . '/sessions.db');
        $row = Message::assistant('the answer')
            ->withThinkingBlocks([self::BLOCK])
            ->withToolCalls([new ToolCall('Read', ['path' => 'a'], 'call_1')]);

        $store->saveTranscript('s1', [Message::user('go'), $row]);
        $loaded = $store->loadTranscript('s1');

        self::assertNotNull($loaded);
        // loadTranscript answers in the stored ROW shape; the resume path
        // folds those rows through Message::fromArray, so both the payload
        // and the rehydrated column are pinned.
        self::assertSame([self::BLOCK], $loaded[1]['thinkingBlocks'] ?? null, 'the signature rides the durable row, not just the live one');
        $restored = Message::fromArray($loaded[1]);
        self::assertSame([self::BLOCK], $restored->thinkingBlocks);
        self::assertSame($row->content, $restored->content);
        self::assertEquals($row->toolCalls, $restored->toolCalls, 'every other field survives unchanged beside the new column');
        self::assertArrayNotHasKey('thinkingBlocks', $loaded[0], 'the block-less row serialises without the key as before the column (hash compatibility)');
    }

    public function testABlocklessRowSerialisesExactlyAsBeforeTheColumn(): void
    {
        $plain = Message::assistant('quiet')->jsonSerialize();
        self::assertArrayNotHasKey('thinkingBlocks', $plain, 'additive by absence: pre-column checkpoints keep their hashes');

        $carried = Message::assistant('loud')->withThinkingBlocks([self::BLOCK])->jsonSerialize();
        self::assertSame([self::BLOCK], $carried['thinkingBlocks']);
    }

    public function testLegacyAndForeignBlobsReadBackEmpty(): void
    {
        // A row written before the column: no key at all.
        $legacy = Message::fromArray(['role' => 'assistant', 'content' => 'old row']);
        self::assertSame([], $legacy->thinkingBlocks);

        // A hostile or corrupt blob: the wrong shape is refused to the
        // default, never surfaced to a replay wire.
        $foreign = Message::fromArray(['role' => 'assistant', 'content' => 'x', 'thinkingBlocks' => 'not a list']);
        self::assertSame([], $foreign->thinkingBlocks);
        $mixed = Message::fromArray(['role' => 'assistant', 'content' => 'x', 'thinkingBlocks' => [self::BLOCK, 'garbage', 7]]);
        self::assertSame([self::BLOCK], $mixed->thinkingBlocks, 'well-formed entries survive, the rest falls to the filter');
    }

    public function testTheColumnNeverTravelsToTheModelWireAndSurvivesEveryWither(): void
    {
        $row = Message::assistant('signed')->withThinkingBlocks([self::BLOCK]);

        // toWire is the model-facing shape; blocks ride history, and the
        // PROVIDER wire gets its blocks from the typed AssistantMessage,
        // never from a root row leaking them here.
        self::assertStringNotContainsString('signature', json_encode($row->toWire(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('thinkingBlocks', json_encode($row->toWire(), JSON_THROW_ON_ERROR));

        // The one-copier mutate() law: a field added to the constructor must
        // not be silently dropped by any later wither.
        $folded = $row
            ->withToolCalls([new ToolCall('Bash', ['command' => 'ls'], 'c1')])
            ->withUiOnly(true)
            ->withStepId('st_1');
        self::assertSame([self::BLOCK], $folded->thinkingBlocks, 'a wither that forgot the column is the bug this pin refuses');
    }
}

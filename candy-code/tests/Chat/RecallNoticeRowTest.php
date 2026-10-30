<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\RuntimeNoticePumpMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tests\Support\DiscardsErrorLogTrait;
use SugarCraft\Mouse\Zone;

/**
 * C-CTX-6.e: the memory-recall row is a structured, expandable transcript row.
 *
 * `announceMemoryRecall()` records a JSON datagram through the notice sink
 * instead of a count-only sentence; both pump sites turn that kind into a
 * {@see Message::recallNotice()} row (uiOnly, like every notice); and the
 * renderer folds it into the transcript's EXISTING click-to-expand affordance
 * — collapsed it reads as today's "💭 Recalled N notes" sentence plus the
 * "▸" hint, expanded it lists the recalled notes' titles (never bodies).
 *
 * The wire half is pinned in {@see \SugarCraft\Code\Tests\Diagnostics\RuntimeNoticeSinkTest};
 * the announce half in {@see \SugarCraft\Code\Tests\Context\MemoryRecallBlockTest}.
 * This file drives the middle: datagram in, row out, render collapsed and
 * expanded, and the legacy plain-string leg — an ordinary notice row must
 * still render exactly as before, and a resume from a pre-C-CTX-6.e
 * checkpoint (no titles key) must read back as the plain leg.
 */
final class RecallNoticeRowTest extends TestCase
{
    use DiscardsErrorLogTrait;

    private const CLICK_VARS = ['CANDY_CODE_DISABLE_MOUSE', 'CANDY_CODE_DISABLE_MOUSE_CLICKS'];

    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
        foreach (self::CLICK_VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        foreach (self::CLICK_VARS as $var) {
            putenv($var);
        }
        Renderer::scanner()->clear();
    }

    // =========================================================================
    // Datagram -> row
    // =========================================================================

    public function testARecallFrameRoundTripsThroughTheSinkIntoAStructuredRow(): void
    {
        RuntimeNoticeSink::arm(false);
        self::assertTrue(RuntimeNoticeSink::recordStructured(
            RuntimeNoticeSink::KIND_MEMORY_RECALL,
            ['count' => 2, 'titles' => ['staging notes freeze', 'the ship ledger']],
        ));

        $rows = RuntimeNoticeSink::drain();
        self::assertCount(1, $rows);
        $frame = RuntimeNoticeSink::decodeStructured($rows[0]);
        self::assertIsArray($frame);

        $row = Message::recallNotice($frame['count'], RuntimeNoticeSink::recallRowText($frame['count']), $frame['titles']);

        self::assertSame('💭 Recalled 2 notes for this turn', $row->content, 'the fallback sentence keeps the pre-C-CTX-6.e byte-shape');
        self::assertTrue($row->uiOnly, 'a notice is never agent-visible');
        self::assertSame(['staging notes freeze', 'the ship ledger'], $row->recallTitles);
        self::assertSame([], Message::agentVisible([$row]), 'and the row must never reach the model');
    }

    public function testThePumpTurnsAStructuredFrameIntoARecallRowAndLeavesPlainRowsAlone(): void
    {
        RuntimeNoticeSink::arm(false);
        RuntimeNoticeSink::beginTurn();
        self::assertTrue(RuntimeNoticeSink::recordStructured(
            RuntimeNoticeSink::KIND_MEMORY_RECALL,
            ['count' => 3, 'titles' => ['one', 'two', 'three']],
        ));
        self::assertTrue(RuntimeNoticeSink::record('an ordinary parser warning'));

        $chat = new Chat(drainsRuntimeNotices: true);
        [$next, $cmd] = $chat->update(new RuntimeNoticePumpMsg());

        self::assertNull($cmd);
        self::assertCount(2, $next->history);

        [$recall, $plain] = $next->history;
        self::assertSame(['one', 'two', 'three'], $recall->recallTitles);
        self::assertSame('💭 Recalled 3 notes for this turn', $recall->content);
        self::assertTrue($recall->uiOnly);
        self::assertSame([], $plain->recallTitles, 'an ordinary notice row stays an ordinary notice row');
        self::assertSame('an ordinary parser warning', $plain->content);
    }

    public function testAMalformedFrameIsDroppedRatherThanRowedAsGarbage(): void
    {
        RuntimeNoticeSink::arm(false);
        RuntimeNoticeSink::beginTurn();
        self::assertTrue(RuntimeNoticeSink::record(RuntimeNoticeSink::STRUCTURED_PREFIX . '{not json at all'));

        $chat = new Chat(drainsRuntimeNotices: true);
        [$next] = $chat->update(new RuntimeNoticePumpMsg());

        self::assertCount(0, $next->history, 'a reserved-prefix row the decoder refuses must add NOTHING, not a garbage line');
    }

    // =========================================================================
    // Persistence: only-when-set, tolerant read-back
    // =========================================================================

    public function testARecallRowPersistsAndResumesWithItsTitles(): void
    {
        $row = Message::recallNotice(2, RuntimeNoticeSink::recallRowText(2), ['staging notes freeze', 'the ship ledger'], 1700000000);

        $blob = $row->jsonSerialize();
        self::assertSame(['staging notes freeze', 'the ship ledger'], $blob['recallTitles'] ?? null);

        $resumed = Message::fromArray($blob);
        self::assertSame($row->recallTitles, $resumed->recallTitles);
        self::assertSame($row->content, $resumed->content);
        self::assertTrue($resumed->uiOnly);
    }

    public function testAPlainNoticeRowSerializesNoTitlesKeyAndReadsBackEmpty(): void
    {
        $blob = Message::notice('an ordinary notice')->jsonSerialize();
        self::assertArrayNotHasKey('recallTitles', $blob, 'only-when-set keeps pre-C-CTX-6.e checkpoints byte-stable');

        // A hostile or drifted blob claiming titles that are not strings must
        // still read back as the plain leg, never a recall row half-built.
        $resumed = Message::fromArray(['role' => 'system', 'content' => 'x', 'uiOnly' => true, 'recallTitles' => ['ok', 7, null]]);
        self::assertSame(['ok'], $resumed->recallTitles);
    }

    // =========================================================================
    // Render: collapsed, expanded through the existing affordance, legacy
    // =========================================================================

    public function testTheCollapsedRecallRowReadsAsTheSentencePlusTheExpandHintAndKeepsTitlesOut(): void
    {
        $row = Message::recallNotice(3, RuntimeNoticeSink::recallRowText(3), ['staging notes freeze', 'the ship ledger', 'quota arithmetic']);
        $frame = Renderer::render(new Chat(history: [$row]));

        self::assertStringContainsString('Recalled 3 notes for this turn', $frame);
        self::assertStringContainsString('▸', $frame);
        self::assertStringNotContainsString('ship ledger', $frame, 'collapsed must not leak the titles it exists to hide');
    }

    public function testTheRecallRowIsZonedUnderTheSameToolCallAffordanceAndExpandsOnClick(): void
    {
        $row = Message::recallNotice(3, RuntimeNoticeSink::recallRowText(3), ['staging notes freeze', 'the ship ledger', 'quota arithmetic']);
        $chat = new Chat(history: [$row]);

        Renderer::render($chat);
        $zone = Renderer::scanner()->get('toolcall:' . Renderer::recallKey($row));
        self::assertInstanceOf(Zone::class, $zone, 'the row must reuse the tool-call click zone, keyed by its recall id');

        $expanded = $chat->toggleToolOutput(Renderer::recallKey($row));
        self::assertNotSame($chat, $expanded);

        $frame = Renderer::render($expanded);
        self::assertStringContainsString('▾', $frame);
        self::assertStringContainsString('staging notes freeze', $frame);
        self::assertStringContainsString('quota arithmetic', $frame);
    }

    public function testARecallRowWithoutTitlesRendersAsAnOrdinaryNoticeRow(): void
    {
        // The legacy leg: a plain '💭 Recalled N notes' row — what a resumed
        // pre-C-CTX-6.e session, or any other producer's sentence — keeps the
        // old notice rendering untouched.
        $legacy = Message::notice('💭 Recalled 2 notes for this turn');
        $frame = Renderer::render(new Chat(history: [$legacy]));

        self::assertStringContainsString('Recalled 2 notes for this turn', $frame);
        self::assertStringNotContainsString('▸', $frame);
        self::assertNull(Renderer::scanner()->get('toolcall:' . Renderer::recallKey($legacy)), 'no zone on a row that carries no titles');
    }

    public function testWithClicksDisabledTheCollapsedRowStillRendersWithoutAZone(): void
    {
        putenv('CANDY_CODE_DISABLE_MOUSE_CLICKS=1');
        try {
            $row = Message::recallNotice(2, RuntimeNoticeSink::recallRowText(2), ['staging notes freeze', 'the ship ledger']);
            $frame = Renderer::render(new Chat(history: [$row]));

            self::assertStringContainsString('Recalled 2 notes for this turn', $frame);
            self::assertNull(Renderer::scanner()->get('toolcall:' . Renderer::recallKey($row)), 'the zone rides mouseClicksEnabled like every other click affordance');
        } finally {
            putenv('CANDY_CODE_DISABLE_MOUSE_CLICKS');
        }
    }
}

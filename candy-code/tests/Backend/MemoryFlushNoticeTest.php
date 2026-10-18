<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Lang;

/**
 * ISS-070, the display half: a pre-compaction memory flush names what it
 * saved on the transcript seam instead of vanishing silently.
 *
 * {@see \SugarCraft\Code\Context\Compaction\MemoryFlush::run()} has returned
 * one Memory-tool result sentence per saved note since 6ea7a5a2c, but both
 * EngineBackend flush callers dropped the list. They now hand it to the
 * engine's private surface, which records one {@see RuntimeNoticeSink::warn}
 * row — the seam the host drains into the conversation and audit 15b-03
 * keeps UI-only.
 *
 * The pins drive the surface directly (it is a pure function of the row
 * list) and pin the two call sites by source scan, so a future refactor that
 * drops one caller's surfacing goes red.
 */
final class MemoryFlushNoticeTest extends TestCase
{
    private string $errorLog = '';
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(false);
        // warn() copies every row to error_log() as well as the seam (the
        // copy skips only the sink's own MAX_CHARS clip). Redirect it to a
        // file, house pattern of RuntimeNoticeSinkTest, so the suite output
        // stays clean and the copy is readable for the pair pin.
        $this->errorLog = sys_get_temp_dir() . '/gapeb_flush_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.log';
        $this->previousLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== false) {
            ini_set('error_log', $this->previousLog);
        }
        @unlink($this->errorLog);
        RuntimeNoticeSink::reset();
    }

    public function testNothingIsRecordedWhenTheFlushSavedNoRows(): void
    {
        $this->gapebSurface([]);

        self::assertSame([], RuntimeNoticeSink::drain(), 'an empty flush stays silent');
    }

    public function testOneSavedNoteIsNamedInFullOnTheNoticeSeam(): void
    {
        $row = 'Saved note ab12cd34 (scope: project, /tmp/notes/memory.json).';
        $this->gapebSurface([$row]);

        $drained = RuntimeNoticeSink::drain();
        self::assertCount(1, $drained);
        self::assertSame(
            Lang::t('chat.memory.flushed_notice', ['count' => 1, 'notes' => 'note', 'rows' => $row]),
            $drained[0],
            'the row is the catalogue sentence with the note named',
        );
        self::assertStringContainsString('saved 1 note: ', $drained[0]);
        self::assertStringContainsString($row, $drained[0]);
    }

    public function testSeveralRowsRideOneNoticeWithThePlural(): void
    {
        $this->gapebSurface([
            'Saved note one (scope: global, /tmp/a).',
            'Saved note two (scope: project, /tmp/b).',
        ]);

        $drained = RuntimeNoticeSink::drain();
        self::assertCount(1, $drained, 'one flush, one row on the seam');
        self::assertStringContainsString('saved 2 notes: ', $drained[0]);
        self::assertStringContainsString('/tmp/a', $drained[0]);
        self::assertStringContainsString('/tmp/b', $drained[0]);
    }

    public function testMoreThanThreeRowsCollapseIntoAnExplicitCount(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = 'Saved note ' . $i . ' (scope: global, /tmp/flashow-' . $i . ').';
        }
        $this->gapebSurface($rows);

        $drained = RuntimeNoticeSink::drain();
        self::assertCount(1, $drained);
        self::assertStringContainsString('saved 5 notes: ', $drained[0], 'the count names all five even though three are shown');
        self::assertStringContainsString('/tmp/flashow-1', $drained[0]);
        self::assertStringContainsString('/tmp/flashow-3', $drained[0]);
        self::assertStringContainsString('and 2 more', $drained[0]);
        self::assertStringNotContainsString('/tmp/flashow-4', $drained[0], 'the overflow collapses into the count, not the text');

        // The collapse is built into the row before warn() sees it, so the
        // error_log copy carries the SAME text the seam shows (the copy is
        // only un-clipped by the sink's own MAX_CHARS, which these rows do
        // not reach). Pinning the pair keeps both halves of warn() honest.
        $logged = file_get_contents($this->errorLog);
        self::assertNotFalse($logged);
        self::assertStringContainsString($drained[0], $logged, 'the log copy carries the same collapsed row the seam shows');
    }

    public function testBothFlushCallersCaptureAndSurface(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Backend/EngineBackend.php');
        self::assertNotFalse($source);
        self::assertSame(
            2,
            substr_count($source, '$saved = \SugarCraft\Code\Context\Compaction\MemoryFlush::run('),
            'both flush callers capture the saved-row list (in-turn StepOverBudget and summaryReply)',
        );
        self::assertSame(
            2,
            substr_count($source, 'self::surfaceMemoryFlushRows($saved);'),
            'and both hand it to the seam — a dropped caller goes red here',
        );
    }

    // =========================================================================

    /**
     * @param list<string> $rows
     */
    private function gapebSurface(array $rows): void
    {
        $surface = new \ReflectionMethod(EngineBackend::class, 'surfaceMemoryFlushRows');
        $surface->invoke(null, $rows);
    }
}

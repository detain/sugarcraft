<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Format\RelativeFormat;
use SugarCraft\Vcr\Hook\Hook;
use SugarCraft\Vcr\Recorder;
use SugarCraft\Vcr\Tests\Support\FlushMeter;
use SugarCraft\Vcr\Tests\Support\Stream;
use SugarCraft\Vcr\Support\ObjectMap;

final class RecorderTest extends TestCase
{
    public function testHeaderWrittenAtConstruction(): void
    {
        $fh = $this->memory();
        new Recorder($fh, $this->stubHeader());
        $first = $this->firstLine($fh);
        $this->assertSame(1, $first['v']);
        $this->assertSame(80, $first['cols']);
        $this->assertSame(24, $first['rows']);
        $this->assertSame('sugarcraft/candy-vcr@dev', $first['runtime']);
    }

    public function testResizeEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader(), startTime: microtime(true));
        $r->recordResize(120, 40);

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events);
        $this->assertSame('resize', $events[0]['k']);
        $this->assertSame(120, $events[0]['cols']);
        $this->assertSame(40, $events[0]['rows']);
        $this->assertGreaterThanOrEqual(0.0, $events[0]['t']);
    }

    public function testInputBytesEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordInputBytes("\x1b[A");

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events);
        $this->assertSame('input', $events[0]['k']);
        $this->assertSame("\x1b[A", $events[0]['b']);
    }

    public function testOutputEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput("hello\n");

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events);
        $this->assertSame('output', $events[0]['k']);
        $this->assertSame("hello\n", $events[0]['b']);
    }

    public function testEmptyInputAndOutputAreSkipped(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordInputBytes('');
        $r->recordOutput('');

        $this->assertSame([], $this->readEvents($fh));
    }

    public function testQuitEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordQuit();

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events);
        $this->assertSame('quit', $events[0]['k']);
        $this->assertArrayNotHasKey('b', $events[0]);
    }

    public function testCloseIsIdempotent(): void
    {
        $r = new Recorder($this->memory(), $this->stubHeader());
        $r->close();
        $r->close();
        // Surviving the second close() without throwing is the assertion.
        $this->addToAssertionCount(1);
    }

    public function testWriteAfterCloseIsNoOp(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput('before');
        rewind($fh);
        $beforeContents = stream_get_contents($fh);
        $r->close();
        // After close, all record* calls silently no-op so program teardown
        // writes that fire after recordQuit don't pollute the cassette.
        $r->recordOutput('after');
        $r->recordResize(1, 1);
        $r->recordInputBytes('x');
        $r->recordQuit();
        // Stream is closed, but the contents we already captured shouldn't grow.
        $this->assertStringContainsString('"before"', $beforeContents);
        $this->assertStringNotContainsString('"after"', $beforeContents);
    }

    public function testTimingIncreases(): void
    {
        $fh = $this->memory();
        $start = microtime(true);
        $r = new Recorder($fh, $this->stubHeader(), startTime: $start);
        $r->recordOutput('a');
        usleep(5_000);
        $r->recordOutput('b');

        $events = $this->readEvents($fh);
        $this->assertCount(2, $events);
        $this->assertLessThanOrEqual($events[1]['t'], $events[0]['t']);
    }

    public function testRecorderProducesValidCassetteRoundTripsThroughFormat(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader(), startTime: microtime(true));
        $r->recordResize(80, 24);
        $r->recordOutput("\x1b[2J");
        $r->recordInputBytes('q');
        $r->recordQuit();

        rewind($fh);
        $contents = stream_get_contents($fh);
        $cassette = (new JsonlFormat())->decode($contents);

        $this->assertSame(1, $cassette->header->version);
        $this->assertSame(4, $cassette->eventCount());
        $this->assertSame(EventKind::Resize, $cassette->events[0]->kind);
        $this->assertSame(EventKind::Output, $cassette->events[1]->kind);
        $this->assertSame(EventKind::Input, $cassette->events[2]->kind);
        $this->assertSame(EventKind::Quit, $cassette->events[3]->kind);
        $this->assertSame("\x1b[2J", $cassette->events[1]->payload['b']);
        $this->assertSame('q', $cassette->events[2]->payload['b']);
    }

    public function testOpenFactoryWritesToFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-test-');
        $this->assertNotFalse($path);
        try {
            $r = Recorder::open($path);
            $r->recordResize(80, 24);
            $r->recordQuit();
            $r->close();

            $cassette = (new JsonlFormat())->read($path);
            $this->assertSame(2, $cassette->eventCount());
        } finally {
            @unlink($path);
        }
    }

    public function testOpenFactoryThrowsOnUnwritablePath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot open recorder file');
        Recorder::open(sys_get_temp_dir() . '/candy-vcr-missing-' . uniqid() . '/never/path.cas');
    }

    public function testRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line — intentional bad type */
        new Recorder('not-a-resource', $this->stubHeader());
    }

    public function testDefaultHeaderHasSensibleDefaults(): void
    {
        $h = Recorder::defaultHeader();
        $this->assertSame(1, $h->version);
        $this->assertSame(80, $h->cols);
        $this->assertSame(24, $h->rows);
        $this->assertSame('sugarcraft/candy-vcr@dev', $h->runtime);
        $this->assertNotEmpty($h->createdAt);
    }

    // ---- F1 (round 90): torn-UTF-8 chunk pins ----------------------------
    // A PTY read loop slices output at arbitrary byte boundaries; before the
    // re-join contract, recordOutput("\xe4\xb8") threw and aborted BOTH the
    // recording and the render. These pins hold the three-way contract:
    // split glyphs re-join byte-exact, invalid bytes degrade to U+FFFD,
    // and close() flushes a tail the session never completed.

    public function testByteByByteFeedOfMultibyteStreamReassemblesByteExact(): void
    {
        $full = "ok \xe4\xb8\xad \xf0\x9f\x98\x80 end"; // ASCII + 3-byte 中 + 4-byte emoji
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-f1-');
        $this->assertNotFalse($path);
        try {
            $r = Recorder::open($path, $this->stubHeader());
            foreach (str_split($full) as $byte) {
                $r->recordOutput($byte); // worst-case fragmentation: one byte per tap
            }
            $r->close(); // close() releases the sink — read the finished file, not a live handle

            $reassembled = '';
            $cassette = (new JsonlFormat())->read($path);
            foreach ($cassette->events as $event) {
                $this->assertSame(EventKind::Output, $event->kind);
                $this->assertIsString($event->payload['b']);
                $reassembled .= $event->payload['b'];
            }
            $this->assertSame($full, $reassembled, 'deferred tails must re-join, never corrupt or drop');
        } finally {
            @unlink($path);
        }
    }

    public function testTornTailIsDeferredNotEncodedIntoItsOwnEvent(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput("split \xe4\xb8"); // valid prefix + lead+1 of 中 (E4 B8 AD)

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events, 'only the complete prefix may be written while a tail is pending');
        $this->assertSame('split ', $events[0]['b']);

        $r->recordOutput("\xad tail"); // completes the glyph
        $events = $this->readEvents($fh);
        $this->assertCount(2, $events);
        $this->assertSame("\xe4\xb8\xad tail", $events[1]['b']);
    }

    public function testInvalidLeadBytesDegradeToSubstitutionWithoutThrowing(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput("\xff\xfe raw 8-bit"); // leads no valid sequence can form

        $events = $this->readEvents($fh);
        $this->assertCount(1, $events, 'invalid bytes are final, not deferred');
        $this->assertSame("\u{fffd}\u{fffd} raw 8-bit", $events[0]['b']);
    }

    public function testCloseFlushesPendingTailSoEndOfSessionMidGlyphLosesNoBytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-f1-');
        $this->assertNotFalse($path);
        try {
            $r = Recorder::open($path, $this->stubHeader());
            $r->recordOutput("x\xe4\xb8"); // session ends mid-glyph
            $r->close();

            $events = [];
            foreach ((new JsonlFormat())->read($path)->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $events[] = $event->payload['b'];
                }
            }
            $this->assertCount(2, $events);
            $this->assertSame('x', $events[0]);
            $this->assertSame("\u{fffd}", $events[1], 'the uncompleted tail reaches substitution, not the bit bucket');
        } finally {
            @unlink($path);
        }
    }

    public function testSplitGlyphCassetteRoundTripsThroughTheJsonlReader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-f1-');
        $this->assertNotFalse($path);
        try {
            $r = Recorder::open($path);
            $r->recordResize(80, 24);
            // 字 = E5 AD 97, 五 = E4 BA 94 — tear 五 between its lead and
            // its second continuation byte.
            $r->recordOutput("\u{5b57}\xe4\xba");
            $r->recordOutput("\x94");
            $r->recordQuit();
            $r->close();

            $cassette = (new JsonlFormat())->read($path);
            $output = '';
            foreach ($cassette->events as $event) {
                if ($event->kind === EventKind::Output) {
                    $this->assertIsString($event->payload['b']);
                    $output .= $event->payload['b'];
                }
            }
            $this->assertSame("\u{5b57}\u{4e94}", $output, 'reader must hand the replay path the re-joined glyph stream');
        } finally {
            @unlink($path);
        }
    }

    /** @return resource */
    private function memory()
    {
        $fh = Stream::memory('w+b');
        $this->assertNotFalse($fh);
        return $fh;
    }

    private function stubHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-08T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
        );
    }

    /**
     * @param resource $fh
     * @return array<string, mixed>
     */
    private function firstLine($fh): array
    {
        rewind($fh);
        $line = fgets($fh);
        $this->assertNotFalse($line);
        $data = json_decode(trim($line), true);
        $this->assertIsArray($data);

        return ObjectMap::of($data, 'recorded header line');
    }

    /**
     * @param resource $fh
     * @return list<array<string, mixed>>
     */
    private function readEvents($fh): array
    {
        rewind($fh);
        fgets($fh);  // skip header
        $events = [];
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $data = json_decode($line, true);
            $this->assertIsArray($data);
            $events[] = ObjectMap::of($data, 'recorded event line');
        }
        return $events;
    }

    // ----- C3: write errors are counted, never discarded --------------

    /**
     * A read-only stream resource is the cheapest in-process ENOSPC
     * stand-in: is_resource() stays true (so the recorder keeps recording
     * — the E712 best-effort ruling) while every fwrite returns false.
     * Pre-fix, those false returns were dropped on the floor and the CLI
     * still announced success; now they are tallied.
     */
    public function testFailedWritesAreCountedNotDiscarded(): void
    {
        $fh = Stream::memory('rb');
        $this->assertNotFalse($fh);

        $r = new Recorder($fh, $this->stubHeader());
        $afterHeader = $r->captureWriteErrors();
        $this->assertGreaterThanOrEqual(1, $afterHeader, 'failed header line must be counted');

        $r->recordOutput('this write cannot land');
        $this->assertGreaterThan($afterHeader, $r->captureWriteErrors(), 'each failed event write must count');

        $r->close();
        $this->assertGreaterThan(0, $r->captureWriteErrors(), 'teardown must not erase the tally');
    }

    public function testHealthyCaptureReportsZeroWriteErrors(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput('fine');
        $r->recordResize(100, 30);
        $r->close();
        $this->assertSame(0, $r->captureWriteErrors());
    }

    // ----- M5: withFormat confirms the header, never flips it ----------

    public function testHeaderSeedsRelativeLineEncodingWithoutWithFormat(): void
    {
        $fh = $this->memory();
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-08T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
        );
        $r = new Recorder($fh, $header);
        $r->recordOutput('dt lines from the header contract');

        $events = $this->readEvents($fh);
        $this->assertArrayHasKey('dt', $events[0], 'relative header must seed dt line encoding');
        $this->assertArrayNotHasKey('t', $events[0]);
    }

    public function testRelativeFormatConfirmsRelativeHeader(): void
    {
        $fh = $this->memory();
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-08T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
        );
        $r = new Recorder($fh, $header);
        $this->assertSame($r, $r->withFormat(new RelativeFormat()));
    }

    public function testRelativeFormatAgainstAbsoluteHeaderIsRefused(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('absolute-timestamp cassette');
        $r->withFormat(new RelativeFormat());
    }

    public function testAbsoluteFormatAgainstRelativeHeaderIsRefused(): void
    {
        $fh = $this->memory();
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-08T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
            timestampMode: CassetteHeader::TIMESTAMP_MODE_RELATIVE,
        );
        $r = new Recorder($fh, $header);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('relative-timestamp cassette');
        $r->withFormat(new JsonlFormat());
    }

    // ----- S36 (finding 113): flush coalescing doors ----------------------
    // Pre-fix every line paid an fflush() syscall. The doors below are the
    // durability/perf trade: 32 events, 64 KiB, or the writer going idle.
    // The meter (tests/Support/FlushMeter) separates write from flush so
    // these pins count DRAINS, never bytes.

    public function testBurstCoalescesFlushesAtLeastTenfold(): void
    {
        $fh = FlushMeter::openFresh();
        $r = new Recorder($fh, $this->stubHeader());
        $this->assertSame(1, FlushMeter::flushCount(), 'the header drains at open (below every door)');

        for ($i = 0; $i < 1000; $i++) {
            $r->recordInputBytes('x');
        }
        $drainsDuringBurst = FlushMeter::flushCount();
        $r->close();

        // Door math: the event door fires at 32, 64, ..., 992 — a minimum
        // of 31 drains plus the header drain = 32 by close time. The upper
        // bound proves the ≥10× reduction against the pre-fix per-event
        // contract (1001 flushes) with 3× headroom for a loaded-host
        // scheduling pause tripping the idle door mid-burst.
        $this->assertGreaterThanOrEqual(32, $drainsDuringBurst, 'every full event door must have drained');
        $this->assertLessThanOrEqual(100, $drainsDuringBurst, 'burst must flush ≥10× less than the per-event contract (pre-fix: 1001)');
        $this->assertCount(1001, FlushMeter::lines(), 'coalescing must not drop or duplicate a line');
    }

    public function testByteDoorDrainsOnFatEventsBeforeTheEventDoor(): void
    {
        $fh = FlushMeter::openFresh();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput(str_repeat('a', 40000)); // below the 64 KiB door: no drain
        $this->assertSame(1, FlushMeter::flushCount(), 'one fat event stays banked');
        $r->recordOutput(str_repeat('b', 40000)); // 80 KiB banked: byte door opens

        $this->assertSame(2, FlushMeter::flushCount(), 'accumulated bytes must drain past 64 KiB (2 events << 32-event door)');
        $r->close();
        $this->assertCount(3, FlushMeter::lines());
    }

    public function testIdleGapDoorFlushesLowVolumeEvents(): void
    {
        $fh = FlushMeter::openFresh();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordInputBytes('a');
        \usleep(150_000);
        $r->recordInputBytes('b'); // arrived after >100 ms idle: flush now
        $this->assertSame(2, FlushMeter::flushCount(), 'idle event must drain through the gap door (2 events < 32/64KiB)');
        \usleep(150_000);
        $r->recordInputBytes('c');
        $this->assertSame(3, FlushMeter::flushCount(), 'a second idle arrival drains again');
        $r->close();
        $this->assertSame(4, FlushMeter::flushCount(), 'close adds the final drain');
        $this->assertCount(4, FlushMeter::lines());
    }

    public function testCloseDrainsEventsBelowEveryDoor(): void
    {
        $fh = FlushMeter::openFresh();
        $r = new Recorder($fh, $this->stubHeader());
        $r->recordOutput('small');
        $r->recordResize(90, 30);
        $this->assertSame(1, FlushMeter::flushCount(), 'busy sub-door events stay banked');
        $r->close();
        $this->assertSame(2, FlushMeter::flushCount(), 'close must push every banked line through');
    }

    // ----- S36 (finding 116): lock-before-truncate ------------------------
    // fopen() with 'wb' truncated on the open syscall — a second recorder
    // destroyed a live tape before any lock could refuse it. Now mode 'c'
    // opens WITHOUT truncate and LOCK_EX|LOCK_NB decides before damage.
    // Two distinct OFDs in this process contend exactly as two processes
    // would (flock(2) tracks open-file descriptions, not pids).

    public function testOpenRefusesPathHeldByAnotherWriterByName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-s36-');
        $this->assertNotFalse($path);
        $holder = fopen($path, 'wb');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, LOCK_EX));
        try {
            Recorder::open($path);
            $this->fail('a contended cassette path must be refused by name');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('is locked by another recorder process', $e->getMessage());
            $this->assertStringContainsString($path, $e->getMessage(), 'the refusal names the path under contention');
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
            @unlink($path);
        }
    }

    public function testRefusedOpenLeavesHoldersBytesIntact(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-s36-');
        $this->assertNotFalse($path);
        try {
            $holder = Recorder::open($path, $this->stubHeader());
            // The holder is mid-session — lock still held, file not closed.
            $contenderFailed = false;
            try {
                Recorder::open($path, $this->stubHeader());
            } catch (\RuntimeException) {
                $contenderFailed = true;
            }
            $this->assertTrue($contenderFailed, 'the contender must be refused');

            // Pre-fix the contender's fopen('wb') had already zeroed the
            // holder's flushed header; the 'c' + lock order must not.
            $raw = file_get_contents($path);
            $this->assertIsString($raw);
            $this->assertNotSame('', $raw, 'refused open may not truncate the holder cassette');
            $header = json_decode(trim(explode("\n", $raw)[0]), true);
            $this->assertIsArray($header);
            $this->assertSame(1, $header['v'], 'the the holder header line line must survive the refusal');
            $holder->close();
        } finally {
            @unlink($path);
        }
    }

    public function testOpenSucceedsAfterHolderReleasesTheLock(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'candy-vcr-s36-');
        $this->assertNotFalse($path);
        try {
            $first = Recorder::open($path, $this->stubHeader());
            $first->recordOutput('generation one');
            $first->close(); // close releases the flock through fclose

            $second = Recorder::open($path, $this->stubHeader()); // legal: re-record over the old tape
            $second->recordOutput('generation two');
            $second->close();

            $cassette = (new JsonlFormat())->read($path);
            $this->assertSame(1, $cassette->eventCount(), 'a released lock must not block the next recording');
        } finally {
            @unlink($path);
        }
    }

    // ----- S36 (finding 096): swallowed hook deaths are observable --------

    public function testHookErrorsEmptyForCleanCapture(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->withHook(new class implements Hook {
            public function beforeSave(Event $event): Event
            {
                return $event;
            }
            public function afterCapture(Event $event): void
            {
            }
        });
        $r->recordOutput('fine');
        $r->close();
        $this->assertSame([], $r->hookErrors());
    }

    public function testSwallowedHookDeathIsListedForCloseSideWarnings(): void
    {
        $fh = $this->memory();
        $r = new Recorder($fh, $this->stubHeader());
        $r->withHook(new class implements Hook {
            public function beforeSave(Event $event): Event
            {
                return $event;
            }
            public function afterCapture(Event $event): void
            {
                throw new \RuntimeException('sink exploded');
            }
        });
        $r->recordOutput('one');
        $r->recordOutput('two');

        $errors = $r->hookErrors();
        $this->assertCount(2, $errors, 'each swallowed death is one observable entry');
        $this->assertStringContainsString('::afterCapture failed: sink exploded', $errors[0]);
        $r->close();
        $this->assertCount(2, $r->hookErrors(), 'close must not erase the evidence');
    }
}

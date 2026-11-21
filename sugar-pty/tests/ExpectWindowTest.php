<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Recorder;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Expect;
use SugarCraft\Pty\ExpectWindow;

/**
 * {@see Expect::collectWindow()} is the non-throwing window engine behind a
 * scripted PTY session: it reports a match, retains bytes under a session-wide
 * cap, counts what the cap refused, cuts UTF-8 safely, and clamps every read to
 * a wall deadline. Each case pins one edge of that contract directly.
 */
final class ExpectWindowTest extends TestCase
{
    /**
     * A master that serves the scripted chunks in order. A null chunk, or an
     * empty script, is a read that times out after honoring the tick, so the
     * deadline arithmetic gets real wall time to measure.
     *
     * @param list<?string> $chunks
     */
    private static function master(array $chunks): MasterPty
    {
        return new class ($chunks) implements MasterPty {
            /** @param list<?string> $chunks */
            public function __construct(private array $chunks)
            {
            }

            public function read(int $len = 8192, ?float $timeout = 0.05): ?string
            {
                $chunk = $this->chunks === [] ? null : \array_shift($this->chunks);
                if ($chunk === null && $timeout !== null) {
                    \usleep((int) ($timeout * 1_000_000));
                }

                return $chunk;
            }

            public function write(string $bytes): int
            {
                return \strlen($bytes);
            }

            public function resize(int $cols, int $rows): void
            {
            }

            public function size(): array
            {
                return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
            }

            public function stream(): mixed
            {
                throw new \LogicException('window master has no real stream');
            }

            public function setBlocking(bool $blocking): void
            {
            }

            public function close(): void
            {
            }

            public function isClosed(): bool
            {
                return false;
            }

            public function fd(): int
            {
                return -1;
            }
        };
    }

    private static function child(bool $exited): Child
    {
        return new class ($exited) implements Child {
            public function __construct(private bool $exited)
            {
            }

            public function pid(): int
            {
                return 1;
            }

            public function exited(): bool
            {
                return $this->exited;
            }

            public function wait(): int
            {
                return 0;
            }

            public function exitCode(): ?int
            {
                return 0;
            }

            public function kill(int $signal): void
            {
            }
        };
    }

    /** A deadline far enough away that only the window length can end the window. */
    private static function farDeadline(): float
    {
        return \microtime(true) + 30.0;
    }

    /** A recorder that appends every output byte it is handed to the shared sink. */
    private static function capturingRecorder(\ArrayObject $sink): Recorder
    {
        return new class ($sink) implements Recorder {
            public function __construct(private \ArrayObject $sink)
            {
            }

            public function recordResize(int $cols, int $rows): void
            {
            }

            public function recordInputBytes(string $bytes): void
            {
            }

            public function recordOutput(string $bytes): void
            {
                $this->sink->append($bytes);
            }

            public function recordQuit(): void
            {
            }

            public function close(): void
            {
            }
        };
    }

    // ----------------------------------------------------------- retention cap

    public function testCollectsEverythingUnderTheCapAndClosesOnTheWindow(): void
    {
        $result = Expect::on(self::master(['abc', 'def']))
            ->collectWindow(0.2, self::farDeadline(), 100);

        self::assertSame('abcdef', $result->output);
        self::assertSame(6, $result->retained);
        self::assertSame(0, $result->dropped);
        self::assertNull($result->matched, 'no regex was asked for, so no match verdict');
        self::assertFalse($result->wallHit, 'the window closed on its length, not the deadline');
        self::assertFalse($result->childExited);
    }

    public function testCapStopsRetentionAndCountsTheRefusedBytes(): void
    {
        // Cap 8: the first chunk fits (6), the second keeps only its first 2 bytes.
        $result = Expect::on(self::master(['abcdef', 'ghij']))
            ->collectWindow(0.2, self::farDeadline(), 8);

        self::assertSame('abcdefgh', $result->output);
        self::assertSame(8, $result->retained);
        self::assertSame(2, $result->dropped, 'the two refused bytes must be counted, never lost');
    }

    public function testTheSessionTotalCarriesIntoTheNextWindow(): void
    {
        // The running session total is 5 of a 6-byte cap with nothing dropped yet, so
        // one byte of room is left for this window's 3-byte chunk: keep 'x', drop 'yz'.
        // A session that has already dropped a byte has ended its transcript; that
        // case is pinned by testRoomLeftByACutIsNotSpentInTheNextWindow.
        $result = Expect::on(self::master(['xyz']))
            ->collectWindow(0.2, self::farDeadline(), 6, 5, 0);

        self::assertSame('x', $result->output);
        self::assertSame(6, $result->retained, 'the budget is session-wide, not per window');
        self::assertSame(2, $result->dropped, 'the bytes past the session cap are counted as dropped');
    }

    public function testAFullBudgetDropsTheWholeChunk(): void
    {
        $result = Expect::on(self::master(['abcdef']))
            ->collectWindow(0.2, self::farDeadline(), 4, 4, 0);

        self::assertSame('', $result->output);
        self::assertSame(4, $result->retained);
        self::assertSame(6, $result->dropped);
    }

    // ---------------------------------------------------------------- UTF-8 cut

    public function testTheCapCutNeverTearsACodepoint(): void
    {
        // 'a' + 你 + 你 is 7 bytes. A cap of 3 would tear the first glyph; the
        // cut must back off to the lone 'a' and count the six bytes it gives up.
        $result = Expect::on(self::master(["a\u{4F60}\u{4F60}"]))
            ->collectWindow(0.2, self::farDeadline(), 3);

        self::assertSame('a', $result->output);
        self::assertTrue(\mb_check_encoding($result->output, 'UTF-8'), 'the retained output must be valid UTF-8');
        self::assertSame(1, $result->retained);
        self::assertSame(6, $result->dropped);
    }

    public function testACompleteGlyphOnTheCapEdgeIsKept(): void
    {
        // 'a' + 你 is exactly 4 bytes: a cap of 4 keeps the whole glyph.
        $result = Expect::on(self::master(["a\u{4F60}\u{4F60}"]))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertSame("a\u{4F60}", $result->output);
        self::assertSame(4, $result->retained);
        self::assertSame(3, $result->dropped);
    }

    public function testATwoByteGlyphSeveredAtTheCapIsCutWhole(): void
    {
        // 'x' + é is 3 bytes; a cap of 2 would tear the two-byte é, so only 'x' survives.
        $result = Expect::on(self::master(["x\u{e9}"]))
            ->collectWindow(0.2, self::farDeadline(), 2);

        self::assertSame('x', $result->output);
        self::assertTrue(\mb_check_encoding($result->output, 'UTF-8'));
        self::assertSame(1, $result->retained);
        self::assertSame(2, $result->dropped);
    }

    public function testAWholeTwoByteGlyphOnTheCapEdgeIsKept(): void
    {
        // 'x' + é (C3 A9) + 'b' is 4 bytes; a cap of 3 ends exactly after é. The C3
        // lead declares two bytes and both are present, so nothing is backed off. A
        // wider two-byte lead width would back the cut off to 'x' and fail here.
        $result = Expect::on(self::master(["x\xC3\xA9b"]))
            ->collectWindow(0.2, self::farDeadline(), 3);

        self::assertSame("x\xC3\xA9", $result->output);
        self::assertSame(3, $result->retained);
        self::assertSame(1, $result->dropped, 'only the trailing b is refused by the cap');
    }

    public function testAFourByteGlyphSeveredAtTheCapIsCutWhole(): void
    {
        // 'a' + U+1F600 is 5 bytes. Caps 2 and 3 both split the four-byte glyph, so
        // the cut must back off to the lone 'a' and never retain a torn F0 lead.
        foreach ([2, 3] as $cap) {
            $result = Expect::on(self::master(["a\u{1F600}"]))
                ->collectWindow(0.2, self::farDeadline(), $cap);

            self::assertSame('a', $result->output, "cap {$cap} must not retain a torn four-byte lead");
            self::assertTrue(\mb_check_encoding($result->output, 'UTF-8'));
            self::assertSame(1, $result->retained);
            self::assertSame(4, $result->dropped);
        }
    }

    public function testAWholeFourByteGlyphOnTheCapEdgeIsKept(): void
    {
        // 'a' + U+1F600 is exactly 5 bytes: a cap of 5 keeps the whole glyph.
        $result = Expect::on(self::master(["a\u{1F600}"]))
            ->collectWindow(0.2, self::farDeadline(), 5);

        self::assertSame("a\u{1F600}", $result->output);
        self::assertSame(5, $result->retained);
        self::assertSame(0, $result->dropped);
    }

    public function testTheCapStopsOnAnAsciiBoundaryWithoutBackingOff(): void
    {
        // ASCII needs no codepoint backing off: a cap of 2 keeps exactly 'ab'.
        $result = Expect::on(self::master(['abcd']))
            ->collectWindow(0.2, self::farDeadline(), 2);

        self::assertSame('ab', $result->output);
        self::assertSame(2, $result->retained);
        self::assertSame(2, $result->dropped);
    }

    public function testTheSessionTotalBacksOffAMultiByteGlyphInTheNextWindow(): void
    {
        // The session already holds 3 of a 5-byte cap, so 2 bytes of room remain.
        // A 3-byte glyph cannot fit whole, so none of it may be retained.
        $result = Expect::on(self::master(["\u{4F60}"]))
            ->collectWindow(0.2, self::farDeadline(), 5, 3, 0);

        self::assertSame('', $result->output);
        self::assertSame(3, $result->retained);
        self::assertSame(3, $result->dropped);
    }

    public function testATornGlyphThatFillsTheCapIsRetainedAsAPrefixOfTheStream(): void
    {
        // The first read ends mid-glyph and fills the cap exactly. It fits whole, so
        // it is retained as read; the second read is refused in full. The transcript
        // stays a prefix of the child's stream and never gains bytes it did not emit.
        $result = Expect::on(self::master(["ab\xE4\xBD", "\xA0cd"]))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertSame("ab\xE4\xBD", $result->output);
        self::assertSame(4, $result->retained);
        self::assertSame(3, $result->dropped, 'the three bytes refused by the cap are counted as dropped');
    }

    public function testNothingIsSplicedAfterATornTailAtTheCap(): void
    {
        // 'abc' + the lead of 你 fills the cap of 4. The tail of 你 and what follows
        // are refused by the cap, so the transcript cannot read 'abcX' with an 'X'
        // that never followed 'abc' in the stream.
        $result = Expect::on(self::master(["abc\xE4", "\xBD\xA0XYZ"]))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertSame("abc\xE4", $result->output);
        self::assertSame(4, $result->retained);
        self::assertSame(5, $result->dropped);
    }

    public function testAContinuationRunAfterACutIsCountedOnlyAgainstTheCap(): void
    {
        // The cut backs 'abc' + E4 BD off to 'abc', leaving one byte of room. The next
        // read opens with the tail A0 of that codepoint. Keeping it would put a byte
        // after a gap, so every byte after the cut is refused and the room is never spent.
        $stream = "abc\xE4\xBD\xA0xyz";
        $result = Expect::on(self::master(["abc\xE4\xBD", "\xA0xyz"]))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertSame('abc', $result->output);
        self::assertStringStartsWith($result->output, $stream, 'the transcript is a byte prefix of the stream');
        self::assertSame(3, $result->retained);
        self::assertSame(6, $result->dropped, 'two bytes backed off at the cut and the four bytes after it');
    }

    public function testACutInOneReadRefusesAContinuationRunInTheSameRead(): void
    {
        // The same stream in one read: the cut keeps 'abc' and refuses the rest of the chunk.
        $result = Expect::on(self::master(["abc\xE4\xBD\xA0xyz"]))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertSame('abc', $result->output);
        self::assertSame(3, $result->retained);
        self::assertSame(6, $result->dropped);
    }

    public function testRoomLeftByACutIsNotSpentInTheNextWindow(): void
    {
        // The first window cuts 'abc' + 你 back to 'abc', leaving one byte of room. The
        // next window is handed the threaded totals and must refuse even a one-byte read,
        // so the session transcript never resumes after the gap.
        $first = Expect::on(self::master(["abc\xE4\xBD\xA0"]))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertSame('abc', $first->output);
        self::assertSame(3, $first->retained);
        self::assertSame(3, $first->dropped);

        $second = Expect::on(self::master(['x']))
            ->collectWindow(0.2, self::farDeadline(), 4, $first->retained, $first->dropped);

        self::assertSame('', $second->output);
        self::assertSame(3, $second->retained);
        self::assertSame(4, $second->dropped, 'the one byte after the gap is refused');
    }

    public function testAStrayContinuationByteUnderTheCapIsRetained(): void
    {
        // A continuation byte with no lead, well under the cap, is the child's own
        // output. Retaining it keeps `dropped` meaning "refused by the cap" only.
        $result = Expect::on(self::master(['ok', "\x80"]))
            ->collectWindow(0.2, self::farDeadline(), 100);

        self::assertSame("ok\x80", $result->output);
        self::assertSame(3, $result->retained);
        self::assertSame(0, $result->dropped, 'no byte was refused by the cap');
    }

    // ------------------------------------------------------------ match verdict

    public function testAMatchEndsTheWindowEarly(): void
    {
        $started = \microtime(true);
        $result = Expect::on(self::master(['prompt> ']))
            ->collectWindow(5.0, self::farDeadline(), 100, 0, 0, '/prompt>/');

        self::assertTrue($result->matched);
        self::assertSame('prompt> ', $result->output);
        self::assertFalse($result->wallHit);
        self::assertLessThan(2.0, \microtime(true) - $started, 'a match must not wait out the whole window');
    }

    public function testNoMatchWithinTheWindowReportsFalse(): void
    {
        $result = Expect::on(self::master(['nothing here']))
            ->collectWindow(0.1, self::farDeadline(), 100, 0, 0, '/never/');

        self::assertFalse($result->matched);
        self::assertFalse($result->wallHit, 'a closed window is not a wall hit');
    }

    public function testAMatchInTheFinalBytesAfterChildExitIsStillFound(): void
    {
        // The child is already gone; the match lives only in the drained tail.
        $result = Expect::on(self::master([null, 'DONE']))
            ->collectWindow(5.0, self::farDeadline(), 100, 0, 0, '/DONE/', self::child(true));

        self::assertTrue($result->matched);
        self::assertSame('DONE', $result->output);
        self::assertTrue($result->childExited);
        self::assertFalse($result->wallHit);
    }

    public function testChildExitEndsTheWindowAndKeepsTheFinalRepaint(): void
    {
        $result = Expect::on(self::master(['bye', '!']))
            ->collectWindow(5.0, self::farDeadline(), 100, 0, 0, null, self::child(true));

        self::assertSame('bye!', $result->output);
        self::assertTrue($result->childExited);
        self::assertNull($result->matched);
        self::assertFalse($result->wallHit);
    }

    // ------------------------------------------------------------------ deadline

    public function testTheWallDeadlineClampsAQuietWindow(): void
    {
        $started = \microtime(true);
        $result = Expect::on(self::master([]))
            ->collectWindow(5.0, \microtime(true) + 0.15, 100, 0, 0, '/x/');

        $elapsed = \microtime(true) - $started;
        self::assertLessThan(1.0, $elapsed, 'the window must end at the deadline, not after its 5 s length');
        self::assertFalse($result->matched);
        self::assertTrue($result->wallHit, 'a window cut short by the deadline reports a wall hit');
        self::assertFalse($result->childExited);
    }

    public function testAChildExitNearTheDeadlineDoesNotOvershootIt(): void
    {
        // The child is gone before the first read, so the post-exit drain runs with
        // about 60 ms of deadline left. An unclamped drain would spend two quiet
        // 50 ms reads on top of that, overshooting the deadline by roughly 90 ms.
        $started = \microtime(true);
        $result = Expect::on(self::master([]))
            ->collectWindow(5.0, \microtime(true) + 0.06, 100, 0, 0, null, self::child(true));

        $elapsed = \microtime(true) - $started;
        self::assertLessThan(0.12, $elapsed, 'the post-exit drain must stay inside the wall deadline');
        self::assertTrue($result->childExited);
        self::assertFalse($result->wallHit, 'the child exit closed the window before the deadline was hit');
    }

    public function testAPastDeadlineReturnsAtOnceAsAWallHit(): void
    {
        $started = \microtime(true);
        $result = Expect::on(self::master(['never read']))
            ->collectWindow(5.0, \microtime(true) - 1.0, 100);

        self::assertLessThan(0.5, \microtime(true) - $started);
        self::assertSame('', $result->output);
        self::assertTrue($result->wallHit);
    }

    // ------------------------------------------------------------------- master EOF

    public function testMasterEofEndsTheWindowInsteadOfSpinningIt(): void
    {
        // An empty read is the master's EOF: no byte can follow, so the window must
        // end there, not spin empty reads for its whole 5 s length.
        $started = \microtime(true);
        $result = Expect::on(self::master(['abc', '']))
            ->collectWindow(5.0, self::farDeadline(), 100, 0, 0, '/x/');

        self::assertLessThan(1.0, \microtime(true) - $started, 'a closed master must end the window at once');
        self::assertSame('abc', $result->output);
        self::assertTrue($result->eof, 'EOF must be reported, not confused with a quiet window');
        self::assertFalse($result->matched);
        self::assertFalse($result->childExited);
        self::assertFalse($result->wallHit, 'an EOF before the deadline is not a wall hit');
    }

    public function testAQuietWindowIsNotReportedAsEof(): void
    {
        $result = Expect::on(self::master(['abc']))
            ->collectWindow(0.1, self::farDeadline(), 100, 0, 0, '/x/');

        self::assertFalse($result->eof, 'a window that simply ran out of time did not see EOF');
        self::assertFalse($result->wallHit);
    }

    public function testMasterEofWithAnExitedChildStillReportsTheExit(): void
    {
        // The first read is EOF, so the window ends on the EOF branch; the exited
        // child must still be reported there rather than read as a quiet window.
        $result = Expect::on(self::master(['']))
            ->collectWindow(5.0, self::farDeadline(), 100, 0, 0, null, self::child(true));

        self::assertSame('', $result->output);
        self::assertTrue($result->eof);
        self::assertTrue($result->childExited, 'the child exit is still seen when the master closes first');
        self::assertFalse($result->wallHit);
    }

    public function testAWallDeadlineThatPassesAfterMasterEofWithALiveChildIsAWallHit(): void
    {
        // The master closes at once but the child stays alive, so the window waits
        // for an exit, a match or the deadline. The deadline is what ends it: that is
        // a wall hit, not a silent close.
        $started = \microtime(true);
        $result = Expect::on(self::master(['abc', '']))
            ->collectWindow(5.0, \microtime(true) + 0.2, 100, 0, 0, '/x/', self::child(false));

        $elapsed = \microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.15, $elapsed, 'the window waits for the deadline when the child stays alive');
        self::assertLessThan(1.0, $elapsed, 'the window must end at the deadline, not after its 5 s length');
        self::assertSame('abc', $result->output);
        self::assertTrue($result->eof);
        self::assertFalse($result->matched);
        self::assertFalse($result->childExited);
        self::assertTrue($result->wallHit, 'a deadline passing after EOF with a live child is a wall hit');
    }

    public function testAMasterEofWithALiveChildStillEndsTheWindowAtItsLength(): void
    {
        // EOF with a live child keeps the wait, but only up to the window length: a
        // 30 s deadline must not stretch a 0.2 s window. The window ends with eof set,
        // no exit, and no wall hit, since the deadline never passed.
        $started = \microtime(true);
        $result = Expect::on(self::master(['abc', '']))
            ->collectWindow(0.2, self::farDeadline(), 100, 0, 0, '/x/', self::child(false));

        $elapsed = \microtime(true) - $started;
        self::assertGreaterThanOrEqual(0.15, $elapsed, 'the window runs out its length while the child stays alive');
        self::assertLessThan(1.0, $elapsed, 'the window length, not the 30 s deadline, must end it');
        self::assertSame('abc', $result->output);
        self::assertTrue($result->eof);
        self::assertFalse($result->matched);
        self::assertFalse($result->childExited);
        self::assertFalse($result->wallHit, 'the window length ended it, not the deadline');
    }

    public function testAChildThatExitsAfterMasterEofIsStillReportedAsExited(): void
    {
        // A child that closes its tty then exits 0.3 s later: the master reports EOF
        // at once, but the window must keep waiting for the exit.
        $exitAt = \microtime(true) + 0.3;
        $child = new class ($exitAt) implements Child {
            public function __construct(private float $exitAt)
            {
            }

            public function pid(): int
            {
                return 1;
            }

            public function exited(): bool
            {
                return \microtime(true) >= $this->exitAt;
            }

            public function wait(): int
            {
                return 0;
            }

            public function exitCode(): ?int
            {
                return 0;
            }

            public function kill(int $signal): void
            {
            }
        };
        $eofMaster = new class implements MasterPty {
            public function read(int $len = 8192, ?float $timeout = 0.05): ?string
            {
                return '';
            }

            public function write(string $bytes): int
            {
                return \strlen($bytes);
            }

            public function resize(int $cols, int $rows): void
            {
            }

            public function size(): array
            {
                return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
            }

            public function stream(): mixed
            {
                throw new \LogicException('window master has no real stream');
            }

            public function setBlocking(bool $blocking): void
            {
            }

            public function close(): void
            {
            }

            public function isClosed(): bool
            {
                return false;
            }

            public function fd(): int
            {
                return -1;
            }
        };

        $started = \microtime(true);
        $result = Expect::on($eofMaster)
            ->collectWindow(2.0, self::farDeadline(), 100, 0, 0, null, $child);

        self::assertTrue($result->eof, 'the master closed before the child exited');
        self::assertTrue($result->childExited, 'a late exit after EOF is still reported');
        self::assertFalse($result->wallHit);
        self::assertGreaterThanOrEqual(0.3, \microtime(true) - $started, 'the window waits for the exit');
        self::assertLessThan(1.5, \microtime(true) - $started, 'the window ends at the exit, not at its length');
    }

    // ------------------------------------------------------------ regex validity

    public function testAnInvalidRegexThrowsInsteadOfReportingNoMatch(): void
    {
        // A typo in one scripted step must surface as an error, not as a pattern
        // that never appeared, matching Expect::expectPattern.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("invalid regex '/(/'");

        Expect::on(self::master(['anything']))
            ->collectWindow(0.2, self::farDeadline(), 100, 0, 0, '/(/');
    }

    public function testAnEmptyRegexThrowsLikeExpectPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Expect::on(self::master(['anything']))
            ->collectWindow(0.2, self::farDeadline(), 100, 0, 0, '');
    }

    // -------------------------------------------------------------- side hooks

    public function testOnChunkAndHeartbeatFireAroundEachRead(): void
    {
        $seen = [];
        $beats = 0;
        Expect::on(self::master(['a', 'b']))->collectWindow(
            0.2,
            self::farDeadline(),
            100,
            0,
            0,
            null,
            null,
            static function () use (&$beats): void {
                ++$beats;
            },
            static function (string $chunk) use (&$seen): void {
                $seen[] = $chunk;
            },
        );

        self::assertSame(['a', 'b'], $seen, 'onChunk sees the raw bytes, in order');
        self::assertGreaterThan(0, $beats, 'the heartbeat fires on every read pass');
    }

    public function testOnChunkSeesBytesThatTheCapDrops(): void
    {
        $seen = '';
        $result = Expect::on(self::master(['abcdef']))->collectWindow(
            0.2,
            self::farDeadline(),
            2,
            0,
            0,
            null,
            null,
            null,
            static function (string $chunk) use (&$seen): void {
                $seen .= $chunk;
            },
        );

        self::assertSame('abcdef', $seen, 'the live tail is fed before retention, independent of the budget');
        self::assertSame('ab', $result->output);
        self::assertSame(4, $result->dropped);
    }

    public function testTheRecorderGetsEveryChunkTheWindowReadsNotOnlyTheRetainedBytes(): void
    {
        // The cap keeps 'abcd' in the transcript, but the recording is the child's stream.
        $sink = new \ArrayObject();
        $result = Expect::on(self::master(['abc', 'def']), recorder: self::capturingRecorder($sink))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertSame('abcd', $result->output);
        self::assertSame('abcdef', \implode('', $sink->getArrayCopy()));
    }

    public function testBytesAnExpectReadPastItsMatchAreRecordedOnceAndNotReplayedFromTheMaster(): void
    {
        $sink = new \ArrayObject();
        $expect = Expect::on(self::master(['ok> more']), recorder: self::capturingRecorder($sink))
            ->expect('> ');
        $result = $expect->collectWindow(0.2, self::farDeadline(), 100);

        self::assertSame('more', $result->output);
        self::assertSame('ok> more', \implode('', $sink->getArrayCopy()), 'the expect read records its bytes once; the window adds none');
    }

    public function testTheBufferLeftByAnExpectIsTheWindowsFirstInput(): void
    {
        $expect = Expect::on(self::master(['ok> more']))->expect('> ');

        self::assertSame('more', $expect->buffer);
        $result = $expect->collectWindow(0.2, self::farDeadline(), 100);

        self::assertSame('more', $result->output, 'bytes read past the expect match must not be skipped');
    }

    public function testABufferedMatchEndsTheWindowWithoutReadingTheMaster(): void
    {
        $expect = Expect::on(self::master(['ok> more']))->expect('> ');
        $result = $expect->collectWindow(5.0, self::farDeadline(), 100, 0, 0, '/mo/');

        self::assertTrue($result->matched);
        self::assertSame('more', $result->output);
    }

    public function testABufferedWindowCountsAgainstTheByteCap(): void
    {
        $expect = Expect::on(self::master(['ok> more']))->expect('> ');
        $result = $expect->collectWindow(0.2, self::farDeadline(), 2);

        self::assertSame('mo', $result->output);
        self::assertSame(2, $result->retained);
        self::assertSame(2, $result->dropped);
    }

    public function testTheSuccessorWindowDoesNotReplayTheConsumedBuffer(): void
    {
        $expect = Expect::on(self::master(['ok> more', ' tail']))->expect('> ');
        $first = $expect->collectWindow(0.2, self::farDeadline(), 100);

        self::assertSame('more tail', $first->output, 'the buffered bytes and the master bytes both belong to the first window');
        self::assertSame('', $first->next->buffer, 'the successor carries no buffer');

        $second = $first->next->collectWindow(0.2, self::farDeadline(), 100, $first->retained, $first->dropped);

        self::assertSame('', $second->output, 'the consumed buffer must not be reported a second time');
        self::assertSame($first->retained, $second->retained, 'no bytes are counted twice against the session cap');
    }

    public function testAWindowWithNoBufferedInputHandsBackItsOwnInstance(): void
    {
        $expect = Expect::on(self::master(['abc']));
        $result = $expect->collectWindow(0.2, self::farDeadline(), 100);

        self::assertSame($expect, $result->next);
    }
}

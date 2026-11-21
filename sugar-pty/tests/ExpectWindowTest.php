<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Expect;
use SugarCraft\Pty\ExpectWindow;

/**
 * {@see Expect::collectWindow()} is the non-throwing window engine behind a
 * scripted PTY session: it reports a match, retains bytes under a session-wide
 * cap, counts what the cap refused, cuts UTF-8 safely, and clamps every read to
 * a wall deadline. Its cases mirror the byte-budget checks that the candy-code
 * PtyDriver suite pins against its private copy of the same logic.
 */
final class ExpectWindowTest extends TestCase
{
    /**
     * A master that serves the scripted chunks in order. A null chunk, or an
     * empty script, is a read that times out after honouring the tick, so the
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
        // The running session total is 5 of a 6-byte cap, so one byte of room
        // is left for this window's 3-byte chunk: keep 'x', drop 'yz'.
        $result = Expect::on(self::master(['xyz']))
            ->collectWindow(0.2, self::farDeadline(), 6, 5, 1);

        self::assertSame('x', $result->output);
        self::assertSame(6, $result->retained, 'the budget is session-wide, not per window');
        self::assertSame(3, $result->dropped, 'the earlier session drop is carried forward and added to');
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

    public function testAnIncompleteGlyphAtTheCapEdgeIsNotRetainedAcrossChunks(): void
    {
        // The first read ends mid-glyph and fills the cap exactly. Nothing can
        // complete the sequence once the cap is reached, so it must not be kept.
        $result = Expect::on(self::master(["ab\xE4\xBD", "\xA0cd"]))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertTrue(\mb_check_encoding($result->output, 'UTF-8'), 'the retained output must be valid UTF-8');
        self::assertSame('abcd', $result->output);
        self::assertSame(4, $result->retained);
        self::assertSame(3, $result->dropped, 'the three bytes of the glyph are counted as dropped');
    }

    public function testAContinuationRunAfterACutIsNotRetained(): void
    {
        // The cut at 'abc' leaves a codepoint's lead behind; the next read opens with
        // its tail, which must be given up rather than retained as a lone byte.
        $result = Expect::on(self::master(["abc\xE4\xBD", "\xA0xyz"]))
            ->collectWindow(0.2, self::farDeadline(), 4);

        self::assertTrue(\mb_check_encoding($result->output, 'UTF-8'), 'the retained output must be valid UTF-8');
        self::assertSame('abcx', $result->output);
        self::assertSame(4, $result->retained);
        self::assertSame(5, $result->dropped);
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
}

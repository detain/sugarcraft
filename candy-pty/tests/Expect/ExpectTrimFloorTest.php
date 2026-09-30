<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Exception\ExpectEofException;
use SugarCraft\Pty\Exception\ExpectTimeoutException;
use SugarCraft\Pty\Expect;

/**
 * Audit F2 pins for Expect's buffer trim.
 *
 * The old arithmetic `keep = max(0, maxBuffer - needleLen)` collapsed
 * whenever `maxBuffer <= 2 * needleLen`:
 * - keep == 0 (cap <= needleLen): `substr($buffer, -0)` returns the
 *   WHOLE buffer, silently defeating the cap → unbounded growth.
 * - 0 < keep < needleLen: the tail kept after each trim is shorter
 *   than the needle, so a literal can never survive the read/trim
 *   boundary → guaranteed timeout/Eof with no diagnostic.
 *
 * Fix: expectAny() rejects cap < longest needle up front (fail loud),
 * and trimBuffer() floors retention at needleLen (defense-in-depth for
 * expectPattern's fixed 1 KiB heuristic).
 */
final class ExpectTrimFloorTest extends TestCase
{
    public function testExpectAnyRejectsCapSmallerThanNeedle(): void
    {
        $expect = Expect::on(new TrimFixtureMaster(chunks: []))->withMaxBuffer(4);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('#maxBuffer \(4\) is smaller than the longest needle \(10\)#');

        $expect->expectAny(['0123456789'], 0.05);
    }

    public function testExpectAnyAtCapEqualToNeedleStillRuns(): void
    {
        // Boundary of the new guard: maxBuffer == needleLen is legal —
        // the floored trim keeps exactly the needle.
        $master = new TrimFixtureMaster(chunks: ['0123456789']);
        $expect = Expect::on($master)->withMaxBuffer(10);

        $result = $expect->expectAny(['0123456789'], 0.5);

        $this->assertSame('0123456789', $result->lastMatch);
    }

    public function testExpectPatternKeepsBufferBoundedUnderEof(): void
    {
        // maxBuffer == the 1 KiB heuristic: old keep collapsed to 0,
        // so the buffer streamed through EOF whole (~4 KiB here).
        // The floored trim must cap what the exception carries.
        $master = new TrimFixtureMaster(chunks: [
            \str_repeat('x', 2000),
            \str_repeat('y', 2000),
            '',
        ]);
        $expect = Expect::on($master)->withMaxBuffer(1024);

        try {
            $expect->expectPattern('/NEVER-PRESENT/', 0.5);
            $this->fail('expected EOF before the pattern matched');
        } catch (ExpectEofException $e) {
            $this->assertLessThanOrEqual(1024, \strlen($e->buffer));
            $this->assertGreaterThan(0, \strlen($e->buffer), 'tail bytes must still be salvageable');
        }
    }

    public function testNeedleLongerThanTailGapStillMatchesAfterFlooredTrim(): void
    {
        // maxBuffer (15) < 2 * needleLen (8): old keep was 7, which
        // sliced the head off the just-arrived needle. The floor keeps
        // 8 bytes and the literal survives the boundary.
        $master = new TrimFixtureMaster(chunks: [
            \str_repeat('x', 20),
            'yyyyyyySLEUTH!!',
        ]);
        $expect = Expect::on($master)->withMaxBuffer(15);

        $result = $expect->expectAny(['SLEUTH!!'], 0.5);

        $this->assertSame('SLEUTH!!', $result->lastMatch);
    }

    public function testExpectEofTrimPathUnchangedByFloor(): void
    {
        // expectEof passes needleLen = 0, so keep == maxBuffer exactly,
        // byte-identical to the pre-fix arithmetic on the timeout path.
        $master = new TrimFixtureMaster(chunks: [\str_repeat('x', 4096), null]);
        $expect = Expect::on($master)->withMaxBuffer(1024);

        try {
            $expect->expectEof(0.2);
            $this->fail('expected the fixture to stop before EOF');
        } catch (ExpectTimeoutException $e) {
            $this->assertSame(\str_repeat('x', 1024), $e->buffer);
        }
    }
}

/**
 * Scripted MasterPty stub local to this file (same read protocol as
 * FixtureMaster in ExpectShortWriteTest, but self-contained so this
 * suite runs under any --filter).
 *
 * @implements MasterPty
 */
final class TrimFixtureMaster implements MasterPty
{
    /**
     * @param list<string|null> $chunks  null = timeout, '' = EOF, string = data
     */
    public function __construct(private array $chunks = []) {}

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        if ($this->chunks === []) {
            if ($timeout !== null) {
                \usleep((int) ($timeout * 1_000_000));
            }
            return null;
        }
        $chunk = \array_shift($this->chunks);
        if ($chunk === null && $timeout !== null) {
            \usleep((int) ($timeout * 1_000_000));
        }
        return $chunk;
    }

    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void {}
    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
    public function stream(): mixed { throw new \LogicException('TrimFixtureMaster has no real stream'); }
    public function close(): void {}
    public function isClosed(): bool { return false; }
    public function fd(): int { return -1; }
}

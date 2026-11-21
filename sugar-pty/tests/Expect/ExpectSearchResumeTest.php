<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Exception\ExpectTimeoutException;
use SugarCraft\Pty\Expect;

/**
 * Rank 79: `expectAny` resumes its search where the last failed pass left
 * off instead of rescanning the buffer from byte 0 after every read, and
 * it trims the buffer to `maxBuffer` only after a pass has searched the
 * fresh chunk.
 *
 * The pins below are the two ways a resume can go wrong: a cursor that
 * runs past an occurrence still being completed by the next read (or one
 * that the back-off trim cut through), and a cursor that falls back over a
 * match already consumed, so the same match is reported twice.
 */
final class ExpectSearchResumeTest extends TestCase
{
    public function testMatchSpanningTheBackOffCutIsFound(): void
    {
        // 34 bytes against a 16-byte cap with a 6-byte needle: the trim
        // keeps max(6, 16 - 6) = 10 bytes, so it cuts at byte 24, inside
        // SLEUTH (bytes 20..25). Trimming before the search would leave
        // only 'TH' and the match would never be seen.
        $chunk = \str_repeat('x', 20) . 'SLEUTH' . \str_repeat('y', 8);
        $expect = Expect::on(new ResumeFixtureMaster([$chunk]))->withMaxBuffer(16);

        $result = $expect->expectAny(['SLEUTH'], 0.5);

        $this->assertSame('SLEUTH', $result->lastMatch);
        $this->assertSame(\str_repeat('x', 20), $result->before);
        $this->assertSame(\str_repeat('y', 8), $result->buffer);
    }

    public function testMatchWhollyInsideTheTrimmedHeadIsFound(): void
    {
        $chunk = 'ab' . 'SLEUTH' . \str_repeat('z', 32);
        $expect = Expect::on(new ResumeFixtureMaster([$chunk]))->withMaxBuffer(16);

        $result = $expect->expectAny(['SLEUTH'], 0.5);

        $this->assertSame('ab', $result->before);
        $this->assertSame(\str_repeat('z', 32), $result->buffer);
    }

    public function testMatchSpanningAReadBoundaryIsFound(): void
    {
        $expect = Expect::on(new ResumeFixtureMaster(['aaaSLE', 'UTHbbb']));

        $result = $expect->expectAny(['SLEUTH'], 0.5);

        $this->assertSame('aaa', $result->before);
        $this->assertSame('bbb', $result->buffer);
    }

    public function testMatchSpanningBothACutAndAReadBoundaryIsFound(): void
    {
        // Pass one fails on 16 bytes; the 10-byte cap keeps 6 bytes
        // ('xxxxSL'), shifting the resume cursor back by the 10-byte cut.
        // The cursor must still sit at or before the 'S' so the next read
        // completes the needle.
        $expect = Expect::on(new ResumeFixtureMaster([\str_repeat('x', 14) . 'SL', 'EUTHz']))
            ->withMaxBuffer(10);

        $result = $expect->expectAny(['SLEUTH'], 0.5);

        $this->assertSame('SLEUTH', $result->lastMatch);
        $this->assertSame('xxxx', $result->before);
        $this->assertSame('z', $result->buffer);
    }

    public function testNeedlesOfDifferentLengthsKeepTheirOwnCursors(): void
    {
        // After pass one the short needle's cursor is past the long
        // needle's start. The long needle still wins on the earliest
        // offset once the next read completes it.
        $expect = Expect::on(new ResumeFixtureMaster(['xxxLONGNEE', 'DLE ok']));

        $result = $expect->expectAny(['ok', 'LONGNEEDLE'], 0.5);

        $this->assertSame('LONGNEEDLE', $result->lastMatch);
        $this->assertSame('xxx', $result->before);
        $this->assertSame(' ok', $result->buffer);
    }

    public function testConsecutiveExpectsNeverReportTheSameMatchTwice(): void
    {
        $expect = Expect::on(new ResumeFixtureMaster(['one>', 'two', '>thr', 'ee>']));

        $first = $expect->expect('>', 0.5);
        $second = $first->expect('>', 0.5);
        $third = $second->expect('>', 0.5);

        $this->assertSame(['one', 'two', 'three'], [$first->before, $second->before, $third->before]);
        $this->assertSame('', $third->buffer);

        $this->expectException(ExpectTimeoutException::class);
        $third->expect('>', 0.1);
    }

    public function testRepeatedNeedleInOneChunkIsConsumedOnceEach(): void
    {
        $expect = Expect::on(new ResumeFixtureMaster(['A>B>C>']));

        $first = $expect->expect('>', 0.5);
        $second = $first->expect('>', 0.5);
        $third = $second->expect('>', 0.5);

        $this->assertSame(['A', 'B', 'C'], [$first->before, $second->before, $third->before]);

        $this->expectException(ExpectTimeoutException::class);
        $third->expect('>', 0.1);
    }

    public function testATrimmedTimeoutStillReportsTheCappedTail(): void
    {
        $expect = Expect::on(new ResumeFixtureMaster([\str_repeat('q', 64)]))->withMaxBuffer(16);

        try {
            $expect->expectAny(['NEVER'], 0.1);
            $this->fail('expected a timeout');
        } catch (ExpectTimeoutException $e) {
            $this->assertSame(\str_repeat('q', 11), $e->buffer, 'keep = max(5, 16 - 5) = 11');
        }
    }
}

/**
 * Scripted MasterPty stub local to this file: hands out one chunk per read,
 * then reads as a quiet master (null after the timeout) once the script ends.
 */
final class ResumeFixtureMaster implements MasterPty
{
    /** @param list<string> $chunks */
    public function __construct(private array $chunks) {}

    public function read(int $len = 8192, ?float $timeout = 0.05): ?string
    {
        if ($this->chunks === []) {
            if ($timeout !== null) {
                \usleep((int) ($timeout * 1_000_000));
            }
            return null;
        }
        return \array_shift($this->chunks);
    }

    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }

    public function resize(int $cols, int $rows): void {}
    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
    public function stream(): mixed { throw new \LogicException('ResumeFixtureMaster has no real stream'); }
    public function setBlocking(bool $blocking): void {}
    public function close(): void {}
    public function isClosed(): bool { return false; }
    public function fd(): int { return -1; }
}

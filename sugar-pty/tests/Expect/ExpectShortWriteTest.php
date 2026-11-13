<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Exception\ExpectEofException;
use SugarCraft\Pty\Exception\ExpectTimeoutException;
use SugarCraft\Pty\Expect;

/**
 * Expect buffer-discipline pins: the maxBuffer cap as observed through
 * expectEof()'s retained before-buffer, the builder carries for
 * maxBuffer/searchWindow/recorder, and expectEof() success semantics.
 *
 * The partial-write flush loop is NOT covered here despite the file name:
 * FixtureMaster::write() below accepts every byte it is handed, so any
 * short-write story told in this file would be fiction. That loop's home
 * is ExpectSendRetryTest, whose busyMaster fixture would-blocks and
 * accepts one chunk at a time.
 */
final class ExpectShortWriteTest extends TestCase
{
    // ----- builder methods -----------------------------------------------

    public function testWithMaxBufferReturnsNewInstance(): void
    {
        $expect = Expect::on(new FixtureMaster(''));
        $expect2 = $expect->withMaxBuffer(4096);

        $this->assertNotSame($expect, $expect2);
        $this->assertSame(4096, $expect2->maxBuffer);
        $this->assertNull($expect->maxBuffer);
    }

    public function testWithSearchWindowReturnsNewInstance(): void
    {
        $expect = Expect::on(new FixtureMaster(''));
        $expect2 = $expect->withSearchWindow(1024);

        $this->assertNotSame($expect, $expect2);
        $this->assertSame(1024, $expect2->searchWindow);
        $this->assertNull($expect->searchWindow);
    }

    public function testWithMaxBufferCanBeChained(): void
    {
        $expect = Expect::on(new FixtureMaster(''));
        $expect2 = $expect
            ->withMaxBuffer(4096)
            ->withSearchWindow(512);

        $this->assertSame(4096, $expect2->maxBuffer);
        $this->assertSame(512, $expect2->searchWindow);
    }

    // ----- expectEof() ---------------------------------------------------

    public function testExpectEofReturnsEmptyBufferOnEof(): void
    {
        // Master returns '' (genuine EOF) immediately
        $master = new FixtureMaster('', chunks: ['', '']);
        $expect = Expect::on($master);
        $result = $expect->expectEof(0.1);

        $this->assertSame('', $result->buffer);
        $this->assertNull($result->lastMatch);
    }

    public function testExpectEofThrowsTimeoutExceptionWhenNoEof(): void
    {
        // Master returns null (timeout) repeatedly
        $master = new FixtureMaster('', chunks: [null, null, null]);
        $expect = Expect::on($master);

        $this->expectException(ExpectTimeoutException::class);
        $expect->expectEof(0.01);
    }

    public function testExpectEofPassesBeforeBuffer(): void
    {
        // Master returns some data then EOF
        $master = new FixtureMaster('', chunks: ['hello', '']);
        $expect = new Expect(master: $master, buffer: 'pre-existing');
        $result = $expect->expectEof(0.1);

        // before = pre-existing + "hello"
        $this->assertSame('pre-existinghello', $result->before);
        $this->assertSame('', $result->buffer);
    }

    public function testExpectEofWithMaxBufferTrimsLongBuffer(): void
    {
        // Master returns a large chunk, then EOF.
        $largeChunk = \str_repeat('x', 8192);
        $master = new FixtureMaster('', chunks: [$largeChunk, '']);
        $expect = Expect::on($master)->withMaxBuffer(1024);

        $result = $expect->expectEof(0.1);

        // The cap's observable oracle is the RETAINED before-buffer: 8192
        // bytes were read, only the last 1024 survive the per-read trim.
        // If trimBuffer() stops running, before is 8192 bytes and this
        // fails — unlike the old assertLessThanOrEqual(1024, …) that sat
        // above an emptiness assert on $result->buffer and could never
        // fail independently (emptiness subsumes the cap).
        $this->assertSame(1024, \strlen($result->before));
        $this->assertSame(\str_repeat('x', 1024), $result->before);
        $this->assertSame('', $result->buffer); // final buffer after EOF is ''
    }

    public function testExpectEofWithMaxBufferKeepsTheNewestBytes(): void
    {
        // 12 KiB arrives in three distinguishable chunks before EOF; the
        // retained tail must be the NEWEST maxBuffer bytes, not a stale
        // window from wherever a one-shot trim happened to land.
        $master = new FixtureMaster('', chunks: [
            \str_repeat('a', 4096),
            \str_repeat('b', 4096),
            \str_repeat('c', 4096),
            '',
        ]);
        $result = Expect::on($master)->withMaxBuffer(1024)->expectEof(0.1);

        $this->assertSame(\str_repeat('c', 1024), $result->before);
    }

    // ----- searchWindow builder ------------------------------------------

    public function testWithSearchWindowEchoesTheBuilderField(): void
    {
        // Builder carry only: the value lands on the new instance and the
        // old one is untouched. This leg never exercised a strpos scan —
        // the window's scan-bound BEHAVIOUR (what stays findable inside
        // the tail slice) is a property question, not a field-echo one,
        // and belongs in a behaviour pin.
        $expect = Expect::on(new FixtureMaster(''));
        $expect2 = $expect->withSearchWindow(10);

        $this->assertSame(10, $expect2->searchWindow);
        $this->assertNull($expect->searchWindow);
        $this->assertNotSame($expect, $expect2);
    }

    // ----- withRecorder carries maxBuffer/searchWindow -------------------

    public function testWithRecorderPreservesMaxBufferAndSearchWindow(): void
    {
        $expect = Expect::on(new FixtureMaster('', chunks: []))
            ->withMaxBuffer(2048)
            ->withSearchWindow(512);
        $expect2 = $expect->withRecorder(null);

        $this->assertSame(2048, $expect2->maxBuffer);
        $this->assertSame(512, $expect2->searchWindow);
    }
}

/**
 * Minimal MasterPty stub for testing Expect methods that don't need a real PTY.
 *
 * @implements MasterPty
 */
final class FixtureMaster implements MasterPty
{
    /**
     * @param list<string|null> $chunks  null = timeout, '' = EOF, string = data
     */
    public function __construct(
        private string $initial = '',
        private array $chunks = [],
    ) {}

    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        if ($this->initial !== '') {
            $out = $this->initial;
            $this->initial = '';
            return $out;
        }
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
    public function stream(): mixed { throw new \LogicException('FixtureMaster has no real stream'); }
    public function close(): void {}
    public function isClosed(): bool { return false; }
    public function fd(): int { return -1; }
}

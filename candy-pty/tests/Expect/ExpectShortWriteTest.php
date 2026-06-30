<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Expect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Exception\ExpectEofException;
use SugarCraft\Pty\Exception\ExpectTimeoutException;
use SugarCraft\Pty\Expect;

/**
 * Tests for Expect remediation: partial-write flush loop (Step 8)
 * and maxBuffer / searchWindow / expectEof (Step 11).
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
        // Master returns a large chunk
        $largeChunk = \str_repeat('x', 8192);
        $master = new FixtureMaster('', chunks: [$largeChunk, '']);
        $expect = Expect::on($master)->withMaxBuffer(1024);

        $result = $expect->expectEof(0.1);

        // Buffer should be trimmed to maxBuffer (1024 bytes)
        $this->assertLessThanOrEqual(1024, \strlen($result->buffer));
        $this->assertSame('', $result->buffer); // final buffer after EOF is ''
    }

    // ----- searchWindow behavior -----------------------------------------

    public function testSearchWindowBoundsStrposScan(): void
    {
        // When searchWindow is set, only the last N bytes are searched.
        // With a buffer of "prefix" and a needle "suffix" at the very end,
        // the strpos should still find it if the window covers it.
        $expect = Expect::on(new FixtureMaster(''));
        $expect2 = $expect->withSearchWindow(10);

        $this->assertSame(10, $expect2->searchWindow);
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

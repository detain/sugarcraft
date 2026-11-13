<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Style\Output;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    public function testSprintConcatenatesWithSpaces(): void
    {
        $this->assertSame('hello world',     Output::sprint('hello', 'world'));
        $this->assertSame('a b c',           Output::sprint('a', 'b', 'c'));
        $this->assertSame('',                Output::sprint());
        $this->assertSame('only',            Output::sprint('only'));
    }

    public function testPrintfFormatsLikeSprintf(): void
    {
        $this->assertSame('count=42 ratio=0.50', Output::printf('count=%d ratio=%0.2f', 42, 0.5));
        $this->assertSame('no args here',        Output::printf('no args here'));
    }

    public function testFprintWritesToCallerSuppliedStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);
        Output::fprint($stream, 'foo', 'bar', 'baz');
        rewind($stream);
        $this->assertSame('foo bar baz', stream_get_contents($stream));
        fclose($stream);
    }

    public function testFprintHonoursPreStyledContent(): void
    {
        // Output::* is style-agnostic — already-styled strings pass
        // through verbatim (this keeps the helper composable with
        // Style::new()->render('...').
        $stream = fopen('php://memory', 'w+');
        Output::fprint($stream, "\x1b[1mhi\x1b[0m");
        rewind($stream);
        $this->assertSame("\x1b[1mhi\x1b[0m", stream_get_contents($stream));
        fclose($stream);
    }

    // ---- Finding 038: accounted writes (fail-fast on false/short) -----

    public function testFprintReturnsTheByteCount(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertSame(11, Output::fprint($stream, 'hello', 'world'));
        fclose($stream);
    }

    public function testEmptyPayloadIsAZeroWriteNotAFailure(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertSame(0, Output::fprint($stream));
        fclose($stream);
    }

    public function testFprintRefusesAClosedStreamLoudly(): void
    {
        // Before ST-18 this was silent (fwrite on a closed resource was
        // unchecked); now the writers family refuses with the house
        // RuntimeException carrying the destination.
        $stream = fopen('php://memory', 'w+');
        fclose($stream);
        try {
            Output::fprint($stream, 'payload');
            $this->fail('expected RuntimeException for a closed stream');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'cannot write: stream is not a valid stream resource',
                $e->getMessage(),
            );
        }
    }

    public function testFprintRefusesAShortWriteLoudly(): void
    {
        // A user-space stream whose first stream_write() lands a single
        // byte and then stalls (0) — fwrite returns 1 of 11, the exact
        // silent-truncation case finding 038 flags.
        stream_wrapper_register('st18short', ShortWriteStream::class);
        $stream = fopen('st18short://probe', 'w');
        try {
            Output::fprint($stream, 'hello', 'world');
            $this->fail('expected RuntimeException for a short write');
        } catch (\RuntimeException $e) {
            $this->assertSame('stream write failed: wrote 1 of 11 bytes', $e->getMessage());
        } finally {
            fclose($stream);
            stream_wrapper_unregister('st18short');
        }
    }

    public function testStyleFprintSharesTheAccountingContract(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertSame(11, Style::new()->fprint($stream, 'hello', 'world'));
        fclose($stream);
        try {
            Style::new()->fprint($stream, 'x');
            $this->fail('expected RuntimeException for the closed stream');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                'cannot write: stream is not a valid stream resource',
                $e->getMessage(),
            );
        }
    }
}

/**
 * Test-support stream wrapper for
 * {@see OutputTest::testFprintRefusesAShortWriteLoudly()}: accepts one
 * byte per open, then reports no progress, so fwrite() returns a short
 * count instead of looping to completion.
 */
final class ShortWriteStream
{
    /** @var resource|null */
    public $context;

    private int $calls = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->calls = 0;
        return true;
    }

    public function stream_write(string $data): int
    {
        $this->calls++;
        return $this->calls === 1 ? 1 : 0;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    /** @return array<int, mixed> */
    public function stream_stat(): array
    {
        return [];
    }
}

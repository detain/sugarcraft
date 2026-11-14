<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use SugarCraft\Style\Output;
use SugarCraft\Style\Style;
use PHPUnit\Framework\TestCase;

final class OutputTest extends TestCase
{
    private ?string $scratchDir = null;

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

    // ---- ST-27: the STDOUT routes of print/println -----------------------
    //
    // fwrite(STDOUT) lands on the file descriptor, so an output buffer
    // in-process cannot see it — the honest capture is a child process
    // with its streams redirected (same idiom as ReadmeSnippetsTest).
    // The child echoes its int return on STDERR so the byte accounting
    // is asserted against the exact same payload the STDOUT file carries.

    public function testPrintWritesTheJoinedPayloadWithNoNewline(): void
    {
        [$payload, $count] = $this->runWriterScript('\\SugarCraft\\Style\\Output::print("hello", "world")');
        $this->assertSame('hello world', $payload, 'print must add nothing — not even a newline');
        $this->assertSame(11, $count, 'the return must equal the bytes that landed');
    }

    public function testPrintlnEmitsExactlyOneTrailingNewline(): void
    {
        [$payload, $count] = $this->runWriterScript('\\SugarCraft\\Style\\Output::println("a", "b", "c")');
        $this->assertSame("a b c\n", $payload);
        $this->assertSame(6, $count);
        // The zero-argument edge: a lone line break, exactly one byte.
        [$bare, $bareCount] = $this->runWriterScript('\\SugarCraft\\Style\\Output::println()');
        $this->assertSame("\n", $bare, 'println() is one newline, never two');
        $this->assertSame(1, $bareCount);
    }

    public function testStylePrintRoutesRenderedBytesToStdout(): void
    {
        [$payload, $count] = $this->runWriterScript('\\SugarCraft\\Style\\Style::new()->bold()->print("hi")');
        $this->assertSame("\x1b[1mhi\x1b[0m", $payload);
        $this->assertSame(10, $count);
    }

    public function testStylePrintlnRoutesRenderedBytesPlusOneNewline(): void
    {
        [$payload, $count] = $this->runWriterScript('\\SugarCraft\\Style\\Style::new()->bold()->println("hi")');
        $this->assertSame("\x1b[1mhi\x1b[0m\n", $payload);
        $this->assertSame(11, $count, 'the newline is inside the accounted write');
    }

    public function testSuccessiveStylePrintCallsConcatenateWithoutSeparators(): void
    {
        $script = '(function () { $s = \\SugarCraft\\Style\\Style::new()->bold(); '
            . 'return $s->print("x") + $s->print("y"); })()';
        [$payload, $count] = $this->runWriterScript($script);
        $this->assertSame("\x1b[1mx\x1b[0m\x1b[1my\x1b[0m", $payload, 'print never separates its calls');
        $this->assertSame(18, $count);
    }

    /**
     * Evaluate one expression in a child PHP process with STDOUT/STDERR
     * redirected to files; the expression's int return is echoed on
     * STDERR by the generated harness.
     *
     * @return array{0:string, 1:int} [exact STDOUT bytes, STDERR as int]
     */
    private function runWriterScript(string $expression): array
    {
        $dir = $this->scratchDir();
        $path = $dir . '/writer-' . substr(md5($expression), 0, 12) . '.php';
        $out  = $path . '.out';
        $err  = $path . '.err';
        $autoload = var_export(realpath(__DIR__ . '/../vendor/autoload.php'), true);
        file_put_contents(
            $path,
            "<?php\ndeclare(strict_types=1);\nrequire {$autoload};\n"
            . 'fwrite(STDERR, (string) (' . $expression . '));' . "\n",
        );
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path)
            . ' 1>' . escapeshellarg($out) . ' 2>' . escapeshellarg($err),
            $lines,
            $status,
        );
        $this->assertSame(0, $status, 'writer script died: ' . (string) file_get_contents($err));

        return [(string) file_get_contents($out), (int) file_get_contents($err)];
    }

    private function scratchDir(): string
    {
        if ($this->scratchDir === null) {
            $this->scratchDir = sys_get_temp_dir() . '/style-output-' . getmypid() . '-' . spl_object_id($this);
            if (!is_dir($this->scratchDir) && !mkdir($this->scratchDir, 0700) && !is_dir($this->scratchDir)) {
                $this->fail('could not create scratch dir ' . $this->scratchDir);
            }
        }

        return $this->scratchDir;
    }

    public static function tearDownAfterClass(): void
    {
        $marker = sys_get_temp_dir() . '/style-output-' . getmypid() . '-';
        foreach (glob($marker . '*') ?: [] as $dir) {
            foreach (glob($dir . '/' . chr(42)) ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($dir);
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

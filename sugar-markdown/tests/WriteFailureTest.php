<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\StreamSink;
use SugarCraft\Markdown\Tests\Support\ProbeStreamWrapper;
use SugarCraft\Markdown\Theme;
use SugarCraft\Markdown\Writer;

/**
 * SM-S54 (MDTEST-07): write/flush failure paths. Every documented
 * `RuntimeException` on the write-through doors must actually fire when the
 * stream refuses — the probe wrapper gains short-write and flush-failure
 * arms so each error line is driven, not assumed. Each leg flips red if its
 * throw is deleted (the whole point: pcov-zero error lines are unfalsified
 * promises otherwise).
 *
 * Error-capture style (assign in catch, assert after) is deliberate:
 * PHPUnit's fail() itself travels as a RuntimeException subclass, and a
 * fail-inside-try/catch(RuntimeException) would swallow the very guard it
 * means to prove.
 */
final class WriteFailureTest extends TestCase
{
    private const DOC = "# Title\n\nbody\n";

    /**
     * Refusal semantics of the probe arms (SM-S54 discovery): a stream_write
     * that reports partial progress gets remedied by PHP (the remainder is
     * pushed in a second call, fwrite totals), while a total refusal (0
     * bytes) surfaces as a failed fwrite — the shape real byte-limited doors
     * take. Legs therefore arm full refusal, not trimming.
     *
     * @param resource $stream
     */
    private static function armRefusal(string $target, $stream): void
    {
        self::assertNotFalse($stream);
        ProbeStreamWrapper::$shortWrite[$target] = 0;
    }

    public function testRendererWriteShortWriteThrowsWriteFailed(): void
    {
        $stream = ProbeStreamWrapper::open('s54-rw');
        self::armRefusal('s54-rw', $stream);

        $error = null;
        try {
            (new Renderer(Theme::plain()))->write(self::DOC, $stream);
        } catch (RuntimeException $e) {
            $error = $e;
        } finally {
            fclose($stream);
        }

        $this->assertInstanceOf(RuntimeException::class, $error, 'a short write must be reported as a failed write');
        $this->assertMatchesRegularExpression('/failed to write \d+ bytes to the output stream/', $error->getMessage());
    }

    public function testSinkShortWriteThrowsWriteFailed(): void
    {
        $stream = ProbeStreamWrapper::open('s54-sink');
        $sink   = StreamSink::toStream($stream);

        // A clean write first — the refusal arm must land on the door call
        // of this leg, not on state left behind by earlier traffic.
        $sink->write("# A\n\n");
        ProbeStreamWrapper::$shortWrite['s54-sink'] = 0; // the door refuses everything

        $error = null;
        try {
            $sink->write("body\n");
        } catch (RuntimeException $e) {
            $error = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $error, 'StreamSink must treat a refused write as fatal');
        $this->assertMatchesRegularExpression('/failed to write \d+ bytes to the output stream/', $error->getMessage());

        $sink->close();
        fclose($stream);
    }

    public function testSinkFlushFailureThrowsFlushFailed(): void
    {
        $stream = ProbeStreamWrapper::open('s54-flush');
        $sink   = StreamSink::toStream($stream);

        $sink->write("clean\n");
        ProbeStreamWrapper::$failFlush['s54-flush'] = true;

        $error = null;
        try {
            $sink->write("body\n");
        } catch (RuntimeException $e) {
            $error = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $error, 'a failed fflush must be reported, not swallowed');
        $this->assertSame('flushing the output stream failed', $error->getMessage());

        // PHP caches a wrapper flush failure at the stream level until the
        // next successful flush; @fflush above suppressed that notice, this
        // repair flush clears the latch so fclose cannot warn post-assert.
        fflush($stream);
        $sink->close();
        fclose($stream);
    }

    public function testWriterCloseRethrowsOriginalFailureAndStillReleasesTheDoor(): void
    {
        $stream = ProbeStreamWrapper::open('s54-close');

        $writer = Writer::toStream(new Renderer(Theme::plain()), $stream);
        $writer->feed("# A\n\nno newline cut here");
        ProbeStreamWrapper::$failFlush['s54-close'] = true;

        $error = null;
        try {
            $writer->close();
        } catch (RuntimeException $e) {
            $error = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $error, 'close() must rethrow the original write error');
        $this->assertSame('flushing the output stream failed', $error->getMessage());

        // Single-use law holds even after the failed close: the door is
        // released and a second close is a silent no-op.
        $this->assertFalse($writer->isOpen());
        $writer->close();
        fclose($stream);
    }
}

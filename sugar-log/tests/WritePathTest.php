<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Tests\Support\StderrNoticeCapture;

/**
 * [M8/m15/N5/N7] Write-path honesty pins: a record that cannot land must be
 * announced, never silently lost, and the logger must survive the failure.
 */
final class WritePathTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/sugar-log-write-' . \uniqid();
        \mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->dir);
    }

    public function testClosedStreamDegradesToNoticeWithoutThrowing(): void
    {
        // [m15] A handle validated at assignment time can be closed by the
        // host afterwards; emit() must notice-and-continue, never fatal.
        $path = $this->dir . '/closed.log';
        $stream = \fopen($path, 'w');
        $log = Logger::new(level: Level::Debug, reportTimestamp: false, stream: $stream);
        \fclose($stream);

        $log->info('after-close');
        $log->warn('also-after-close');

        // The logger stays usable after switching to a live destination.
        $path2 = $this->dir . '/revived.log';
        $stream2 = \fopen($path2, 'w');
        $log->setOutput($stream2);
        $log->info('revived');
        \fclose($stream2);

        $this->assertStringContainsString('revived', (string) \file_get_contents($path2));
    }

    public function testReadOnlyStreamIsRejectedQuietlyAndLossIsAnnounced(): void
    {
        // [M8] fwrite() to a read-only handle returns false. Pre-fix the
        // result was discarded and the record vanished silently; now the
        // failure path runs (retry, then the STDERR notice) without any
        // exception reaching the caller.
        $path = $this->dir . '/readonly.log';
        \file_put_contents($path, 'untouched');
        $stream = \fopen($path, 'r');
        $log = Logger::new(level: Level::Debug, reportTimestamp: false, stream: $stream);

        $log->error('cannot-land');

        \fclose($stream);
        $this->assertSame('untouched', (string) \file_get_contents($path));
    }

    public function testRoutingThresholdFallsBackToErrorWhenNulled(): void
    {
        // [N5] The documented default threshold is Error. Forcing the slot
        // to null (state the public setters refuse to create) must keep
        // Info on the main stream — null coercion would once compare
        // `$level->value >= null` and route every non-Debug record away.
        $mainPath = $this->dir . '/main.log';
        $errPath = $this->dir . '/err.log';
        $main = \fopen($mainPath, 'w');
        $err = \fopen($errPath, 'w');

        $log = Logger::new(level: Level::Debug, reportTimestamp: false, stream: $main, errorStream: $err);
        (new \ReflectionProperty(Logger::class, 'errorStreamAt'))->setValue($log, null);

        $log->info('gentle-info');
        $log->error('loud-error');
        \fclose($main);
        \fclose($err);

        $mainContent = (string) \file_get_contents($mainPath);
        $errContent = (string) \file_get_contents($errPath);

        $this->assertStringContainsString('gentle-info', $mainContent);
        $this->assertStringNotContainsString('gentle-info', $errContent);
        $this->assertStringContainsString('loud-error', $errContent);
    }

    public function testWriteFailureAnnouncesSanitizedSingleLineOnRealStderr(): void
    {
        // [TEST-SL-01] The notice bytes themselves were never asserted: every
        // failure test here checked the destination, not the announcement.
        // Deleting the fwrite at Logger.php:273 kept the suite green. A child
        // process pin closes that hole: one line, exact prefix, payload
        // excerpt carried, control bytes swept.
        $result = StderrNoticeCapture::run(<<<'PHP'
            $path = sys_get_temp_dir() . '/sugar-log-notice-ro-' . getmypid() . '.log';
            file_put_contents($path, 'untouched');
            $stream = fopen($path, 'r');
            $logger = SugarCraft\Log\Logger::new(level: SugarCraft\Log\Level::Debug, reportTimestamp: false, stream: $stream);
            $logger->error('cannot-land-probe');
            fclose($stream);
            unlink($path);
            PHP);

        $this->assertSame(0, $result['exitCode'], 'the failed write must not escalate to the child: ' . $result['stderr']);
        $this->assertStringStartsWith('[sugar-log] log write failed: ', $result['stderr']);
        $this->assertStringContainsString('cannot-land-probe', $result['stderr']);
        $this->assertSame(1, StderrNoticeCapture::lineCount($result['stderr']), 'the notice must be exactly one line: ' . $result['stderr']);
        $this->assertStringNotContainsString("\x1b", $result['stderr']);
    }

    public function testFlushDrainsBufferedFileBytes(): void
    {
        // [M8 docs] File streams buffer; flush() is the documented drain for
        // hard-exit paths. After flush(), bytes must be readable while the
        // stream is still open.
        $path = $this->dir . '/buffered.log';
        $stream = \fopen($path, 'w');
        $log = Logger::new(level: Level::Debug, reportTimestamp: false, stream: $stream);

        $log->info('buffered-now');
        $log->flush();

        $this->assertStringContainsString('buffered-now', (string) \file_get_contents($path));
        \fclose($stream);
    }

    public function testShortWriteThatTheRetryCompletesLandsOneLineWithNoAnnounce(): void
    {
        $result = StderrNoticeCapture::run(<<<'PHP'
            SugarCraft\Log\Tests\Support\ShortWriteStream::$modes = [];
            \stream_wrapper_register('sugarlog-sw', SugarCraft\Log\Tests\Support\ShortWriteStream::class);
            $stream = \fopen('sugarlog-sw://retry', 'w');
            $logger = SugarCraft\Log\Logger::new(stream: $stream, reportTimestamp: false);
            $logger->info('cap');
            $logger->flush();
            echo '[' . SugarCraft\Log\Tests\Support\ShortWriteStream::$buffers['retry'] . ']';
            \fclose($stream);
            \stream_wrapper_unregister('sugarlog-sw');
            PHP);

        $this->assertSame(0, $result['exitCode']);
        // The record is ONE whole line in the stream: five bytes from the
        // short first call plus the three the retry completed.
        $this->assertSame("[INF cap\n]", $result['stdout']);
        // A retry that succeeds must not announce anything.
        $this->assertSame('', $result['stderr']);
    }

    public function testWriteThatStaysShortEvenAfterRetryAnnouncesPartialWrite(): void
    {
        $result = StderrNoticeCapture::run(<<<'PHP'
            SugarCraft\Log\Tests\Support\ShortWriteStream::$modes = ['short' => 'always-fail'];
            \stream_wrapper_register('sugarlog-sw', SugarCraft\Log\Tests\Support\ShortWriteStream::class);
            $stream = \fopen('sugarlog-sw://short', 'w');
            $logger = SugarCraft\Log\Logger::new(stream: $stream, reportTimestamp: false);
            $logger->info('cap');
            $logger->flush();
            echo '[' . SugarCraft\Log\Tests\Support\ShortWriteStream::$buffers['short'] . ']';
            \fclose($stream);
            \stream_wrapper_unregister('sugarlog-sw');
            PHP);

        $this->assertSame(0, $result['exitCode']);
        // Two landed bytes: the first write and the retry each gave one.
        $this->assertSame('[IN]', $result['stdout']);
        $this->assertStringStartsWith(
            '[sugar-log] log write failed: partial write only: INF cap ',
            $result['stderr'],
        );
    }
}

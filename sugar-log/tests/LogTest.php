<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Level;
use SugarCraft\Log\Log;
use SugarCraft\Log\Logger;
use SugarCraft\Log\PanicFormatter;
use SugarCraft\Log\Tests\Support\StderrNoticeCapture;

/**
 * Coverage tests for the Log static facade.
 * Tests: default(), setLogger(), reset(), debug(), info(), warn(), error(), fatal(), print(), restoreTerminal()
 */
final class LogTest extends TestCase
{
    /** @var resource */
    private $tempFile;
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/sugar-log-test-' . \uniqid() . '.log';
        $this->tempFile = \fopen($this->tempPath, 'w');
        // Reset the Log facade state before each test
        Log::reset();
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->tempFile)) {
            \fclose($this->tempFile);
        }
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
        Log::removePanicHandler();
        Log::reset();
    }

    private function logger(Level $minLevel = Level::Debug): Logger
    {
        return Logger::new(
            level: $minLevel,
            reportTimestamp: false,
            reportCaller: false,
            stream: $this->tempFile,
        );
    }

    private function getContent(): string
    {
        \fclose($this->tempFile);
        return \file_get_contents($this->tempPath) ?: '';
    }

    public function testDefaultLazilyCreatesLogger(): void
    {
        // Before setting a logger, default() should create one lazily
        $default = Log::default();
        $this->assertInstanceOf(Logger::class, $default);
    }

    public function testDefaultReturnsSameInstanceOnSubsequentCalls(): void
    {
        $first = Log::default();
        $second = Log::default();
        $this->assertSame($first, $second);
    }

    public function testSetLoggerReplacesDefault(): void
    {
        $custom = $this->logger();
        Log::setLogger($custom);

        $this->assertSame($custom, Log::default());
    }

    public function testResetClearsDefault(): void
    {
        $first = Log::default();
        Log::reset();
        $second = Log::default();

        $this->assertNotSame($first, $second);
    }

    public function testDebugLogsAtDebugLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::debug('debug message', ['key' => 'value']);

        $content = $this->getContent();
        $this->assertStringContainsString('debug message', $content);
        $this->assertStringContainsString('DBG', $content);
        $this->assertStringContainsString('key=value', $content);
    }

    public function testDebugFilteredWhenMinLevelIsInfo(): void
    {
        $log = $this->logger(Level::Info);
        Log::setLogger($log);

        Log::debug('should not appear');

        $content = $this->getContent();
        $this->assertSame('', $content);
    }

    public function testInfoLogsAtInfoLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::info('info message', ['item' => 'test']);

        $content = $this->getContent();
        $this->assertStringContainsString('info message', $content);
        $this->assertStringContainsString('INF', $content);
        $this->assertStringContainsString('item=test', $content);
    }

    public function testWarnLogsAtWarnLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::warn('warn message', ['count' => 3]);

        $content = $this->getContent();
        $this->assertStringContainsString('warn message', $content);
        $this->assertStringContainsString('WRN', $content);
        $this->assertStringContainsString('count=3', $content);
    }

    public function testErrorLogsAtErrorLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::error('error message', ['code' => 500]);

        $content = $this->getContent();
        $this->assertStringContainsString('error message', $content);
        $this->assertStringContainsString('ERR', $content);
        $this->assertStringContainsString('code=500', $content);
    }

    public function testFatalLogsAndThrows(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fatal error');

        try {
            Log::fatal('fatal error');
        } catch (\RuntimeException $e) {
            // Verify it logged before throwing
            $content = $this->getContent();
            $this->assertStringContainsString('FTL', $content);
            throw $e;
        }
    }

    public function testFatalLogsAtFatalLevel(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        try {
            Log::fatal('goodbye');
        } catch (\RuntimeException) {
            // Expected - we just want to verify the log output before the exception
        }

        $content = $this->getContent();
        $this->assertStringContainsString('goodbye', $content);
        $this->assertStringContainsString('FTL', $content);
    }

    public function testPrintOutputsAtInfoLevel(): void
    {
        // Create logger with Info as minimum level - print should work
        $log = $this->logger(Level::Info);
        Log::setLogger($log);

        Log::print('always printed', ['type' => 'status']);

        $content = $this->getContent();
        $this->assertStringContainsString('always printed', $content);
        $this->assertStringContainsString('type=status', $content);
    }

    public function testPrintBypassesLevelFilter(): void
    {
        // Create logger with Error as minimum level - print should still appear
        $log = $this->logger(Level::Error);
        Log::setLogger($log);

        Log::print('should appear');

        $content = $this->getContent();
        $this->assertStringContainsString('should appear', $content);
    }

    public function testRestoreTerminalEmitsExactSequencesOnlyForTerminalStreams(): void
    {
        // Pipe stderr is not a TTY: the unforced call must be swallowed by the
        // guard entirely, while force=true emits the DECSET pair byte-exactly.
        // Pinning the shape here gave the capability gate (SL-30) a slot;
        // with the gate in, the child pins TERM to a capable value so the
        // pair is deterministic regardless of the runner's ambient TERM.
        $result = StderrNoticeCapture::run(<<<'CODE'
            \putenv('TERM=xterm-256color');
            \SugarCraft\Log\Log::restoreTerminal();
            \SugarCraft\Log\Log::restoreTerminal(true);
            CODE);

        $this->assertSame(0, $result['exitCode']);
        // Exactly one altscreen-exit + cursor-show; a guardless build would double
        // the bytes, a force-ignoring build would emit nothing.
        $this->assertSame("\x1b[?1049l\x1b[?25h", $result['stderr']);
    }

    /**
     * Capability arm (SL-30): on TERM=dumb the altscreen DECSET is dropped
     * while the universal cursor-show survives — the byte that matters is
     * never held hostage by the byte that is optional.
     */
    public function testRestoreTerminalSkipsTheAltscreenDecsetOnDumbTerminals(): void
    {
        $result = StderrNoticeCapture::run(<<<'CODE'
            \putenv('TERM=dumb');
            \SugarCraft\Log\Log::restoreTerminal(true);
            CODE);

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame("\x1b[?25h", $result['stderr']);
    }

    public function testInstallPanicHandlerSetsExceptionHandler(): void
    {
        // Two installs must leave the SECOND wrapper live: install captures
        // once (m5/noop probe), then overwrites its own slot, so exactly one
        // report prints per exception and the most recent formatter governs.
        // The old null-or-is_callable tautology accepted any slot state,
        // including the capture no-op itself - which is also defined in
        // src/Log.php and defeated a filename-only probe.
        $first = PanicFormatter::pretty();
        $second = PanicFormatter::plain();
        Log::installPanicHandler($first);
        Log::installPanicHandler($second);
        $slotContents = \set_exception_handler(static fn () => null);
        \restore_exception_handler();
        $this->assertIsCallable($slotContents);
        $used = (new \ReflectionFunction($slotContents))->getClosureUsedVariables();
        $this->assertArrayHasKey('formatter', $used, 'the live handler must be an install wrapper');
        $this->assertSame($second, $used['formatter'], 'the live wrapper must bind the most recent formatter');
        $this->assertNotSame($first, $used['formatter']);
    }

    public function testInstallPanicHandlerWithNullFormatter(): void
    {
        // Should not throw - uses default formatter
        Log::installPanicHandler(null);

        // Verify it set up a handler (no exception thrown)
        $handler = \set_exception_handler(function () {});
        \restore_exception_handler();

        $this->assertNotNull($handler);
    }

    public function testInstallPanicHandlerWithShowLocals(): void
    {
        Log::installPanicHandler(null, true);

        $handler = \set_exception_handler(function () {});
        \restore_exception_handler();

        $this->assertNotNull($handler);
    }

    public function testInstallPanicHandlerWithRedactPaths(): void
    {
        Log::installPanicHandler(null, false, ['/secret/path']);

        $handler = \set_exception_handler(function () {});
        \restore_exception_handler();

        $this->assertNotNull($handler);
    }

    public function testInstallPanicHandlerRefusesKnobsAlongsideExplicitFormatter(): void
    {
        // [SL-08] silent-arg-drop trap: a formatter you pass has already
        // decided locals/redaction; non-default knobs alongside it are a
        // bug-shaped call and must throw, naming the knob it refuses. Both
        // arms run in one test (expectException would abort the loop at the
        // first throw, silently skipping the redactPaths arm).
        $arms = [
            ['showLocals', [PanicFormatter::plain(), true]],
            ['redactPaths', [PanicFormatter::plain(), false, ['/etc/secrets']]],
        ];

        foreach ($arms as [$knob, $args]) {
            try {
                Log::installPanicHandler(...$args);
                $this->fail('expected InvalidArgumentException refusing the silently-ignored ' . $knob);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('$' . $knob, $e->getMessage());
            }
        }
    }

    public function testContextIsMergedInLogMessages(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::info('test message', ['extra' => 'context']);

        $content = $this->getContent();
        $this->assertStringContainsString('test message', $content);
        $this->assertStringContainsString('extra=context', $content);
    }

    public function testAllLogLevelsFromFacade(): void
    {
        $log = $this->logger(Level::Debug);
        Log::setLogger($log);

        Log::debug('debug msg');
        Log::info('info msg');
        Log::warn('warn msg');
        Log::error('error msg');

        $content = $this->getContent();
        $this->assertStringContainsString('debug msg', $content);
        $this->assertStringContainsString('info msg', $content);
        $this->assertStringContainsString('warn msg', $content);
        $this->assertStringContainsString('error msg', $content);
        $this->assertStringContainsString('DBG', $content);
        $this->assertStringContainsString('INF', $content);
        $this->assertStringContainsString('WRN', $content);
        $this->assertStringContainsString('ERR', $content);
    }

    public function testStaticFormattedProxiesWriteSprintfLines(): void
    {
        Log::setLogger($this->logger());

        Log::debugf('dbg %s', 'one');
        Log::infof('inf %s', 'two');
        Log::warnf('wrn %d', ['k' => 'v'], 3); // array first-arg = context slot, like the instance helper
        Log::errorf('err %s', 'four');

        $content = $this->getContent();
        $this->assertStringContainsString('dbg one', $content);
        $this->assertStringContainsString('inf two', $content);
        $this->assertStringContainsString('wrn 3', $content);
        $this->assertStringContainsString('k=v', $content);
        $this->assertStringContainsString('err four', $content);
    }

    public function testStaticFatalfWritesThenThrowsTypedException(): void
    {
        Log::setLogger($this->logger());

        try {
            Log::fatalf('ftl %s', 'five');
            $this->fail('fatalf must throw after writing');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ftl five', $e->getMessage());
        }

        $content = $this->getContent();
        $this->assertStringContainsString('FTL ftl five', $content);
    }

    public function testRemovePanicHandlerRestoresTheForeignHandler(): void
    {
        $result = StderrNoticeCapture::run(<<<'CODE'
            \set_exception_handler(static function (\Throwable $e): void {
                \fwrite(\STDERR, 'FOREIGN-SAW:' . $e->getMessage() . "\n");
            });
            \SugarCraft\Log\Log::installPanicHandler(\SugarCraft\Log\PanicFormatter::plain());
            \SugarCraft\Log\Log::removePanicHandler();
            throw new \RuntimeException('boom-x');
            CODE);

        $this->assertStringContainsString('FOREIGN-SAW:boom-x', $result['stderr']);
        $this->assertStringNotContainsString('report rendered by sugar-log', $result['stderr']);
    }

    public function testRemovePanicHandlerWithoutInstallIsANoOp(): void
    {
        // Without the push-counter guard, a stray remove would pop the
        // host's handler — the foreign echo below proves nothing was stolen.
        $result = StderrNoticeCapture::run(<<<'CODE'
            \set_exception_handler(static function (\Throwable $e): void {
                \fwrite(\STDERR, 'FOREIGN-SAW:' . $e->getMessage() . "\n");
            });
            \SugarCraft\Log\Log::removePanicHandler();
            throw new \RuntimeException('boom-y');
            CODE);

        $this->assertStringContainsString('FOREIGN-SAW:boom-y', $result['stderr']);
    }

    public function testReinstallOverBuiltinDefaultReportsOnce(): void
    {
        // No foreign handler: the first install captures null. The old
        // `panicPrevious ?? capture` test could not distinguish re-install
        // from first-install then, and chained the stale wrapper — two
        // banners per exception.
        $result = StderrNoticeCapture::run(<<<'CODE'
            \SugarCraft\Log\Log::installPanicHandler(\SugarCraft\Log\PanicFormatter::plain());
            \SugarCraft\Log\Log::installPanicHandler(\SugarCraft\Log\PanicFormatter::plain());
            throw new \RuntimeException('twice-y');
            CODE);

        $this->assertSame(1, \substr_count($result['stderr'], 'report rendered by sugar-log'));
        $this->assertStringContainsString('twice-y', $result['stderr']);
    }

    public function testChainedPreviousHandlerStillRunsAfterTheReport(): void
    {
        // sugar-log-011 pin: the captured "previous" is not just stored — it
        // runs. A host handler set before install keeps its seat behind the
        // panic report, so integrations (error pages, sentries) still fire.
        $result = StderrNoticeCapture::run(<<<'CODE'
            \set_exception_handler(static function (\Throwable $e): void {
                \fwrite(\STDERR, 'PREV-SAW:' . $e->getMessage() . "\n");
            });
            \SugarCraft\Log\Log::installPanicHandler(\SugarCraft\Log\PanicFormatter::plain());
            throw new \RuntimeException('chained');
            CODE);

        $stderr = $result['stderr'];
        $this->assertStringContainsString('report rendered by sugar-log', $stderr);
        $this->assertStringContainsString('PREV-SAW:chained', $stderr);
        $banner = \strpos($stderr, 'report rendered by sugar-log');
        $chained = \strpos($stderr, 'PREV-SAW:');
        $this->assertIsInt($banner);
        $this->assertIsInt($chained);
        $this->assertLessThan($chained, $banner, 'the report must precede the chained handler');
    }

    public function testRemoveLeavesExactlyTheHandlerInstallFound(): void
    {
        // Stack-neutral contract proof (sugar-log-011 order-fragility): after
        // install+remove the runner's handler is back in the slot — a later
        // throw in the same process is rendered by the host, never by a
        // leaked sugar-log banner. PHP exposes no handler getter, so the top
        // slot is peeked the same push-and-pop way the sibling probes do.
        $peek = static function (): mixed {
            $slot = \set_exception_handler(static function (): void {});
            \restore_exception_handler();
            return $slot;
        };

        $foreign = static function (): void {};
        \set_exception_handler($foreign); // now the top slot: what install will find
        try {
            Log::installPanicHandler(PanicFormatter::plain());
            $this->assertNotSame($foreign, $peek(), 'install must take the slot');

            Log::removePanicHandler();
            $this->assertSame($foreign, $peek(), 'remove must restore the exact handler found at install');
        } finally {
            \restore_exception_handler(); // pop the probe, leave the runner's state as found
        }
    }
}

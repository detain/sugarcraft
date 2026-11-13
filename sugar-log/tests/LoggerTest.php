<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use SugarCraft\Log\Log;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Level;
use SugarCraft\Log\Support\Stdio;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\Formatter\TextFormatter;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private $tempFile;
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/sugar-log-test-' . \uniqid() . '.log';
        $this->tempFile = \fopen($this->tempPath, 'w');
    }

    protected function tearDown(): void
    {
        // Contract, not file-order luck: this class hands the process-global
        // facade a logger in testStaticInfoLogsToGlobalLogger, and that
        // logger's stream is closed at test end. Without the reset, the
        // facade keeps a dead handle until some other file happens to touch
        // it — whichever test runs next pays a write failure against a
        // resource it never opened.
        Log::reset();

        if (\is_resource($this->tempFile)) {
            \fclose($this->tempFile);
        }
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
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

    public function testNewIsTheConstructorForwarderNotACopiedTwin(): void
    {
        // [SL-33] Structural dedupe pin: new() takes everything as one
        // variadic spread — no hand-copied ten-parameter list that can
        // silently reorder against the constructor.
        $params = (new \ReflectionMethod(Logger::class, 'new'))->getParameters();
        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->isVariadic(), 'new() must spread-forward');

        // Behaviour: identical named arguments through both doors produce
        // byte-identical records.
        $viaNew = \fopen('php://memory', 'r+');
        $viaCtor = \fopen('php://memory', 'r+');
        Logger::new(level: Level::Debug, prefix: 'svc', reportTimestamp: false, stream: $viaNew)->debug('same');
        (new Logger(level: Level::Debug, prefix: 'svc', reportTimestamp: false, stream: $viaCtor))->debug('same');
        \rewind($viaNew);
        \rewind($viaCtor);
        $newBytes = \stream_get_contents($viaNew);
        $ctorBytes = \stream_get_contents($viaCtor);
        \fclose($viaNew);
        \fclose($viaCtor);
        $this->assertSame($ctorBytes, $newBytes);
        $this->assertStringContainsString('DBG svc same', $newBytes);

        // Fail-fast survives the spread: an unknown named argument still
        // meets the constructor's real signature.
        try {
            Logger::new(nope: 1, stream: \fopen('php://memory', 'r+'));
            $this->fail('unknown named parameter must throw');
        } catch (\Error $e) {
            $this->assertStringContainsString('nope', $e->getMessage());
        }

        // One time-format literal, one owner: Logger aliases TextFormatter.
        $this->assertSame('Y/m/d H:i:s', Logger::DEFAULT_FORMAT);
        $this->assertSame(
            \constant(TextFormatter::class . '::DEFAULT_TIME_FORMAT'),
            Logger::DEFAULT_FORMAT
        );
    }

    public function testInfoEmitsMessage(): void
    {
        $log = $this->logger();
        $log->info('hello');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('hello', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testDebugFilteredWhenMinLevelIsInfo(): void
    {
        $log = $this->logger(Level::Info);
        $log->debug('should not appear');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertSame('', $content);
    }

    public function testWarnEmitsWarningLevel(): void
    {
        $log = $this->logger();
        $log->warn('careful');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('WRN', $content);
        $this->assertStringContainsString('careful', $content);
    }

    public function testErrorEmitsErrorLevel(): void
    {
        $log = $this->logger();
        $log->error('oops');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('ERR', $content);
    }

    public function testFatalCallsExit(): void
    {
        $log = $this->logger();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fatal log: bye');
        $log->fatal('bye');
    }

    public function testFatalThrowsTypedExceptionWithSweptSingleLineMessage(): void
    {
        // [SEC-01/PHP-01] The fatal throw is the one emit-path sink that shows
        // raw caller text on the crash display: control bytes must not survive
        // (ESC/OSC would move the cursor, set the title, or forge lines) and
        // newlines must not break the one-record-one-line contract.
        $log = $this->logger();

        try {
            $log->fatal("bye\nplease\x1b]0;title\x07 and\ttabs");
            $this->fail('expected FatalLoggedException');
        } catch (\SugarCraft\Log\FatalLoggedException $e) {
            // Typed class (bridge detects via instanceof, not string equality).
            $message = $e->getMessage();
            $this->assertStringContainsString('fatal log:', $message);
            $this->assertStringContainsString('bye', $message);
            $this->assertStringContainsString('please', $message);
            $this->assertStringNotContainsString("\x1b", $message);
            $this->assertStringNotContainsString("\x07", $message);
            $this->assertStringNotContainsString("\n", $message);
            $this->assertStringNotContainsString("\t", $message);
            $this->assertSame($message, str_replace(["\n", "\r", "\t"], ' ', $message), 'message must be single-line');
        }
    }

    public function testWithCreatesChildWithExtraFields(): void
    {
        $log = $this->logger();
        $child = $log->with(['batch' => 2, 'user' => 'chef']);
        $child->info('started', ['item' => 'cookies']);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('batch=2', $content);
        $this->assertStringContainsString('user=chef', $content);
        $this->assertStringContainsString('item=cookies', $content);
    }

    public function testChildDoesNotAffectParentFields(): void
    {
        $log = $this->logger();
        $child = $log->with(['batch' => 2]);
        $log->info('parent only');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('parent only', $content);
        $this->assertStringNotContainsString('batch', $content);
    }

    public function testWithPrefixCreatesChildWithPrefix(): void
    {
        $log = $this->logger();
        $prefixed = $log->withPrefix('🍪');
        $prefixed->info('baking');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('🍪', $content);
    }

    public function testWithFormatterCreatesChildWithFormatter(): void
    {
        $log = $this->logger();
        $json = $log->withFormatter(new JsonFormatter(reportTimestamp: false));
        $json->info('test');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $decoded = \json_decode(\trim($content), true);
        $this->assertIsArray($decoded);
        $this->assertSame('INFO', $decoded['level']);
        $this->assertSame('test', $decoded['msg']);
    }

    public function testJsonFormatterEmitsValidJson(): void
    {
        $log = $this->logger();
        $log = $log->withFormatter(new JsonFormatter(reportTimestamp: false));
        $log->info('bake complete', ['cookies' => 24, 'ok' => true]);
        \fclose($this->tempFile);

        $content = \trim(\file_get_contents($this->tempPath));
        $decoded = \json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertSame('INFO', $decoded['level']);
        $this->assertSame('bake complete', $decoded['msg']);
        $this->assertSame(24, $decoded['cookies']);
        $this->assertTrue($decoded['ok']);
    }

    public function testLogfmtFormatterEmitsKeyValuePairs(): void
    {
        $log = $this->logger();
        $log = $log->withFormatter(new LogfmtFormatter(reportTimestamp: false));
        $log->info('hello', ['name' => 'world', 'n' => 42]);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('level=INFO', $content);
        $this->assertStringContainsString('msg=hello', $content);
        $this->assertStringContainsString('name=world', $content);
        $this->assertStringContainsString('n=42', $content);
    }

    public function testSetMinLevelFiltersSubsequentLogs(): void
    {
        $log = $this->logger(Level::Debug);
        $log->debug('visible');
        $log->setMinLevel(Level::Error);
        $log->debug('hidden');
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('visible', $content);
        $this->assertStringNotContainsString('hidden', $content);
    }

    public function testPrintfStyleFormattedMessage(): void
    {
        $log = $this->logger();
        $log->infof('baking %s at %d°F', [], 'cookies', 375);
        \fclose($this->tempFile);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('cookies', $content);
        $this->assertStringContainsString('375', $content);
    }

    public function testPrintBypassesLevelFilter(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(stream: $stream, level: Level::Error, reportTimestamp: false, reportCaller: false);
        $log->print('should appear');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('should appear', $content);
    }

    public function testStaticInfoLogsToGlobalLogger(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(stream: $stream, level: Level::Debug, reportTimestamp: false);
        Log::setLogger($log);

        Log::info('global hello', ['key' => 'val']);

        // [SL-11] reset the facade BEFORE closing the stream it points at —
        // the handle must not outlive the resource, not even for the run of
        // the next test file.
        Log::reset();
        $this->assertNotSame($log, Log::default(), 'reset returns a fresh default');

        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('global hello', $content);
        $this->assertStringContainsString('key=val', $content);
    }

    public function testDefaultTimestampFormatIsRealDate(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: true,
            reportCaller: false,
            stream: $stream,
        );
        $log->info('timestamp test');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        // Verify the timestamp is a real date in Y/m/d H:i:s format
        $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}/', $content);
    }

    public function testSetReportTimestampKeepsCustomFormatter(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $jsonFormatter = new JsonFormatter(true);
        $log = Logger::new(
            formatter: $jsonFormatter,
            level: Level::Debug,
            reportTimestamp: true,
            reportCaller: false,
            stream: $stream,
        );
        // Calling setReportTimestamp(false) should NOT destroy the JsonFormatter
        $log->setReportTimestamp(false);
        $log->info('json still');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        // Must still be valid JSON with level field
        $this->assertStringContainsString('"level"', $content);
        $this->assertStringContainsString('json still', $content);
    }

    public function testSetReportCallerPreservesCustomFormatter(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $jsonFormatter = new JsonFormatter(true);
        $log = Logger::new(
            formatter: $jsonFormatter,
            level: Level::Debug,
            reportTimestamp: true,
            reportCaller: false,
            stream: $stream,
        );
        // Calling setReportCaller(true) should NOT destroy the JsonFormatter
        $log->setReportCaller(true);
        $log->info('json still');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        // Must still be valid JSON with level field
        $this->assertStringContainsString('"level"', $content);
        $this->assertStringContainsString('json still', $content);
    }

    public function testAdoptedTextFormatterKeepsCallerAndTimeFormat(): void
    {
        // [PHP-02] setFormatter() used to adopt a bare TextFormatter verbatim:
        // the logger's caller reporting and custom time format silently
        // vanished (the inverse of setStyles/setPartsOrder rewiring the live
        // formatter). Adoption now re-seeds presentation state from the logger.
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            formatter: new TextFormatter(), // [PHP-02] bare formatter via the ctor seam
            level: Level::Debug,
            reportTimestamp: true,
            timeFormat: 'Y-m-d',
            reportCaller: true,
            stream: $stream,
        );

        $log->setFormatter(new TextFormatter()); // bare defaults pre-fix
        $log->info('adopt');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('adopt', $content);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $content, 'custom time format must survive adoption');
        $this->assertStringContainsString('LoggerTest.php', $content, 'caller column must survive adoption');
    }

    public function testConstructorSeamAloneKeepsLoggerStateOnBareTextFormatter(): void
    {
        // [PHP-02] The ctor adoption must not depend on a later setFormatter()
        // to fix its state: writing straight after Logger::new(formatter: bare)
        // still carries caller and time format.
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            formatter: new TextFormatter(),
            level: Level::Debug,
            reportTimestamp: true,
            timeFormat: 'Y-m-d',
            reportCaller: true,
            stream: $stream,
        );

        $log->info('ctor only');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('ctor only', $content);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $content, 'custom time format must survive ctor adoption');
        $this->assertStringContainsString('LoggerTest.php', $content, 'caller column must survive ctor adoption');
    }

    public function testWithFormatterAdoptsTheSameSeam(): void
    {
        // [PHP-02] The immutable path must behave like setFormatter(): the
        // child logger's caller + timestamp state rides into the new formatter.
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(level: Level::Debug, reportTimestamp: true, reportCaller: true, stream: $stream);

        $child = $log->withFormatter(new TextFormatter());
        $child->info('child');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('child', $content);
        $this->assertMatchesRegularExpression('/\d{4}\/\d{2}\/\d{2}/', $content);
        $this->assertStringContainsString('LoggerTest.php', $content, 'caller column must survive withFormatter adoption');
    }

    public function testSetOutputRedirectsWrites(): void
    {
        // Create initial logger with first temp file
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $stream,
        );
        $log->info('before redirect');
        \fclose($stream);

        // Create a new temp file and redirect output there
        $newPath = \sys_get_temp_dir() . '/sugar-log-redirect-' . \uniqid() . '.log';
        $newStream = \fopen($newPath, 'w');
        $log->setOutput($newStream);
        $log->info('after redirect');
        \fclose($newStream);

        // Verify first file has 'before' only
        $beforeContent = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('before redirect', $beforeContent);
        $this->assertStringNotContainsString('after redirect', $beforeContent);

        // Verify second file has 'after' only
        $afterContent = \file_get_contents($newPath);
        $this->assertStringContainsString('after redirect', $afterContent);
        $this->assertStringNotContainsString('before redirect', $afterContent);

        \unlink($newPath);
    }

    public function testWithOutputReturnsCloneWithNewStream(): void
    {
        // Create logger with first temp file
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $stream,
        );
        $log->info('original');
        \fclose($stream);

        // Create a clone with a different stream
        $newPath = \sys_get_temp_dir() . '/sugar-log-clone-' . \uniqid() . '.log';
        $newStream = \fopen($newPath, 'w');
        $cloned = $log->withOutput($newStream);
        $cloned->info('clone');
        \fclose($newStream);

        // Verify original logger wrote to its stream
        $originalContent = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('original', $originalContent);
        $this->assertStringNotContainsString('clone', $originalContent);

        // Verify cloned logger wrote to its stream
        $clonedContent = \file_get_contents($newPath);
        $this->assertStringContainsString('clone', $clonedContent);
        $this->assertStringNotContainsString('original', $clonedContent);

        \unlink($newPath);
    }

    public function testSetOutputThrowsOnInvalidInput(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $stream,
        );
        \fclose($stream);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream must be a valid resource');
        $log->setOutput('not a resource');
    }

    // -------------------------------------------------------------------------
    // [PERF] enabled() level gate — *f helpers must not sprintf when filtered
    // -------------------------------------------------------------------------

    public function testEnabledReflectsMinLevel(): void
    {
        $log = $this->logger(Level::Warn);

        $this->assertFalse($log->enabled(Level::Debug));
        $this->assertFalse($log->enabled(Level::Info));
        $this->assertTrue($log->enabled(Level::Warn));
        $this->assertTrue($log->enabled(Level::Error));
        $this->assertTrue($log->enabled(Level::Fatal));
    }

    public function testFilteredDebugfDoesNotRunSprintf(): void
    {
        // A __toString spy proves whether sprintf('%s', $spy) actually executed.
        $spy = new class {
            public int $calls = 0;
            public function __toString(): string
            {
                $this->calls++;
                return 'x';
            }
        };

        $log = $this->logger(Level::Error); // Debug is below the threshold
        $log->debugf('%s', [], $spy);
        \fclose($this->tempFile);

        $this->assertSame(0, $spy->calls, 'sprintf must not run for a filtered-out level');
        $this->assertSame('', \file_get_contents($this->tempPath));
    }

    public function testEnabledDebugfStillRunsSprintf(): void
    {
        $spy = new class {
            public int $calls = 0;
            public function __toString(): string
            {
                $this->calls++;
                return 'baked';
            }
        };

        $log = $this->logger(Level::Debug); // Debug is enabled
        $log->debugf('%s', [], $spy);
        \fclose($this->tempFile);

        $this->assertSame(1, $spy->calls);
        $this->assertStringContainsString('baked', \file_get_contents($this->tempPath));
    }

    // -------------------------------------------------------------------------
    // withMinLevel - creates child logger with different level threshold
    // -------------------------------------------------------------------------

    public function testWithMinLevelCreatesChildWithNewThreshold(): void
    {
        $log = $this->logger(Level::Debug);
        $child = $log->withMinLevel(Level::Error);

        // Parent still logs Debug
        $log->debug('parent debug');
        \fclose($this->tempFile);
        $parentContent = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('parent debug', $parentContent);

        // Reopen for child
        $this->tempFile = \fopen($this->tempPath, 'w');
        // Child filters out Debug
        $child->debug('child debug should not appear');
        \fclose($this->tempFile);
        $childContent = \file_get_contents($this->tempPath);
        $this->assertSame('', $childContent);
    }

    public function testWithMinLevelDoesNotAffectParent(): void
    {
        $log = $this->logger(Level::Debug);
        $child = $log->withMinLevel(Level::Error);

        // Child is at Error threshold
        $this->assertFalse($child->enabled(Level::Debug));
        // Parent is still at Debug threshold
        $this->assertTrue($log->enabled(Level::Debug));
    }

    // -------------------------------------------------------------------------
    // setStyles / styles - text formatter styling
    // -------------------------------------------------------------------------

    public function testStylesReturnsStylesObject(): void
    {
        $log = $this->logger();
        $styles = $log->styles();
        $this->assertInstanceOf(\SugarCraft\Log\Styles::class, $styles);
    }

    public function testSetStylesUpdatesTextFormatter(): void
    {
        $log = $this->logger();
        // A fresh instance — Styles::default() is the process-wide singleton the
        // logger already rides, so identity would prove nothing, and mutating it
        // would repaint every other logger in the suite.
        $newStyles = new \SugarCraft\Log\Styles();
        $log->setStyles($newStyles);

        // setStyles must swap the wired TextFormatter for one carrying the new
        // styles object; dropping the reseed would silently dead-end the knob.
        $formatter = (new \ReflectionProperty(Logger::class, 'formatter'))->getValue($log);
        $this->assertInstanceOf(TextFormatter::class, $formatter);
        $this->assertSame(
            $newStyles,
            (new \ReflectionProperty(TextFormatter::class, 'styles'))->getValue($formatter),
            'the wired formatter must carry the object passed to setStyles',
        );
    }

    // -------------------------------------------------------------------------
    // setPartsOrder / withPartsOrder - text formatter part ordering
    // -------------------------------------------------------------------------

    public function testWithPartsOrderCreatesChildWithReorderedParts(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $stream,
        );

        // Create child with custom parts order (message first, then level)
        $child = $log->withPartsOrder(new \SugarCraft\Log\PartsOrder([
            \SugarCraft\Log\PartsOrder::PART_MESSAGE,
            \SugarCraft\Log\PartsOrder::PART_LEVEL,
        ]));

        $child->info('test message');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        // Message should appear before level indicator
        $msgPos = \strpos($content, 'test message');
        $levelPos = \strpos($content, 'INF');
        $this->assertNotFalse($msgPos);
        $this->assertNotFalse($levelPos);
        $this->assertLessThan($levelPos, $msgPos);
    }

    public function testSetPartsOrderUpdatesTextFormatter(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $stream,
        );

        $log->setPartsOrder(\SugarCraft\Log\PartsOrder::default());
        $log->info('test');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('INF', $content);
        $this->assertStringContainsString('test', $content);
    }

    // -------------------------------------------------------------------------
    // printf - bypasses level filter
    // -------------------------------------------------------------------------

    public function testPrintfBypassesLevelFilter(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Error, // Very high threshold
            reportTimestamp: false,
            reportCaller: false,
            stream: $stream,
        );

        // Pass arg as variadic param, not in context array (printf doesn't forward context to sprintf)
        $log->printf('always shows %s', [], 'arg');
        \fclose($stream);

        $content = \file_get_contents($this->tempPath);
        $this->assertStringContainsString('always shows', $content);
        $this->assertStringContainsString('arg', $content);
    }

    // -------------------------------------------------------------------------
    // withOutput throws on invalid stream
    // -------------------------------------------------------------------------

    public function testWithOutputThrowsOnInvalidInput(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $stream,
        );
        \fclose($stream);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream must be a valid resource');
        $log->withOutput('not a resource');
    }

    // -------------------------------------------------------------------------
    // Constructor stream validation (m3) + error-stream routing (M8)
    // -------------------------------------------------------------------------

    public function testConstructorRejectsNonResourceStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stream must be a valid resource');
        Logger::new(stream: 'not-a-resource');
    }

    public function testConstructorAcceptsNullStreamDefaultingToStdout(): void
    {
        // The STDOUT default must still pass the boundary guard untouched.
        $log = Logger::new(reportTimestamp: false);
        $this->assertInstanceOf(Logger::class, $log);
    }

    public function testStdioHelperReturnsProcessHandlesIdentityAndCached(): void
    {
        // [PORT-01] Shape pin: under CLI the lazy resolver must hand back the
        // process-bound constants themselves (byte-identical CLI behavior),
        // and repeat calls must return the same cached resource.
        $this->assertSame(\STDOUT, Stdio::out());
        $this->assertSame(\STDERR, Stdio::err());
        $this->assertSame(Stdio::out(), Stdio::out());
        $this->assertSame(Stdio::err(), Stdio::err());

        // The constructor default flows through the same resolver.
        $log = Logger::new(reportTimestamp: false);
        $stream = new \ReflectionProperty(Logger::class, 'stream');
        $stream->setAccessible(true);
        $this->assertSame(\STDOUT, $stream->getValue($log));
    }

    public function testStdioReopensWhenTheCachedHandleWasClosed(): void
    {
        // [PORT-01] The announce path may see a freed handle; resolution must
        // then re-open the channel instead of staying dead. A non-resource
        // sentinel stands in for "slot holds a dead handle" — dangling
        // resource ids get recycled inside a long test process, so relying on
        // fclose() semantics here would be flaky by construction.
        $cache = new \ReflectionProperty(Stdio::class, 'err');
        $cache->setAccessible(true);
        $cache->setValue(null, 'pretend-closed-handle');

        $resolved = Stdio::err();
        $this->assertIsResource($resolved, 'a dead cache entry must trigger re-resolution');

        // Restore CLI identity for every later test.
        $cache->setValue(null, null);
        $this->assertSame(\STDERR, Stdio::err());
    }

    public function testErrorStreamRoutesSevereRecordsOnly(): void
    {
        $mainPath = \sys_get_temp_dir() . '/sugar-log-main-' . \uniqid() . '.log';
        $errPath = \sys_get_temp_dir() . '/sugar-log-err-' . \uniqid() . '.log';
        $main = \fopen($mainPath, 'w');
        $err = \fopen($errPath, 'w');
        $this->assertIsResource($main);
        $this->assertIsResource($err);

        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $main,
            errorStream: $err,
        );
        $log->debug('quiet-debug');
        $log->info('gentle-info');
        $log->warn('rising-warn');
        $log->error('loud-error');
        \fclose($main);
        \fclose($err);

        $mainContent = (string) \file_get_contents($mainPath);
        $errContent = (string) \file_get_contents($errPath);
        \unlink($mainPath);
        \unlink($errPath);

        $this->assertStringContainsString('quiet-debug', $mainContent);
        $this->assertStringContainsString('gentle-info', $mainContent);
        $this->assertStringContainsString('rising-warn', $mainContent);
        $this->assertStringNotContainsString('loud-error', $mainContent);
        $this->assertStringContainsString('loud-error', $errContent);
    }

    public function testErrorStreamAtLowersTheRoutingThreshold(): void
    {
        $mainPath = \sys_get_temp_dir() . '/sugar-log-main-' . \uniqid() . '.log';
        $errPath = \sys_get_temp_dir() . '/sugar-log-err-' . \uniqid() . '.log';
        $main = \fopen($mainPath, 'w');
        $err = \fopen($errPath, 'w');

        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            stream: $main,
            errorStream: $err,
            errorStreamAt: Level::Warn,
        );
        $log->info('calm-info');
        $log->warn('hot-warn');
        \fclose($main);
        \fclose($err);

        $mainContent = (string) \file_get_contents($mainPath);
        $errContent = (string) \file_get_contents($errPath);
        \unlink($mainPath);
        \unlink($errPath);

        $this->assertStringContainsString('calm-info', $mainContent);
        $this->assertStringNotContainsString('hot-warn', $mainContent);
        $this->assertStringContainsString('hot-warn', $errContent);
    }

    public function testWithErrorStreamNullRestoresSingleStream(): void
    {
        $singlePath = \sys_get_temp_dir() . '/sugar-log-single-' . \uniqid() . '.log';
        $single = \fopen($singlePath, 'w');

        $log = Logger::new(
            reportTimestamp: false,
            stream: $this->tempFile,
            errorStream: $single,
        )->withErrorStream(null);

        $log->error('back-to-one');
        \fclose($single);

        $mainContent = (string) \file_get_contents($this->tempPath);
        $singleContent = (string) \file_get_contents($singlePath);
        \unlink($singlePath);

        $this->assertStringContainsString('back-to-one', $mainContent);
        $this->assertSame('', $singleContent);
    }

    // [M14] The formatted helpers take arguments in the context slot when
    // what sits there is not an array — infof('count %d', 7) must format,
    // not TypeError.
    public function testFormattedHelperAcceptsArgsInContextSlot(): void
    {
        $log = $this->logger();
        $log->infof('count %d', 7);
        \fclose($this->tempFile);

        $content = (string) \file_get_contents($this->tempPath);
        $this->assertStringContainsString('count 7', $content);
    }

    public function testFormattedHelperKeepsRealContextAlongsideArgs(): void
    {
        $log = $this->logger();
        $log->infof('count %d', ['id' => 1], 7);
        \fclose($this->tempFile);

        $content = (string) \file_get_contents($this->tempPath);
        $this->assertStringContainsString('count 7', $content);
        $this->assertStringContainsString('id=1', $content);
    }

    public function testPrintfAcceptsArgsInContextSlot(): void
    {
        $log = $this->logger();
        $log->printf('raw %s', 'x');
        \fclose($this->tempFile);

        $content = (string) \file_get_contents($this->tempPath);
        $this->assertStringContainsString('raw x', $content);
    }
}

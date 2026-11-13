<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\PsrBridge;
use SugarCraft\Log\Tests\Support\StderrNoticeCapture;
use SugarCraft\Log\Hook\HookRegistry;

final class PsrBridgeTest extends TestCase
{
    private string $tempPath;
    private $tempFile;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/sugar-log-psr-test-' . \uniqid() . '.log';
        $this->tempFile = \fopen($this->tempPath, 'w');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->tempFile)) {
            \fclose($this->tempFile);
        }
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
    }

    public function testInfoLogsToWrappedLogger(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->info('hello psr');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('hello psr', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testEmergencyMapsToFatal(): void
    {
        // [C1 flip, 2026-11] PSR-3 severity methods must never propagate a
        // Throwable. The wrapped Logger's deliberate Fatal RuntimeException is
        // swallowed by the bridge AFTER the record is written. Pre-fix this
        // pin expected the throw to escape the :void bridge method.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->emergency('panic');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('panic', $content);
        $this->assertStringContainsString('FTL', $content);
    }

    public function testDebugMapsToDebugLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->debug('debug message');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('debug message', $content);
        $this->assertStringContainsString('DBG', $content);
    }

    public function testWarningMapsToWarnLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->warning('careful');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('careful', $content);
        $this->assertStringContainsString('WRN', $content);
    }

    public function testErrorMapsToErrorLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->error('oops');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('oops', $content);
        $this->assertStringContainsString('ERR', $content);
    }

    public function testPsrLevelStringAcceptedByLogMethod(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->log(LogLevel::INFO, 'via log method');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('via log method', $content);
    }

    public function testAlertMapsToFatal(): void
    {
        // [C1 flip, 2026-11] See testEmergencyMapsToFatal: the record must
        // land and the bridge must return cleanly — no escape hatch expected.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->alert('alert message');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('alert message', $content);
        $this->assertStringContainsString('FTL', $content);
    }

    public function testCriticalMapsToFatal(): void
    {
        // [C1 flip, 2026-11] Same contract as alert/emergency.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->critical('critical message');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('critical message', $content);
        $this->assertStringContainsString('FTL', $content);
    }

    public function testNoticeMapsToInfoLevel(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $bridge->notice('notice message');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('notice message', $content);
        $this->assertStringContainsString('INF', $content);
    }

    public function testUnknownPsrLevelRaisesInvalidArgumentException(): void
    {
        // [M13 flip, 2026-11] Silently relabeling an unrecognized level as Info
        // buries caller bugs (and hostile spammers) in fake INFO records.
        // PSR-3 sanctions InvalidArgumentException from log(); nothing else
        // on this class can receive a free-form level. Pre-fix this pin
        // asserted the Info fallback.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $this->expectException(\Psr\Log\InvalidArgumentException::class);
        try {
            $bridge->log('madeupstring', 'fallback message');
        } finally {
            \fclose($this->tempFile);
        }

        $this->assertSame('', \file_get_contents($this->tempPath) ?: '', 'a refused level must write nothing');
    }

    public function testLogRejectsNonStringLevels(): void
    {
        // [M13 flip, 2026-11] The old pin demanded numeric/null/array levels
        // "land on the Info fallback without TypeError". A number is not a
        // PSR-3 level: refusing is the spec-sanctioned behavior, and the
        // failure names the argument type instead of writing a mislabeled row.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        foreach ([123, 4.5, null, true, ['array']] as $hostile) {
            $threw = false;
            try {
                $bridge->log($hostile, 'never written');
            } catch (\Psr\Log\InvalidArgumentException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'log() must reject ' . \get_debug_type($hostile));
        }

        \fclose($this->tempFile);
        $this->assertSame('', \file_get_contents($this->tempPath) ?: '');
    }

    public function testUnknownLevelMessageIsClippedAndSingleLine(): void
    {
        // [N-07, sugar-docs-reviews-1104 step 27] resolveLevel interpolated the
        // raw caller string into its refusal message: a level carrying newlines
        // or escape bytes could forge extra lines and styling in any handler
        // that echoes caught messages. The refusal now quotes a single-line,
        // 64-character clip of the level. Pre-fix this pin asserts the raw
        // interpolation and must stay red without the clip.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $hostile = "in\nvalid\x1b[31m" . \str_repeat('x', 300);
        $message = null;
        try {
            $bridge->log($hostile, 'never written');
        } catch (\Psr\Log\InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
        \fclose($this->tempFile);

        $this->assertNotNull($message, 'a hostile unknown level must still be refused');
        $this->assertStringNotContainsString("\n", $message, 'the refused level must not forge extra message lines');
        $this->assertStringNotContainsString("\x1b", $message, 'the refused level must not smuggle escape bytes');
        $this->assertStringContainsString('Unknown log level', $message);
        $this->assertStringContainsString('in valid', $message, 'the clip keeps a readable head of the level');
        $this->assertLessThanOrEqual(64, \substr_count($message, 'x'), 'the quoted level must be bounded to 64 characters');
        $this->assertSame('', \file_get_contents($this->tempPath) ?: '', 'a refused level must write nothing');
    }

    public function testThrownLevelMessagesRouteThroughTheLangTable(): void
    {
        // [SL-27] Byte-exact against the English table with the slots
        // filled: a bad key in src would surface the namespaced raw
        // fallback here, and describe()/quoteLevelForMessage() keep
        // their exact insertion points.
        $en = require \dirname(__DIR__) . '/lang/en.php';
        $bridge = new PsrBridge(Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false));

        try {
            $bridge->log(42, 'never written');
            $this->fail('a non-string level must be refused');
        } catch (\Psr\Log\InvalidArgumentException $e) {
            $this->assertSame(
                \str_replace('{got}', 'int', $en['psrbridge.not_string']),
                $e->getMessage(),
            );
        }

        try {
            $bridge->log('nope', 'never written');
            $this->fail('an unknown level string must be refused');
        } catch (\Psr\Log\InvalidArgumentException $e) {
            $this->assertSame(
                \str_replace('{level}', 'nope', $en['psrbridge.unknown_level']),
                $e->getMessage(),
            );
        }

        $this->assertSame('', \file_get_contents($this->tempPath) ?: '', 'refused levels must write nothing');
    }

    public function testImplementsPsrLoggerInterface(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        // The README promise: pass it anywhere a PSR-3 logger is expected.
        // Pre-fix this class did NOT implement the interface, so the typed
        // sink below would TypeError at the call.
        $this->assertInstanceOf(\Psr\Log\LoggerInterface::class, $bridge);
        $this->writeViaInterface($bridge);

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('typed sink', $content);
    }

    private function writeViaInterface(\Psr\Log\LoggerInterface $logger): void
    {
        $logger->warning('typed sink');
    }

    public function testHostileFormatterInsideBridgeIsReportedNotPropagated(): void
    {
        // [C1] A throwing custom formatter used to escape a :void PSR-3 method
        // and kill the host request. Now: no throw, and the failure is visible.
        $logger = Logger::new(
            stream: $this->tempFile,
            level: Level::Debug,
            reportTimestamp: false,
            formatter: new class implements \SugarCraft\Log\Formatter {
                public function format(
                    Level $level,
                    string $message,
                    array $context,
                    \DateTimeImmutable $time,
                    ?string $caller,
                    ?string $prefix,
                ): string {
                    throw new \LogicException('boom');
                }
            },
        );
        $bridge = new PsrBridge($logger);

        $bridge->error('formatter explodes'); // must not throw

        $this->addToAssertionCount(1); // reaching here is the assertion
    }

    public function testThrowingMessageCastIsContained(): void
    {
        // [C1] __toString blowing up mid-log must not escape either.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $poison = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('cast exploded');
            }
        };

        $bridge->info($poison); // must not throw
        $this->addToAssertionCount(1);
    }

    public function testInternalFailuresAnnounceTheirNoticeShapesOnRealStderr(): void
    {
        // [TEST-SL-01] Third notice shape pair: PsrBridge::reportInternalFailure
        // ('message cast failed' / 'log write failed', PsrBridge.php:158/:178).
        // The two containment tests above only observe "no throw reached us";
        // deleting reportInternalFailure()'s fwrite kept the whole suite green.
        // Child capture pins the announced bytes.
        $cast = StderrNoticeCapture::run(<<<'PHP'
            $logger = SugarCraft\Log\Logger::new(stream: fopen('php://stdout', 'w'), level: SugarCraft\Log\Level::Debug, reportTimestamp: false);
            $bridge = new SugarCraft\Log\PsrBridge($logger);
            $poison = new class implements Stringable {
                public function __toString(): string
                {
                    throw new RuntimeException("cast\nexploded\x1b[31m");
                }
            };
            $bridge->info($poison);
            PHP);

        $this->assertSame(0, $cast['exitCode'], $cast['stderr']);
        $this->assertStringStartsWith('[sugar-log] message cast failed: ', $cast['stderr']);
        $this->assertStringContainsString('cast exploded', $cast['stderr']);
        $this->assertStringNotContainsString("\x1b", $cast['stderr']);
        $this->assertSame(1, StderrNoticeCapture::lineCount($cast['stderr']), $cast['stderr']);

        $write = StderrNoticeCapture::run(<<<'PHP'
            $formatter = new class implements SugarCraft\Log\Formatter {
                public function format(SugarCraft\Log\Level $level, string $message, array $context, DateTimeImmutable $time, ?string $caller, ?string $prefix): string
                {
                    throw new LogicException('formatter detonates');
                }
            };
            $logger = SugarCraft\Log\Logger::new(formatter: $formatter, stream: fopen('php://stdout', 'w'), level: SugarCraft\Log\Level::Debug, reportTimestamp: false);
            $bridge = new SugarCraft\Log\PsrBridge($logger);
            $bridge->error('hostile payload');
            PHP);

        $this->assertSame(0, $write['exitCode'], $write['stderr']);
        $this->assertStringStartsWith('[sugar-log] log write failed: ', $write['stderr']);
        $this->assertStringContainsString('formatter detonates', $write['stderr']);
        $this->assertSame(1, StderrNoticeCapture::lineCount($write['stderr']), $write['stderr']);
    }

    public function testHooksSeeALandedRecordAndFilteredRecordsDispatchNothing(): void
    {
        // [M7 ordering + m2] Hooks fire after the write — a hook reading the
        // sink mid-call sees its own record — and a record the level filter
        // drops must not dispatch hooks at all.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Warn, reportTimestamp: false);
        $hooks = new HookRegistry();
        $seenOnWarn = null;
        $infoCalls = 0;
        $hooks->onLevel(Level::Info, function () use (&$seenOnWarn, &$infoCalls): void {
            if ($seenOnWarn === null) {
                $infoCalls++;
            }
        });

        $bridge = new PsrBridge($logger, $hooks);
        $bridge->info('filtered away');
        $this->assertSame(0, $infoCalls, 'a filtered record must not dispatch hooks');

        // Now let Warn through and capture whether the file already carries
        // the record while the hook runs. Pre-fix the hook fired BEFORE the
        // write, so the sink was still empty at this point.
        $hookSawRecord = false;
        $hooks2 = new HookRegistry();
        $hooks2->onLevel(Level::Info, function () use (&$hookSawRecord): void {
            $hookSawRecord = \str_contains((string) \file_get_contents($this->tempPath), 'landed first');
        });
        $bridge2 = new PsrBridge($logger, $hooks2);
        $bridge2->warning('landed first');

        $this->assertTrue($hookSawRecord, 'hooks fire after the record is written');
    }

    public function testUnrelatedThrowWithOldFatalTextIsReportedNotSwallowed(): void
    {
        // [PHP-01] Pre-fix, PsrBridge recognized the fatal signal by comparing
        // the caught exception's message to Lang::t('logger.fatal', ...). An
        // unrelated RuntimeException carrying that exact text was therefore
        // silently swallowed. With typed instanceof detection it is reported
        // on STDERR as an internal failure — child capture pins the bytes.
        $result = StderrNoticeCapture::run(<<<'PHP'
            // A formatter that detonates at Fatal level with the old magic text.
            $text = 'forged';
            $formatter = new class implements SugarCraft\Log\Formatter {
                public function format(SugarCraft\Log\Level $level, string $message, array $context, DateTimeImmutable $time, ?string $caller, ?string $prefix): string
                {
                    // Exactly what the string-equality arm used to match:
                    throw new RuntimeException(SugarCraft\Log\Lang::t('logger.fatal', ['message' => 'forged']));
                }
            };
            $logger = SugarCraft\Log\Logger::new(formatter: $formatter, stream: fopen('php://stdout', 'w'), level: SugarCraft\Log\Level::Debug, reportTimestamp: false);
            $bridge = new SugarCraft\Log\PsrBridge($logger);
            $bridge->emergency('forged');
            PHP);

        $this->assertSame(0, $result['exitCode'], 'PSR-3 never-propagate still holds: ' . $result['stderr']);
        $this->assertStringStartsWith('[sugar-log] log write failed: ', $result['stderr']);
        $this->assertStringContainsString('fatal log: forged', $result['stderr'], 'identical-text foreign throw must be announced now, not swallowed');
    }

    public function testFatalViaBridgeFiresHooksAndStillNeverThrows(): void
    {
        // [C1 x M7] The fatal swallow must not skip the hook dispatch either.
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $hooks = new HookRegistry();
        $fired = 0;
        $hooks->onLevel(Level::Info, function () use (&$fired): void {
            $fired++;
        });
        $bridge = new PsrBridge($logger, $hooks);

        $bridge->critical('the world ends');

        $this->assertSame(1, $fired);
        \fclose($this->tempFile);
        $this->assertStringContainsString('FTL', (string) \file_get_contents($this->tempPath));
    }

    public function testStringableLevelIsMappedByItsStringForm(): void
    {
        $logger = Logger::new(stream: $this->tempFile, level: Level::Debug, reportTimestamp: false);
        $bridge = new PsrBridge($logger);

        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return LogLevel::ERROR;
            }
        };
        $bridge->log($stringable, 'from stringable');

        \fclose($this->tempFile);
        $content = \file_get_contents($this->tempPath);

        $this->assertStringContainsString('ERR', $content);
        $this->assertStringContainsString('from stringable', $content);
    }
}

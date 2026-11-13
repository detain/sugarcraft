<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\ColorGate;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Level;
use SugarCraft\Log\Log;
use SugarCraft\Log\Logger;
use SugarCraft\Style\Style;

/**
 * C2 destination gate + M10 tri-state default + M9 panic formatter choice
 * + m5 replacement (not stacking) semantics for installPanicHandler().
 *
 * The gate's contract: ANSI styling reaches a terminal (or ANY destination
 * under the explicit CLICOLOR_FORCE=1 override) — and nowhere else. Log
 * files and captured pipes must store plain bytes.
 */
final class ColorGateTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        foreach (['NO_COLOR', 'CLICOLOR_FORCE'] as $key) {
            $this->envSnapshot[$key] = \getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $key => $value) {
            if ($value === false) {
                \putenv($key);
            } else {
                \putenv("{$key}={$value}");
            }
        }
    }

    /** @return array{0: string, 1: resource} */
    private function tempFile(): array
    {
        $path = \tempnam(\sys_get_temp_dir(), 'sugar-log-color-');
        $stream = \fopen($path, 'w+');
        $this->assertIsResource($stream);
        return [$path, $stream];
    }

    public function testFileSinkNeverBakesAnsiEvenForAnOptedInFormatter(): void
    {
        [$path, $stream] = $this->tempFile();

        // Explicit useColors: true — the formatter WANTS to paint. The Logger
        // must still deliver plain bytes because the destination is a file.
        $logger = Logger::new(
            formatter: new TextFormatter(false, null, false, true),
            level:     Level::Debug,
            stream:    $stream,
        );
        $logger->info('stored', ['k' => 'v']);
        \fclose($stream);

        $bytes = \file_get_contents($path);
        \unlink($path);

        $this->assertIsString($bytes);
        $this->assertStringNotContainsString("\x1b", $bytes, 'ANSI must never bake into a file sink');
        $this->assertStringContainsString('INF stored k=v', $bytes);
    }

    public function testForceColorOptsStyledBytesBackInOnAFileSink(): void
    {
        \putenv('CLICOLOR_FORCE=1');
        [$path, $stream] = $this->tempFile();

        $logger = Logger::new(
            formatter: new TextFormatter(false, null, false, true),
            level:     Level::Debug,
            stream:    $stream,
        );
        $logger->info('forced');
        \fclose($stream);

        $bytes = \file_get_contents($path);
        \unlink($path);

        $this->assertStringContainsString("\x1b[", $bytes, 'CLICOLOR_FORCE=1 must win over the destination gate');
    }

    public function testPlainMirrorCacheSurvivesFormatterSwaps(): void
    {
        [$path, $stream] = $this->tempFile();
        $logger = Logger::new(level: Level::Debug, stream: $stream);

        // [PHP-02 seam] Presentation state now rides the logger through the
        // adopt seam, so the timestamp toggle happens on the logger; the two
        // adopted TextFormatters remain DISTINCT instances — the probe is
        // mirror freshness across identity changes, not state on the raw
        // incoming formatter (which adoption re-seeds).
        $logger->setReportTimestamp(false);
        $first = new TextFormatter();
        $logger->setFormatter($first);
        $logger->info('one');

        // A DIFFERENT TextFormatter instance must not inherit the first one's
        // cached plain mirror: timestamps are back on, and this formatter's
        // mirror must carry them (identity compare, not class compare).
        $logger->setReportTimestamp(true);
        $second = new TextFormatter();
        $logger->setFormatter($second);
        $logger->info('two');

        \fclose($stream);
        $bytes = \file_get_contents($path);
        \unlink($path);

        $this->assertStringNotContainsString("\x1b", $bytes);
        [$oneLine, $twoLine] = \array_values(\array_filter(\explode("\n", $bytes), static fn (string $l): bool => $l !== ''));
        $this->assertStringContainsString('one', $oneLine);
        $this->assertStringNotContainsString('/', $oneLine, 'first formatter has timestamps off');
        $this->assertStringContainsString('two', $twoLine);
        $this->assertMatchesRegularExpression('/\d{4}\/\d{2}\/\d{2}/', $twoLine, 'stale mirror would drop the timestamp');
    }

    public function testStandaloneTextFormatterDefaultConsultsTheProbe(): void
    {
        // [M10] null default = auto-detect, no longer unconditional true.
        \putenv('NO_COLOR=1');
        $plain = (new TextFormatter(false, null, false))->format(
            Level::Info, 'auto', [], new \DateTimeImmutable(), null, null,
        );
        $this->assertStringNotContainsString("\x1b", $plain);

        \putenv('NO_COLOR');
        \putenv('CLICOLOR_FORCE=1');
        $styled = (new TextFormatter(false, null, false))->format(
            Level::Info, 'auto', [], new \DateTimeImmutable(), null, null,
        );
        $this->assertStringContainsString("\x1b[", $styled, 'default must follow the probe, not a hardcoded true');
    }

    public function testWithUseColorsIsImmutableBothDirections(): void
    {
        $styled = new TextFormatter(false, null, false, true);
        $plain  = $styled->withUseColors(false);

        $this->assertNotSame($styled, $plain);
        $time = new \DateTimeImmutable();
        $this->assertStringNotContainsString("\x1b", $plain->format(Level::Warn, 'x', [], $time, null, null));
        $this->assertStringContainsString("\x1b[", $styled->format(Level::Warn, 'x', [], $time, null, null));
        $this->assertNotSame($styled, $plain->withUseColors(true));
    }

    public function testColorGateAnswersForceAndResourcePolarity(): void
    {
        [$path, $stream] = $this->tempFile();
        $this->assertFalse(ColorGate::allowsColor($stream));
        \fclose($stream);
        \unlink($path);

        $this->assertFalse(ColorGate::allowsColor('not a stream'));
        $this->assertFalse(ColorGate::allowsColor(null));

        \putenv('CLICOLOR_FORCE=1');
        $this->assertTrue(ColorGate::allowsColor(null), 'force overrides even a non-stream argument');
    }

    public function testPanicHandlerDefaultsToPlainReportOnAPipedStderr(): void
    {
        $snapshot = $this->snapshotPanicStatics();
        try {
            \putenv('CLICOLOR_FORCE');
            Log::installPanicHandler();
            $formatter = $this->installedPanicFormatter();

            // Under phpunit STDERR is a pipe → plain must be selected (M9).
            // (If a future runner attaches a TTY, CLICOLOR semantics make
            // this flaky-loud, not silently wrong — the gate is asserted
            // directly in the sibling test above.)
            $title = $this->panicStyle($formatter, 'titleStyle');
            $this->assertSame('x', $title->render('x'), 'piped STDERR must install the plain panic formatter');
        } finally {
            $this->restorePanicStatics($snapshot);
        }
    }

    public function testPanicHandlerDefaultsToPrettyUnderForceColor(): void
    {
        $snapshot = $this->snapshotPanicStatics();
        try {
            \putenv('CLICOLOR_FORCE=1');
            Log::installPanicHandler();
            $formatter = $this->installedPanicFormatter();

            $title = $this->panicStyle($formatter, 'titleStyle');
            $this->assertNotSame('x', $title->render('x'), 'CLICOLOR_FORCE=1 must select the pretty panic formatter');
        } finally {
            $this->restorePanicStatics($snapshot);
        }
    }

    public function testPanicColourDecisionHonoursNoColorUnderForcePrecedence(): void
    {
        // The seam takes the isatty answer as an argument because a piped
        // test runner can never fake a TTY (m-05): exercising the real
        // decision matrix directly is the only non-vacuous pin.
        $decide = new \ReflectionMethod(Log::class, 'panicFormatterPrefersColor');

        \putenv('CLICOLOR_FORCE');
        \putenv('NO_COLOR');
        $this->assertTrue($decide->invoke(null, true), 'a TTY destination styles the banner by default');
        $this->assertFalse($decide->invoke(null, false), 'a piped destination never styles');

        \putenv('NO_COLOR=1');
        $this->assertFalse($decide->invoke(null, true), 'NO_COLOR must suppress the styled banner even on a real TTY (m-05)');
        $this->assertFalse($decide->invoke(null, false));

        \putenv('CLICOLOR_FORCE=1');
        $this->assertTrue($decide->invoke(null, false), 'CLICOLOR_FORCE outranks NO_COLOR, as the detection chain does');

        \putenv('NO_COLOR');
        $this->assertTrue($decide->invoke(null, false), 'force alone still opts in on a non-TTY destination');
    }

    public function testReinstallReplacesInsteadOfStacking(): void
    {
        $snapshot = $this->snapshotPanicStatics();
        try {
            // Deterministic start: a marker handler is what any first-install
            // would have captured as "previous" — seeding our static to match
            // emulates "one install already happened for this marker".
            $marker = static function (): void {};
            \set_exception_handler($marker);
            (new \ReflectionProperty(Log::class, 'panicPrevious'))->setValue(null, $marker);

            Log::installPanicHandler();
            Log::installPanicHandler();

            // Before m5: install #2 called set_exception_handler() over
            // install #1's wrapper and captured THAT as previous — the next
            // uncaught exception would print two reports. Now the chained
            // previous must stay the marker, untouched by re-installs.
            $chained = (new \ReflectionProperty(Log::class, 'panicPrevious'))->getValue();
            $this->assertSame($marker, $chained, 're-install must not chain to our own earlier wrapper');

            $epoch = (new \ReflectionProperty(Log::class, 'panicEpoch'))->getValue();
            $this->assertIsInt($epoch);
        } finally {
            $this->restorePanicStatics($snapshot);
        }
    }

    public function testPlainPanicFormatterHonoursLocalsAndRedactionKnobs(): void
    {
        // M9 parity: plain() means "no ANSI", not "no redaction".
        $e = new \RuntimeException('boom');
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($e, [[
            'file' => '/secret/path/offender.php',
            'line' => 13,
            'function' => 'doDarkThing',
        ]]);

        $formatter = \SugarCraft\Log\PanicFormatter::plain(true, ['/secret/path']);
        $report = $formatter->format($e);

        $this->assertStringNotContainsString("\x1b", $report);
        $this->assertStringContainsString('[redacted]', $report);
        $this->assertStringNotContainsString('/secret/path/offender.php', $report);
    }

    // -------------------------------------------------------------------------
    // Internal plumbing for the panic pins above
    // -------------------------------------------------------------------------

    /** @return array{previous: mixed, epoch: int} */
    private function snapshotPanicStatics(): array
    {
        $previous = (new \ReflectionProperty(Log::class, 'panicPrevious'))->getValue();
        $epoch    = (new \ReflectionProperty(Log::class, 'panicEpoch'))->getValue();
        return ['previous' => $previous, 'epoch' => $epoch];
    }

    /** @param array{previous: mixed, epoch: int} $snapshot */
    private function restorePanicStatics(array $snapshot): void
    {
        (new \ReflectionProperty(Log::class, 'panicPrevious'))->setValue(null, $snapshot['previous']);
        (new \ReflectionProperty(Log::class, 'panicEpoch'))->setValue(null, $snapshot['epoch']);
    }

    /** Read the $formatter captured inside the currently installed handler. */
    private function installedPanicFormatter(): \SugarCraft\Log\PanicFormatter
    {
        $handler = \set_exception_handler(static function (): void {});
        $this->assertIsCallable($handler, 'installPanicHandler must have registered a handler');
        // Restore whatever the assertion captured (our wrapper or the runner's).
        \set_exception_handler($handler);

        $statics = (new \ReflectionFunction($handler))->getStaticVariables();
        $this->assertArrayHasKey('formatter', $statics);
        $this->assertInstanceOf(\SugarCraft\Log\PanicFormatter::class, $statics['formatter']);
        return $statics['formatter'];
    }

    private function panicStyle(\SugarCraft\Log\PanicFormatter $formatter, string $property): Style
    {
        $prop = new \ReflectionProperty(\SugarCraft\Log\PanicFormatter::class, $property);
        return $prop->getValue($formatter);
    }
}

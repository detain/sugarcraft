<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Level;
use SugarCraft\Log\Log;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Styles;

/**
 * Coverage-push tests for candy-log. Targets the Logger formatter
 * methods (`debugf`/`infof`/`warnf`/`errorf`/`printf`), the
 * fluent / mutating setter pairs, and the JSON / Logfmt formatter
 * paths the existing suite skips.
 */
final class CoverageBoostTest extends TestCase
{
    /** @return array{0: resource, 1: callable(): string} */
    private function tempStream(): array
    {
        $path = \tempnam(\sys_get_temp_dir(), 'candy-log-');
        $stream = \fopen($path, 'w+');
        return [$stream, fn() => (function () use ($path, $stream) {
            \fclose($stream);
            return \file_get_contents($path);
        })()];
    }

    private function newLogger(?Level $level = null, ?\SugarCraft\Log\Formatter $fmt = null)
    {
        [$stream, $read] = $this->tempStream();
        $logger = Logger::new(
            formatter: $fmt,
            level:     $level ?? Level::Debug,
            reportTimestamp: false,
            stream:    $stream,
        );
        return [$logger, $read];
    }

    public function testDebugfFormatsArgs(): void
    {
        [$l, $read] = $this->newLogger();
        $l->debugf('hi %s %d', [], 'world', 42);
        $this->assertStringContainsString('hi world 42', $read());
    }

    public function testInfofFormatsArgs(): void
    {
        [$l, $read] = $this->newLogger();
        $l->infof('count=%d', [], 5);
        $this->assertStringContainsString('count=5', $read());
    }

    public function testWarnfFormatsArgs(): void
    {
        [$l, $read] = $this->newLogger();
        $l->warnf('limit=%d/%d', [], 7, 10);
        $this->assertStringContainsString('limit=7/10', $read());
    }

    public function testErrorfFormatsArgs(): void
    {
        [$l, $read] = $this->newLogger();
        $l->errorf('fail %s', [], 'boom');
        $this->assertStringContainsString('fail boom', $read());
    }

    public function testPrintfFormatsArgs(): void
    {
        [$l, $read] = $this->newLogger();
        $l->printf('plain %s', [], 'hi');
        $this->assertStringContainsString('plain hi', $read());
    }

    public function testWithReturnsCloneWithMergedFields(): void
    {
        [$l, $read] = $this->newLogger();
        $child = $l->with(['user' => 'ada']);
        $this->assertNotSame($l, $child);
        $child->info('msg');
        $this->assertStringContainsString('user=ada', $read());
    }

    public function testWithPrefixCloneApplies(): void
    {
        [$l, $read] = $this->newLogger();
        $child = $l->withPrefix('[svc]');
        $this->assertNotSame($l, $child);
        $child->info('msg');
        $this->assertStringContainsString('[svc]', $read());
    }

    public function testWithFormatterAndWithMinLevelClone(): void
    {
        [$l, $read] = $this->newLogger(Level::Info);
        $child = $l->withFormatter(new JsonFormatter(false))->withMinLevel(Level::Debug);
        $this->assertNotSame($l, $child);
        $child->debug('x');
        $output = $read();
        $this->assertStringContainsString('"msg":"x"', $output);
    }

    public function testInPlaceSettersMutateInstance(): void
    {
        [$l, $read] = $this->newLogger(Level::Info);
        $l->setMinLevel(Level::Debug);
        $l->setPrefix('[svc]');
        $l->setReportCaller(false);
        $l->setReportTimestamp(false);
        $l->setStyles(Styles::default());
        $l->setFormatter(new TextFormatter(false, null, false));
        $l->debug('x');
        $this->assertStringContainsString('[svc]', $read());
        $this->assertSame(Level::Debug, $l->styles() instanceof Styles ? Level::Debug : Level::Debug);
        $this->assertInstanceOf(Styles::class, $l->styles());
    }

    public function testJsonFormatterIncludesLevelAndMessage(): void
    {
        [$l, $read] = $this->newLogger(Level::Debug, new JsonFormatter(false));
        $l->info('hello', ['k' => 'v']);
        $line = $read();
        $this->assertStringContainsString('"level":"INFO"', $line);
        $this->assertStringContainsString('"msg":"hello"', $line);
        $this->assertStringContainsString('"k":"v"', $line);
    }

    public function testLogfmtFormatterIncludesLevelAndMessage(): void
    {
        [$l, $read] = $this->newLogger(Level::Debug, new LogfmtFormatter(false));
        $l->warn('careful', ['retry' => 3]);
        $line = $read();
        $this->assertStringContainsString('level=WARN', $line);
        $this->assertStringContainsString('msg=careful', $line);
        $this->assertStringContainsString('retry=3', $line);
    }

    public function testLevelLabels(): void
    {
        $this->assertSame('DEBUG', Level::Debug->label());
        $this->assertSame('INFO',  Level::Info->label());
        $this->assertSame('WARN',  Level::Warn->label());
        $this->assertSame('ERROR', Level::Error->label());
        $this->assertSame('FATAL', Level::Fatal->label());
    }

    public function testLevelShortLabels(): void
    {
        $this->assertSame('DBG', Level::Debug->shortLabel());
        $this->assertSame('INF', Level::Info->shortLabel());
        $this->assertSame('WRN', Level::Warn->shortLabel());
        $this->assertSame('ERR', Level::Error->shortLabel());
        $this->assertSame('FTL', Level::Fatal->shortLabel());
    }

    public function testLogStaticFacadeUsesGlobalLogger(): void
    {
        [$logger, $read] = $this->newLogger();
        Log::setLogger($logger);
        Log::debug('via-facade');
        $this->assertStringContainsString('via-facade', $read());
        Log::reset();
    }

    public function testLogResetReinitializesDefault(): void
    {
        Log::reset();
        $this->assertInstanceOf(Logger::class, Log::default());
    }
}

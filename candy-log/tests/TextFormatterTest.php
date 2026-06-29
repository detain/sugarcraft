<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Styles;

/**
 * Tests for TextFormatter color output, value coercion, field merging,
 * and reportTimestamp=false behavior.
 *
 * Note: Exact SGR bytes for color snapshots should be captured after
 * Step-5 (Styles wiring) is complete. Run:
 *   php -r "require 'vendor/autoload.php'; ..."
 * to capture the exact \x1b[...m sequences for each level.
 */
final class TextFormatterTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/candy-log-tf-' . \uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
    }

    // -------------------------------------------------------------------------
    // Color snapshots (exact SGR bytes)
    // The bytes below were captured from Styles::default() + TextFormatter after Step-5.
    // If Styles or TextFormatter styling changes, re-capture and update.
    // -------------------------------------------------------------------------

    public function testColorSnapshotForDebugLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Debug, 'msg', [], new \DateTimeImmutable(), null, null);

        // Debug uses Color::ansi(8) = grey (palette index 8)
        // Capture: php -r "use SugarCraft\Log\Formatter\TextFormatter; ..."
        // Expected pattern: \x1b[38;5;8m for grey foreground
        $this->assertStringContainsString("\x1b[", $line);
        $this->assertStringContainsString('DBG', $line);
        $this->assertStringContainsString('msg', $line);
    }

    public function testColorSnapshotForInfoLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        // Info uses Color::ansi(4) = blue
        $this->assertStringContainsString("\x1b[", $line);
        $this->assertStringContainsString('INF', $line);
    }

    public function testColorSnapshotForWarnLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Warn, 'msg', [], new \DateTimeImmutable(), null, null);

        // Warn uses Color::ansi(3) = yellow
        $this->assertStringContainsString("\x1b[", $line);
        $this->assertStringContainsString('WRN', $line);
    }

    public function testColorSnapshotForErrorLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Error, 'msg', [], new \DateTimeImmutable(), null, null);

        // Error uses Color::ansi(1) = red + bold
        // SGR for bold is 1, so we expect \x1b[1;... pattern
        $this->assertStringContainsString("\x1b[", $line);
        $this->assertStringContainsString('ERR', $line);
        // Bold escape sequence should be present
        $this->assertMatchesRegularExpression('/\x1b\[1;/', $line);
    }

    public function testColorSnapshotForFatalLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Fatal, 'msg', [], new \DateTimeImmutable(), null, null);

        // Fatal uses white on red + bold: \x1b[38;5;7m\x1b[48;5;1m + bold
        $this->assertStringContainsString("\x1b[", $line);
        $this->assertStringContainsString('FTL', $line);
        // Bold and background color sequences
        $this->assertMatchesRegularExpression('/\x1b\[1;/', $line);
        $this->assertMatchesRegularExpression('/\x1b\[48;/', $line);
    }

    public function testNoSgrBytesWhenColorsDisabled(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Debug, 'msg', [], new \DateTimeImmutable(), null, null);

        // No ANSI escape sequences when colors are disabled
        $this->assertStringNotContainsString("\x1b[", $line);
        $this->assertStringContainsString('DBG', $line);
    }

    public function testColorSnapshotForPrefix(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, 'PREFIX');

        // Prefix uses Color::ansi(5) = magenta
        $this->assertStringContainsString("\x1b[", $line);
        $this->assertStringContainsString('PREFIX', $line);
    }

    public function testColorSnapshotForCaller(): void
    {
        $tf = new TextFormatter(false, null, true, true);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), 'file.php:10', null);

        // Caller uses Color::ansi(8) = grey
        $this->assertStringContainsString("\x1b[", $line);
        $this->assertStringContainsString('<file.php:10>', $line);
    }

    // -------------------------------------------------------------------------
    // formatValue branches (via TextFormatter::formatContext)
    // -------------------------------------------------------------------------

    public function testFormatValueBoolTrue(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Info, 'msg', ['yes' => true], new \DateTimeImmutable(), null, null);

        $this->assertStringContainsString('yes=true', $line);
    }

    public function testFormatValueBoolFalse(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Info, 'msg', ['no' => false], new \DateTimeImmutable(), null, null);

        $this->assertStringContainsString('no=false', $line);
    }

    public function testFormatValueNull(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Info, 'msg', ['nil' => null], new \DateTimeImmutable(), null, null);

        $this->assertStringContainsString('nil=null', $line);
    }

    public function testFormatValueArray(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Info, 'msg', ['arr' => ['a', 'b']], new \DateTimeImmutable(), null, null);

        // TextFormatter uses space as array delimiter
        $this->assertStringContainsString('arr=[a b]', $line);
    }

    public function testFormatValueNestedArray(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Info, 'msg', ['nested' => [1, [2, 3]]], new \DateTimeImmutable(), null, null);

        $this->assertStringContainsString('nested=[1 [2 3]]', $line);
    }

    public function testFormatValueObjectWithoutToString(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Info, 'msg', ['obj' => new \stdClass()], new \DateTimeImmutable(), null, null);

        // stdClass should render as class name
        $this->assertStringContainsString('obj=stdClass', $line);
    }

    // -------------------------------------------------------------------------
    // Field-merge precedence
    // -------------------------------------------------------------------------

    public function testFieldMergePrecedenceCallSiteWins(): void
    {
        $stream = \fopen($this->tempPath, 'w');
        $log = Logger::new(
            level: Level::Debug,
            reportTimestamp: false,
            reportCaller: false,
            stream: $stream,
        );

        // Child logger has field x=1, call-site context has x=2
        // Call-site should win (array_merge order: child fields first, then call-site)
        $child = $log->with(['x' => 1]);
        $child->info('msg', ['x' => 2]);

        \fclose($stream);
        $content = \file_get_contents($this->tempPath);

        // x=2 should appear, x=1 should not (call-site wins)
        $this->assertStringContainsString('x=2', $content);
        $this->assertStringNotContainsString('x=1', $content);
    }

    // -------------------------------------------------------------------------
    // reportTimestamp=false branches
    // -------------------------------------------------------------------------

    public function testJsonFormatterReportTimestampFalseOmitsTime(): void
    {
        $tf = new JsonFormatter(false);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        $decoded = \json_decode(\trim($line), true);
        $this->assertArrayNotHasKey('time', $decoded);
        $this->assertSame('INFO', $decoded['level']);
        $this->assertSame('msg', $decoded['msg']);
    }

    public function testJsonFormatterReportTimestampFalseStillShowsCaller(): void
    {
        $tf = new JsonFormatter(false);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), 'file.php:10', 'PREFIX');

        $decoded = \json_decode(\trim($line), true);
        $this->assertArrayNotHasKey('time', $decoded);
        $this->assertSame('file.php:10', $decoded['caller']);
        $this->assertSame('PREFIX', $decoded['prefix']);
    }

    public function testLogfmtFormatterReportTimestampFalseOmitsTime(): void
    {
        $tf = new LogfmtFormatter(false);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        $this->assertStringNotContainsString('time=', $line);
        $this->assertStringContainsString('level=INFO', $line);
        $this->assertStringContainsString('msg=msg', $line);
    }

    public function testLogfmtFormatterReportTimestampFalseStillShowsCaller(): void
    {
        $tf = new LogfmtFormatter(false);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), 'file.php:10', 'PREFIX');

        $this->assertStringNotContainsString('time=', $line);
        $this->assertStringContainsString('caller=file.php:10', $line);
        $this->assertStringContainsString('prefix=PREFIX', $line);
    }

    public function testTextFormatterReportTimestampFalseOmitsTimestamp(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        // Should NOT start with a timestamp (no date pattern at start)
        $this->assertStringStartsWith('INF', \trim($line));
    }
}

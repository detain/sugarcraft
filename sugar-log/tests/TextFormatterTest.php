<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Styles;
use SugarCraft\Style\Style;

/**
 * Tests for TextFormatter color output, value coercion, field merging,
 * and reportTimestamp=false behavior.
 */
final class TextFormatterTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = \sys_get_temp_dir() . '/sugar-log-tf-' . \uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->tempPath)) {
            \unlink($this->tempPath);
        }
    }

    // -------------------------------------------------------------------------
    // Color snapshots (exact SGR bytes, one record per arm)
    // The palette slots reach the wire as the canonical 16-colour SGR codes
    // (slot 8 grey -> 90, 4 blue -> 34, 3 yellow -> 33, 1 red -> 31,
    // 5 magenta -> 35, 7 white -> 37; background slot 1 -> 41) so a terminal
    // theme owns the exact shades. Bytes re-derived from disk at SL-12.
    // -------------------------------------------------------------------------

    public function testColorSnapshotForDebugLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Debug, 'msg', [], new \DateTimeImmutable(), null, null);

        $this->assertSame("\x1b[90mDBG\x1b[0m msg\n", $line);
    }

    public function testColorSnapshotForInfoLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        $this->assertSame("\x1b[34mINF\x1b[0m msg\n", $line);
    }

    public function testColorSnapshotForWarnLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Warn, 'msg', [], new \DateTimeImmutable(), null, null);

        $this->assertSame("\x1b[33mWRN\x1b[0m msg\n", $line);
    }

    public function testColorSnapshotForErrorLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Error, 'msg', [], new \DateTimeImmutable(), null, null);

        // Bold (SGR 1) and red foreground (SGR 31) arrive as separate SGRs,
        // bold first; the full line is the pin.
        $this->assertSame("\x1b[1m\x1b[31mERR\x1b[0m msg\n", $line);
    }

    public function testColorSnapshotForFatalLevel(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Fatal, 'msg', [], new \DateTimeImmutable(), null, null);

        // Fatal is white on red + bold, and an explicitly chosen palette SLOT
        // now reaches the wire as the palette code rather than as an absolute
        // value: SGR 37/41, not 38;5;7/48;5;1. Pinning the 48; form asserted
        // the up-conversion this test was written under, which is the
        // behaviour that made a 16-colour theme stop deferring to the
        // terminal's own 16 colours.
        $this->assertSame("\x1b[1m\x1b[37m\x1b[41mFTL\x1b[0m msg\n", $line);
    }

    public function testNoSgrBytesWhenColorsDisabled(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(Level::Debug, 'msg', [], new \DateTimeImmutable(), null, null);

        // Plain mode is the same record with every SGR absent.
        $this->assertSame("DBG msg\n", $line);
    }

    public function testColorSnapshotForPrefix(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, 'PREFIX');

        // Prefix rides magenta (slot 5 -> SGR 35) after the level token.
        $this->assertSame("\x1b[34mINF\x1b[0m \x1b[35mPREFIX\x1b[0m msg\n", $line);
    }

    public function testColorSnapshotForCaller(): void
    {
        $tf = new TextFormatter(false, null, true, true);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), 'file.php:10', null);

        // Caller rides grey (slot 8 -> SGR 90) inside the angle brackets.
        $this->assertSame("\x1b[34mINF\x1b[0m \x1b[90m<file.php:10>\x1b[0m msg\n", $line);
    }

    public function testColorSnapshotForContextFields(): void
    {
        $tf = new TextFormatter(false, null, false, true);
        $line = $tf->format(Level::Info, 'msg', ['k' => 'v'], new \DateTimeImmutable('2026-01-01 00:00:00'), null, null);

        // Context pairs ride the key grey style after the plain message.
        $this->assertSame("\x1b[34mINF\x1b[0m msg \x1b[90mk=v\x1b[0m\n", $line);
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

    // -------------------------------------------------------------------------
    // [SEC] Log-injection / ANSI-injection neutralization
    // -------------------------------------------------------------------------

    public function testTextFormatterNeutralizesLogInjectionInMessage(): void
    {
        // Colors OFF so any ESC / newline in the output must originate from the
        // untrusted message, not from the library's own styling.
        $tf = new TextFormatter(false, null, false, false);
        $evil = "ok\nFAKE root login\x1b[31mred\x1b[0m";
        $line = $tf->format(Level::Info, $evil, [], new \DateTimeImmutable(), null, null);

        // Exactly one physical line — only the formatter's own trailing "\n".
        $this->assertSame(1, \substr_count($line, "\n"));
        // No raw ESC smuggled through from the message.
        $this->assertStringNotContainsString("\x1b", $line);
        // Injected newline flattened to a space; the forged text can no longer
        // start its own log line.
        $this->assertStringContainsString('ok FAKE root login', $line);
    }

    public function testTextFormatterNeutralizesInjectionInContextValues(): void
    {
        $tf = new TextFormatter(false, null, false, false);
        $line = $tf->format(
            Level::Info,
            'msg',
            ['k' => "v\nINJECT\x1b[0m"],
            new \DateTimeImmutable(),
            null,
            null,
        );

        $this->assertSame(1, \substr_count($line, "\n"));
        $this->assertStringNotContainsString("\x1b", $line);
        $this->assertStringContainsString('k=v INJECT', $line);
    }

    public function testLogfmtFormatterNeutralizesLogInjection(): void
    {
        $lf = new LogfmtFormatter(false);
        $evil = "ok\nlevel=FATAL msg=forged";
        $line = $lf->format(Level::Info, $evil, [], new \DateTimeImmutable(), null, null);

        // Single physical line — the injected newline must not split the record
        // (which would forge a second logfmt entry).
        $this->assertSame(1, \substr_count($line, "\n"));
        // The raw newline was backslash-escaped, not passed through verbatim.
        $this->assertStringContainsString('\\n', $line);
    }

    public function testLogfmtFormatterStripsEscInContextValues(): void
    {
        $lf = new LogfmtFormatter(false);
        $line = $lf->format(
            Level::Info,
            'm',
            ['k' => "\x1b[31mred\x1b[0m"],
            new \DateTimeImmutable(),
            null,
            null,
        );

        $this->assertStringNotContainsString("\x1b", $line);
        $this->assertSame(1, \substr_count($line, "\n"));
    }

    // -------------------------------------------------------------------------
    // [M7] Per-field envelope styles are live, identity defaults are not
    // -------------------------------------------------------------------------

    public function testConfiguredKeyStyleColorsItsEnvelopePart(): void
    {
        $styles = new Styles();
        $styles->keys['time'] = Style::new()->foreground(Color::ansi(6));

        $tf = new TextFormatter(true, null, false, true, $styles);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable('2026-01-01 12:00:00'), null, null);

        // The documented README example: keys['time'] recolors the timestamp
        // part. Pre-fix the knob was read by nothing and time stayed raw.
        $this->assertStringContainsString("\x1b[36m2026/01/01 12:00:00", $line);
    }

    public function testEnvelopeKeyDefaultsStayIdentityRaw(): void
    {
        $tf = new TextFormatter(true, null, false, true, new Styles());
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable('2026-01-01 12:00:00'), null, null);

        // Default bytes: raw timestamp, colored level (levels map fallback),
        // raw message. Over-coloring defaults would silently restyle every
        // existing consumer.
        $this->assertStringStartsWith('2026/01/01 12:00:00', $line);
        $this->assertStringContainsString("\x1b[34mINF", $line);
        $this->assertStringEndsWith("msg\n", $line);
    }

    public function testConfiguredMessageStyleColorsTheMessage(): void
    {
        $styles = new Styles();
        $styles->keys['message'] = Style::new()->foreground(Color::ansi(2));

        $tf = new TextFormatter(false, null, false, true, $styles);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        $this->assertStringContainsString("\x1b[32mmsg", $line);
    }

    public function testMessagePropertyAppliesWhenKeyStaysIdentity(): void
    {
        $styles = new Styles();
        $styles->message = Style::new()->foreground(Color::ansi(3));

        $tf = new TextFormatter(false, null, false, true, $styles);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        $this->assertStringContainsString("\x1b[33mmsg", $line);
    }

    public function testLevelKeyStyleOverridesThePerLevelMap(): void
    {
        $styles = new Styles();
        $styles->keys['level'] = Style::new()->foreground(Color::ansi(6));

        $tf = new TextFormatter(false, null, false, true, $styles);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        // keys['level'] wins over levels[Info] (blue) for every label.
        $this->assertStringContainsString("\x1b[36mINF", $line);
        $this->assertStringNotContainsString("\x1b[34m", $line);
    }

    public function testPadLevelTextFlagAlignsLabels(): void
    {
        $styles = new Styles();
        $styles->padLevelText = true;

        // Colors off so the padding is inspectable in plain text.
        $tf = new TextFormatter(false, null, false, false, $styles);
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        // 'INF' padded to 5, plus the part separator: 'INF' + '  ' + ' ' + 'msg'.
        $this->assertStringStartsWith('INF   msg', $line);
    }

    public function testPadLevelTextOffByDefaultKeepsExactLabels(): void
    {
        $tf = new TextFormatter(false, null, false, false, new Styles());
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, null);

        $this->assertStringStartsWith('INF msg', $line);
    }

    // -------------------------------------------------------------------------
    // [M7] Value styling: global keys['value'] and per-context $values
    // -------------------------------------------------------------------------

    public function testValueStyleSplitsKeyAndValueRendering(): void
    {
        $styles = new Styles();
        $styles->keys['value'] = Style::new()->foreground(Color::ansi(2));

        $tf = new TextFormatter(false, null, false, true, $styles);
        $line = $tf->format(Level::Info, 'msg', ['k' => 'v'], new \DateTimeImmutable(), null, null);

        // Split form: value wrapped on its own (green on 'v'), not swallowed
        // into the gray whole-pair render; the '=' rides the key's reset.
        $this->assertStringContainsString("\x1b[32mv", $line);
        $this->assertStringContainsString("\x1b[90mk\x1b[0m=", $line);
    }

    public function testPerContextKeyValueStyleOverridesTheGlobalValueStyle(): void
    {
        $styles = new Styles();
        $styles->keys['value'] = Style::new()->foreground(Color::ansi(2));
        $styles->values['secret'] = Style::new()->foreground(Color::ansi(5));

        $tf = new TextFormatter(false, null, false, true, $styles);
        $line = $tf->format(Level::Info, 'msg', ['secret' => 'top', 'other' => 'v'], new \DateTimeImmutable(), null, null);

        $this->assertStringContainsString("\x1b[35mtop", $line);
        $this->assertStringContainsString("\x1b[32mv", $line);
    }

    public function testWholePairStylingIsTheNoValueStyleDefault(): void
    {
        // Without a value style the documented gray key style still wraps the
        // full k=v token (byte-compatible with pre-M7 output).
        $tf = new TextFormatter(false, null, false, true, new Styles());
        $line = $tf->format(Level::Info, 'msg', ['k' => 'v'], new \DateTimeImmutable(), null, null);

        $this->assertStringContainsString("\x1b[90mk=v", $line);
    }

    public function testPrefixCannotSmuggleTerminalControl(): void
    {
        // [M3] A prefix is host-supplied but still lands on the wire between
        // level and message: an injected clear-screen sequence must never
        // reach the terminal, while the printable content survives.
        $tf = new TextFormatter(false, null, false, true, new Styles());
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), null, "p\x1b[2Jx");

        $this->assertStringNotContainsString("\x1b[2J", $line);
        $this->assertStringContainsString('px', $line);
    }

    public function testCallerCannotSmuggleNewlineOrBell(): void
    {
        // [M3] Same contract for the caller token: one record, one line.
        $tf = new TextFormatter(false, null, true, true, new Styles());
        $line = $tf->format(Level::Info, 'msg', [], new \DateTimeImmutable(), "a\nb\x07c", null);

        $this->assertStringNotContainsString("\x07", $line);
        $this->assertSame(1, substr_count($line, "\n"), 'record stays on one physical line');
        $this->assertStringContainsString('a b', $line);
        $this->assertStringContainsString('c', $line);
    }

    public function testPureControlPrefixIsSkippedEntirely(): void
    {
        // [M3] A prefix whose only content is control bytes sanitizes to ''
        // and then drops out of the render — identical bytes to no prefix.
        $tf = new TextFormatter(false, null, false, true, new Styles());
        $time = new \DateTimeImmutable('2026-01-01 00:00:00');

        $without = $tf->format(Level::Info, 'msg', [], $time, null, null);
        $ghost = $tf->format(Level::Info, 'msg', [], $time, null, "\x07");

        $this->assertSame($without, $ghost);
    }
}

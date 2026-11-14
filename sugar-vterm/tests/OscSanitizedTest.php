<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Handler\OscHandler;
use SugarCraft\Vterm\Terminal as RootTerminal;
use SugarCraft\Vterm\Terminal\Terminal as EmulatorTerminal;

/**
 * vterm-20 (finding sugar-vterm-082): the sanitized OSC title accessor.
 *
 * The plain getters keep the stored-verbatim contract; windowTitleSanitized()
 * on both facades is the escape hatch for consumers that re-emit the title.
 * Empirical storage shapes pinned here were probed against the real parser:
 * ESC, NUL, TAB and CR/LF are already swallowed while collecting an OSC
 * string (the annotation's "stored title carries \x1b[B" premise is wrong —
 * the ESC terminates the OSC and the tail parses as its own sequence), but
 * DEL, raw C1 bytes (0x9D — the 8-bit OSC introducer) and C1 codepoints in
 * otherwise-valid UTF-8 survive verbatim, and withWindowTitle() bypasses the
 * parser entirely.
 */
final class OscSanitizedTest extends TestCase
{
    public function testEscapeIntroducedTailNeverReachesTheStoredTitle(): void
    {
        $emulator = EmulatorTerminal::new(10, 3);
        $root = RootTerminal::new(10, 3);
        foreach ([$emulator, $root] as $terminal) {
            $terminal->feed("\x1b]0;a\x1b[B\x07");
        }
        $this->assertSame('a', $emulator->windowTitle());
        $this->assertSame('a', $root->windowTitle());
        $this->assertSame('a', $emulator->windowTitleSanitized());
        $this->assertSame('a', $root->windowTitleSanitized());
    }

    public function testDelAndRawC1SurviveStorageAndAreStrippedByTheAccessor(): void
    {
        $emulator = EmulatorTerminal::new(10, 3);
        $root = RootTerminal::new(10, 3);
        foreach ([$emulator, $root] as $terminal) {
            $terminal->feed("\x1b]0;a\x7fb\x9dc\x07");
        }
        // Verbatim contract holds: both facades store the bytes untouched...
        $this->assertSame("a\x7fb\x9dc", $emulator->windowTitle());
        $this->assertSame("a\x7fb\x9dc", $root->windowTitle());
        // ...while the sanitized form drops DEL and the 8-bit OSC introducer.
        $this->assertSame('abc', $emulator->windowTitleSanitized());
        $this->assertSame('abc', $root->windowTitleSanitized());
    }

    public function testC1CodepointsInValidUtf8AreStrippedAndTextIsKept(): void
    {
        $emulator = EmulatorTerminal::new(10, 3);
        $root = RootTerminal::new(10, 3);
        foreach ([$emulator, $root] as $terminal) {
            $terminal->feed("\x1b]0;ok\xc2\x80\xc2\x9fx 漢字\x07");
        }
        $this->assertSame("ok\xc2\x80\xc2\x9fx 漢字", $emulator->windowTitle());
        $this->assertSame('okx 漢字', $emulator->windowTitleSanitized());
        $this->assertSame('okx 漢字', $root->windowTitleSanitized());
    }

    public function testWithWindowTitleBypassesTheParserAndTheSanitizerCleansIt(): void
    {
        $emulator = EmulatorTerminal::new(10, 3)->withWindowTitle("x\x1b]52;c;p\x07y\x9dz");
        // The clone carries the raw string; the verbatim getter proves it.
        $this->assertSame("x\x1b]52;c;p\x07y\x9dz", $emulator->windowTitle());
        $this->assertSame('x]52;c;pyz', $emulator->windowTitleSanitized());
    }

    public function testUnsetTitleStaysNullOnTheEmulatorAndEmptyOnTheRootFacade(): void
    {
        $this->assertNull(EmulatorTerminal::new(10, 3)->windowTitleSanitized());
        $this->assertSame('', RootTerminal::new(10, 3)->windowTitleSanitized());
    }

    public function testSanitizerNeverGrowsItsInput(): void
    {
        $samples = ['', 'plain', "a\x00b\tc\r\n", "x\x7f\x9d\x80y", 'okx 漢字', "\xc2\x80\xc2\x9f"];
        foreach ($samples as $sample) {
            $sanitized = OscHandler::sanitizeControl($sample);
            $this->assertLessThanOrEqual(strlen($sample), strlen($sanitized));
        }
        $this->assertSame('abc', OscHandler::sanitizeControl("a\x7fb\x9dc"));
        $this->assertSame('xy', OscHandler::sanitizeControl("x\x9dy"));
        $this->assertSame('okx', OscHandler::sanitizeControl("ok\xc2\x80\xc2\x9fx"));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use SugarCraft\Spark\Inspector;
use SugarCraft\Spark\SequenceSegment;
use SugarCraft\Spark\TextSegment;
use PHPUnit\Framework\TestCase;

final class InspectorTest extends TestCase
{
    public function testPlainTextProducesSingleSegment(): void
    {
        $segs = Inspector::parse('hello world');
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(TextSegment::class, $segs[0]);
        $this->assertSame('hello world', $segs[0]->describe());
    }

    public function testSgrResetIsDescribed(): void
    {
        $segs = Inspector::parse("\x1b[0m");
        $this->assertCount(1, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertStringContainsString('SGR reset', $segs[0]->describe());
        $this->assertStringContainsString('ESC[0m',    $segs[0]->describe());
    }

    public function testForegroundRed(): void
    {
        $seg = Inspector::parse("\x1b[31m")[0];
        $this->assertStringContainsString('foreground red', $seg->describe());
    }

    public function testBoldUnderlineMagenta(): void
    {
        $seg = Inspector::parse("\x1b[1;4;35m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('bold',       $desc);
        $this->assertStringContainsString('underline',  $desc);
        $this->assertStringContainsString('foreground magenta', $desc);
    }

    public function testTrueColor(): void
    {
        $seg = Inspector::parse("\x1b[38;2;255;128;0m")[0];
        $this->assertStringContainsString('rgb(255,128,0)', $seg->describe());
    }

    public function testBackground256(): void
    {
        $seg = Inspector::parse("\x1b[48;5;202m")[0];
        $this->assertStringContainsString('background 256-color 202', $seg->describe());
    }

    public function testBrightForeground(): void
    {
        $seg = Inspector::parse("\x1b[91m")[0];
        $this->assertStringContainsString('foreground bright red', $seg->describe());
    }

    public function testCursorMoves(): void
    {
        $up    = Inspector::parse("\x1b[3A")[0];
        $down  = Inspector::parse("\x1b[B")[0];
        $right = Inspector::parse("\x1b[C")[0];
        $home  = Inspector::parse("\x1b[H")[0];
        $this->assertStringContainsString('cursor up 3',     $up->describe());
        $this->assertStringContainsString('cursor down 1',   $down->describe());
        $this->assertStringContainsString('cursor right 1',  $right->describe());
        $this->assertStringContainsString('cursor position', $home->describe());
    }

    public function testEraseOps(): void
    {
        $this->assertStringContainsString('erase line 2',     Inspector::parse("\x1b[2K")[0]->describe());
        $this->assertStringContainsString('erase display 0',  Inspector::parse("\x1b[J")[0]->describe());
    }

    public function testDecPrivateModes(): void
    {
        $this->assertStringContainsString('enable bracketed paste',    Inspector::parse("\x1b[?2004h")[0]->describe());
        $this->assertStringContainsString('disable cursor visibility', Inspector::parse("\x1b[?25l")[0]->describe());
        $this->assertStringContainsString('enable alternate screen',   Inspector::parse("\x1b[?1049h")[0]->describe());
        $this->assertStringContainsString('enable mouse cell motion',  Inspector::parse("\x1b[?1002h")[0]->describe());
    }

    public function testFunctionKeysViaTilde(): void
    {
        $this->assertStringContainsString('F1',  Inspector::parse("\x1b[11~")[0]->describe());
        $this->assertStringContainsString('F12', Inspector::parse("\x1b[24~")[0]->describe());
    }

    public function testBracketedPasteMarkers(): void
    {
        $this->assertStringContainsString('bracketed paste start', Inspector::parse("\x1b[200~")[0]->describe());
        $this->assertStringContainsString('bracketed paste end',   Inspector::parse("\x1b[201~")[0]->describe());
    }

    public function testSs3FunctionKeys(): void
    {
        $this->assertStringContainsString('F1', Inspector::parse("\x1bOP")[0]->describe());
        $this->assertStringContainsString('F4', Inspector::parse("\x1bOS")[0]->describe());
    }

    public function testOscWindowTitle(): void
    {
        $seg = Inspector::parse("\x1b]0;hello\x07")[0];
        $this->assertStringContainsString('set window title to "hello"', $seg->describe());
    }

    public function testOscHyperlink(): void
    {
        $seg = Inspector::parse("\x1b]8;;https://example.com\x1b\\")[0];
        $this->assertStringContainsString('hyperlink', $seg->describe());
    }

    public function testTwoByteEsc(): void
    {
        $this->assertStringContainsString('save cursor', Inspector::parse("\x1b7")[0]->describe());
        $this->assertStringContainsString('restore cursor', Inspector::parse("\x1b8")[0]->describe());
    }

    public function testMixedTextAndSequences(): void
    {
        $segs = Inspector::parse("\x1b[31mhello\x1b[0m world");
        $this->assertCount(4, $segs);
        $this->assertInstanceOf(SequenceSegment::class, $segs[0]);
        $this->assertInstanceOf(TextSegment::class,     $segs[1]);
        $this->assertSame('hello', $segs[1]->describe());
        $this->assertInstanceOf(SequenceSegment::class, $segs[2]);
        $this->assertInstanceOf(TextSegment::class,     $segs[3]);
        $this->assertSame(' world', $segs[3]->describe());
    }

    public function testReportRendersOneLinePerSegment(): void
    {
        $report = Inspector::report("\x1b[1mbold\x1b[0m");
        $lines  = explode("\n", $report);
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('bold',  $lines[0]);
        $this->assertSame('bold', $lines[1]);
        $this->assertStringContainsString('reset', $lines[2]);
    }

    public function testUnknownCsiFallsBackToGeneric(): void
    {
        // Random unrecognised CSI — should not crash, should describe.
        // Final byte 'Y' has no defined meaning in the dispatch table.
        $seg = Inspector::parse("\x1b[1;2Y")[0];
        $this->assertStringContainsString('CSI', $seg->describe());
    }

    public function testRawBytesPreserved(): void
    {
        $seg = Inspector::parse("\x1b[31m")[0];
        $this->assertSame("\x1b[31m", $seg->raw());
    }

    public function testBareEscAtEndOfInput(): void
    {
        $seg = Inspector::parse("hi\x1b")[1];
        $this->assertSame("\x1b", $seg->raw());
    }

    public function testDcsXTVERSIONReply(): void
    {
        $seg = Inspector::parse("\x1bP>|xterm(367)\x1b\\")[0];
        $this->assertStringContainsString('terminal version', $seg->describe());
        $this->assertStringContainsString('xterm(367)', $seg->describe());
    }

    public function testApcCandyZoneMarker(): void
    {
        $seg = Inspector::parse("\x1b_candyzone:S:btn-1\x1b\\")[0];
        $this->assertStringContainsString('CandyZone marker', $seg->describe());
        $this->assertStringContainsString('S:btn-1',          $seg->describe());
    }

    public function testApcKittyGraphics(): void
    {
        $seg = Inspector::parse("\x1b_GBASE64DATA\x1b\\")[0];
        $this->assertStringContainsString('kitty graphics', $seg->describe());
    }

    public function testCursorShape(): void
    {
        $seg = Inspector::parse("\x1b[2 q")[0];
        $this->assertStringContainsString('steady block', $seg->describe());
    }

    public function testScrollRegion(): void
    {
        $seg = Inspector::parse("\x1b[2;10r")[0];
        $this->assertStringContainsString('set scrolling region 2;10', $seg->describe());
        $reset = Inspector::parse("\x1b[r")[0];
        $this->assertStringContainsString('reset scrolling region', $reset->describe());
    }

    public function testScrollUpDown(): void
    {
        $seg = Inspector::parse("\x1b[3S")[0];
        $this->assertStringContainsString('scroll up 3', $seg->describe());
    }

    public function testTabForward(): void
    {
        $seg = Inspector::parse("\x1b[2I")[0];
        $this->assertStringContainsString('tab forward 2', $seg->describe());
    }

    public function testKittyKeyboardQuery(): void
    {
        $seg = Inspector::parse("\x1b[?u")[0];
        $this->assertStringContainsString('kitty keyboard query', $seg->describe());
    }

    public function testOscPaletteAndProgress(): void
    {
        $palette = Inspector::parse("\x1b]4;1;rgb:ff/00/00\x07")[0];
        $this->assertStringContainsString('palette', $palette->describe());

        $progress = Inspector::parse("\x1b]9;4;1;42\x07")[0];
        $this->assertStringContainsString('progress', $progress->describe());
    }

    public function testOscColourSetReset(): void
    {
        $set = Inspector::parse("\x1b]10;rgb:ff/00/00\x07")[0];
        $this->assertStringContainsString('set foreground colour', $set->describe());

        $reset = Inspector::parse("\x1b]110\x1b\\")[0];
        $this->assertStringContainsString('reset foreground colour', $reset->describe());
    }

    public function testDecPrivateExtras(): void
    {
        $sync = Inspector::parse("\x1b[?2026h")[0];
        $this->assertStringContainsString('synchronized output', $sync->describe());

        $unicode = Inspector::parse("\x1b[?2027h")[0];
        $this->assertStringContainsString('unicode', $unicode->describe());
    }

    public function testCsiRequestCursorPosition(): void
    {
        $seg = Inspector::parse("\x1b[6n")[0];
        $this->assertStringContainsString('request cursor position', $seg->describe());
    }

    // --- describeCsi branches ---

    public function testDecrpmReply(): void
    {
        // DECRPM (mode-state reply): CSI ?mode;state $y
        $seg = Inspector::parse("\x1b[?1;1" . '$y')[0];
        $this->assertStringContainsString('mode report (DECRPM)', $seg->describe());
    }

    public function testDecrqmQuery(): void
    {
        // DECRQM (mode-state query): CSI ?mode $p
        $seg = Inspector::parse("\x1b[?1" . '$p')[0];
        $this->assertStringContainsString('DEC private mode query (DECRQM)', $seg->describe());

        $seg2 = Inspector::parse("\x1b[1" . '$p')[0];
        $this->assertStringContainsString('mode query', $seg2->describe());
    }

    public function testXtversionRequest(): void
    {
        // XTVERSION request: CSI > 0 q
        $seg = Inspector::parse("\x1b[>0q")[0];
        $this->assertStringContainsString('request terminal version (XTVERSION)', $seg->describe());
    }

    public function testCursorShapes(): void
    {
        // Blinking underline
        $seg = Inspector::parse("\x1b[3 q")[0];
        $this->assertStringContainsString('blinking underline', $seg->describe());

        // Steady underline
        $seg = Inspector::parse("\x1b[4 q")[0];
        $this->assertStringContainsString('steady underline', $seg->describe());

        // Blinking bar
        $seg = Inspector::parse("\x1b[5 q")[0];
        $this->assertStringContainsString('blinking bar', $seg->describe());

        // Steady bar
        $seg = Inspector::parse("\x1b[6 q")[0];
        $this->assertStringContainsString('steady bar', $seg->describe());

        // Unknown shape
        $seg = Inspector::parse("\x1b[99 q")[0];
        $this->assertStringContainsString('shape 99', $seg->describe());
    }

    public function testKittyKeyboardFlags(): void
    {
        // Kitty keyboard reply with flags
        $seg = Inspector::parse("\x1b[?123u")[0];
        $this->assertStringContainsString('kitty keyboard reply, flags=123', $seg->describe());

        // Push kitty keyboard flags
        $seg = Inspector::parse("\x1b[>5u")[0];
        $this->assertStringContainsString('push kitty keyboard flags 5', $seg->describe());

        // Pop kitty keyboard layers
        $seg = Inspector::parse("\x1b[<3u")[0];
        $this->assertStringContainsString('pop kitty keyboard layers 3', $seg->describe());
    }

    public function testInsertChars(): void
    {
        $seg = Inspector::parse("\x1b[5@")[0];
        $this->assertStringContainsString('insert chars 5', $seg->describe());
    }

    public function testTabBackward(): void
    {
        $seg = Inspector::parse("\x1b[3Z")[0];
        $this->assertStringContainsString('tab backward 3', $seg->describe());
    }

    public function testClearTabStop(): void
    {
        // Clear single tab stop (not 3, which is "clear all")
        $seg = Inspector::parse("\x1b[0g")[0];
        $this->assertStringContainsString('clear tab stop', $seg->describe());
    }

    public function testScrollDown(): void
    {
        $seg = Inspector::parse("\x1b[2T")[0];
        $this->assertStringContainsString('scroll down 2', $seg->describe());
    }

    public function testRepeatChar(): void
    {
        $seg = Inspector::parse("\x1b[5b")[0];
        $this->assertStringContainsString('repeat preceding character 5', $seg->describe());
    }

    public function testCursorNextPrevLine(): void
    {
        $seg = Inspector::parse("\x1b[2E")[0];
        $this->assertStringContainsString('cursor next line 2', $seg->describe());

        $seg = Inspector::parse("\x1b[1F")[0];
        $this->assertStringContainsString('cursor prev line 1', $seg->describe());
    }

    public function testSaveRestoreCursor(): void
    {
        $seg = Inspector::parse("\x1b[s")[0];
        $this->assertStringContainsString('save cursor', $seg->describe());

        $seg = Inspector::parse("\x1b[u")[0];
        $this->assertStringContainsString('restore cursor', $seg->describe());
    }

    public function testDeleteLines(): void
    {
        $seg = Inspector::parse("\x1b[3M")[0];
        $this->assertStringContainsString('delete lines 3', $seg->describe());
    }

    public function testInsertLines(): void
    {
        $seg = Inspector::parse("\x1b[2L")[0];
        $this->assertStringContainsString('insert lines 2', $seg->describe());
    }

    public function testDeleteChars(): void
    {
        $seg = Inspector::parse("\x1b[3P")[0];
        // CSI P is Delete Character (DCH), not F1.
        $this->assertStringContainsString('delete chars 3', $seg->describe());
        $this->assertStringNotContainsString('F1', $seg->describe());
    }

    public function testCursorColumn(): void
    {
        $seg = Inspector::parse("\x1b[5G")[0];
        $this->assertStringContainsString('cursor column 5', $seg->describe());
    }

    public function testUnknownFinalByte(): void
    {
        // CSI with unhandled final byte falls to default
        $seg = Inspector::parse("\x1b[1;2X")[0];
        $this->assertStringContainsString('CSI', $seg->describe());
    }

    // --- describeTilde branches ---

    public function testDescribeTildeHome(): void
    {
        $seg = Inspector::parse("\x1b[1~")[0];
        $this->assertStringContainsString('Home', $seg->describe());
    }

    public function testDescribeTildeEnd(): void
    {
        $seg = Inspector::parse("\x1b[4~")[0];
        $this->assertStringContainsString('End', $seg->describe());
    }

    public function testDescribeTildeDelete(): void
    {
        $seg = Inspector::parse("\x1b[3~")[0];
        $this->assertStringContainsString('Delete', $seg->describe());
    }

    public function testDescribeTildePageUp(): void
    {
        $seg = Inspector::parse("\x1b[5~")[0];
        $this->assertStringContainsString('PageUp', $seg->describe());
    }

    public function testDescribeTildePageDown(): void
    {
        $seg = Inspector::parse("\x1b[6~")[0];
        $this->assertStringContainsString('PageDown', $seg->describe());
    }

    public function testDescribeTildeFunctionKeys(): void
    {
        $this->assertStringContainsString('F3',  Inspector::parse("\x1b[13~")[0]->describe());
        $this->assertStringContainsString('F4',  Inspector::parse("\x1b[14~")[0]->describe());
        $this->assertStringContainsString('F5',  Inspector::parse("\x1b[15~")[0]->describe());
        $this->assertStringContainsString('F6',  Inspector::parse("\x1b[17~")[0]->describe());
        $this->assertStringContainsString('F7',  Inspector::parse("\x1b[18~")[0]->describe());
        $this->assertStringContainsString('F8',  Inspector::parse("\x1b[19~")[0]->describe());
        $this->assertStringContainsString('F9',  Inspector::parse("\x1b[20~")[0]->describe());
        $this->assertStringContainsString('F10', Inspector::parse("\x1b[21~")[0]->describe());
        $this->assertStringContainsString('F11', Inspector::parse("\x1b[23~")[0]->describe());
        $this->assertStringContainsString('F12', Inspector::parse("\x1b[24~")[0]->describe());
    }

    public function testDescribeTildeUnknown(): void
    {
        $seg = Inspector::parse("\x1b[99~")[0];
        $this->assertStringContainsString('CSI 99~', $seg->describe());
    }

    // --- decPrivateName branches ---

    public function testDecPrivateNames(): void
    {
        $this->assertStringContainsString('auto wrap', Inspector::parse("\x1b[?7h")[0]->describe());
        $this->assertStringContainsString('cursor blink', Inspector::parse("\x1b[?12h")[0]->describe());
        $this->assertStringContainsString('cursor visibility', Inspector::parse("\x1b[?25h")[0]->describe());
        $this->assertStringContainsString('alternate screen (legacy)', Inspector::parse("\x1b[?47h")[0]->describe());
        $this->assertStringContainsString('focus reporting', Inspector::parse("\x1b[?1004h")[0]->describe());
        $this->assertStringContainsString('mouse SGR encoding', Inspector::parse("\x1b[?1006h")[0]->describe());
        $this->assertStringContainsString('mouse urxvt encoding', Inspector::parse("\x1b[?1015h")[0]->describe());
        $this->assertStringContainsString('save/restore cursor', Inspector::parse("\x1b[?1048h")[0]->describe());
        $this->assertStringContainsString('bracketed paste', Inspector::parse("\x1b[?2004h")[0]->describe());
    }

    public function testDecPrivateUnknown(): void
    {
        $seg = Inspector::parse("\x1b[?9999h")[0];
        $this->assertStringContainsString('DEC ?9999', $seg->describe());
    }

    // --- describeOsc branches ---

    public function testOscIconName(): void
    {
        $seg = Inspector::parse("\x1b]1;myicon\x07")[0];
        $this->assertStringContainsString('set icon name to "myicon"', $seg->describe());
    }

    public function testOscCwd(): void
    {
        $seg = Inspector::parse("\x1b]7;file:///home/user\x07")[0];
        $this->assertStringContainsString('cwd file:///home/user', $seg->describe());
    }

    public function testOscClipboard(): void
    {
        $seg = Inspector::parse("\x1b]52;c;base64data\x07")[0];
        $this->assertStringContainsString('clipboard c;base64data', $seg->describe());
    }

    public function testOscResetColour(): void
    {
        $seg = Inspector::parse("\x1b]111\x1b\\")[0];
        $this->assertStringContainsString('reset background colour', $seg->describe());

        $seg = Inspector::parse("\x1b]112\x1b\\")[0];
        $this->assertStringContainsString('reset cursor colour', $seg->describe());
    }

    public function testOscUnknown(): void
    {
        $seg = Inspector::parse("\x1b]999;payload\x07")[0];
        $this->assertStringContainsString('OSC 999;payload', $seg->describe());
    }

    // --- describeDcs branches ---

    public function testDcsDecrpssReply(): void
    {
        // Note: candy-ansi parses DCS per VT100 spec - 'r' and 'p' are final bytes,
        // '1' and '0' are params, '$' is intermediate. The sequence 1$r0$p is two
        // DECRPSS commands but the parser only captures the last final byte.
        // New semantic output reflects this structural interpretation.
        $seg = Inspector::parse("\x1bP1" . '$r0' . '$p' . "\x1b\\")[0];
        $this->assertStringContainsString('DCS r', $seg->describe());

        $seg = Inspector::parse("\x1bP0" . '$r1' . '$r' . "\x1b\\")[0];
        $this->assertStringContainsString('DCS r', $seg->describe());
    }

    public function testDcsSixel(): void
    {
        $seg = Inspector::parse("\x1bPq...sixeldata...\x1b\\")[0];
        $this->assertStringContainsString('sixel image', $seg->describe());
    }

    public function testDcsUnknown(): void
    {
        $seg = Inspector::parse("\x1bPtestpayload\x1b\\")[0];
        $this->assertStringContainsString('DCS testpayload', $seg->describe());
    }

    // --- describeSs3 branches ---

    public function testSs3CursorKeys(): void
    {
        $seg = Inspector::parse("\x1bOA")[0];
        $this->assertStringContainsString('cursor up', $seg->describe());

        $seg = Inspector::parse("\x1bOB")[0];
        $this->assertStringContainsString('cursor down', $seg->describe());

        $seg = Inspector::parse("\x1bOC")[0];
        $this->assertStringContainsString('cursor right', $seg->describe());

        $seg = Inspector::parse("\x1bOD")[0];
        $this->assertStringContainsString('cursor left', $seg->describe());

        $seg = Inspector::parse("\x1bOH")[0];
        $this->assertStringContainsString('Home', $seg->describe());

        $seg = Inspector::parse("\x1bOF")[0];
        $this->assertStringContainsString('End', $seg->describe());
    }

    public function testSs3Unknown(): void
    {
        $seg = Inspector::parse("\x1bOX")[0];
        $this->assertStringContainsString('SS3 X', $seg->describe());
    }

    // --- describeEsc branches ---

    public function testEscKeypadModes(): void
    {
        // Application keypad mode
        $seg = Inspector::parse("\x1b=")[0];
        $this->assertStringContainsString('application keypad mode', $seg->describe());

        // Normal keypad mode
        $seg = Inspector::parse("\x1b>")[0];
        $this->assertStringContainsString('normal keypad mode', $seg->describe());
    }

    public function testEscIndex(): void
    {
        // Index (move cursor down)
        $seg = Inspector::parse("\x1bD")[0];
        $this->assertStringContainsString('index (move cursor down)', $seg->describe());
    }

    public function testEscReverseIndex(): void
    {
        // Reverse index (move cursor up)
        $seg = Inspector::parse("\x1bM")[0];
        $this->assertStringContainsString('reverse index (move cursor up)', $seg->describe());
    }

    public function testEscNextLine(): void
    {
        $seg = Inspector::parse("\x1bE")[0];
        $this->assertStringContainsString('next line', $seg->describe());
    }

    public function testEscResetToInitialState(): void
    {
        $seg = Inspector::parse("\x1bc")[0];
        $this->assertStringContainsString('reset to initial state', $seg->describe());
    }

    public function testEscUnknown(): void
    {
        $seg = Inspector::parse("\x1bZ")[0];
        $this->assertStringContainsString('ESC Z', $seg->describe());
    }

    // --- describeSgr branches ---

    public function testDescribeSgrForegroundBasic(): void
    {
        // Test all 8 basic foreground colors
        foreach (['30', '31', '32', '33', '34', '35', '36', '37'] as $code) {
            $seg = Inspector::parse("\x1b[{$code}m")[0];
            $this->assertStringContainsString('foreground', $seg->describe());
        }
    }

    public function testDescribeSgrBackgroundBasic(): void
    {
        // Test all 8 basic background colors
        foreach (['40', '41', '42', '43', '44', '45', '46', '47'] as $code) {
            $seg = Inspector::parse("\x1b[{$code}m")[0];
            $this->assertStringContainsString('background', $seg->describe());
        }
    }

    public function testDescribeSgrAttributes(): void
    {
        $testCases = [
            ['2', 'faint'],
            ['3', 'italic'],
            ['4', 'underline'],
            ['5', 'blink'],
            ['7', 'reverse'],
            ['8', 'conceal'],
            ['9', 'strikethrough'],
            ['22', 'no bold/faint'],
            ['23', 'no italic'],
            ['24', 'no underline'],
            ['25', 'no blink'],
            ['27', 'no reverse'],
            ['28', 'no conceal'],
            ['29', 'no strikethrough'],
        ];
        foreach ($testCases as [$code, $expected]) {
            $seg = Inspector::parse("\x1b[{$code}m")[0];
            $this->assertStringContainsString($expected, $seg->describe());
        }
    }

    public function testDescribeSgrDefaults(): void
    {
        $seg = Inspector::parse("\x1b[39m")[0];
        $this->assertStringContainsString('foreground default', $seg->describe());

        $seg = Inspector::parse("\x1b[49m")[0];
        $this->assertStringContainsString('background default', $seg->describe());
    }

    public function testDescribeSgr256Unknown(): void
    {
        // 38;5;n with unknown sub-mode
        $seg = Inspector::parse("\x1b[38;5;255m")[0];
        $this->assertStringContainsString('foreground 256-color 255', $seg->describe());
    }

    // --- ansiName edge case ---

    public function testAnsiNameUnknownIndex(): void
    {
        // Trigger unknown ANSI color name (index out of range)
        $seg = Inspector::parse("\x1b[90m")[0];  // foreground bright + 0 = black
        $this->assertStringContainsString('foreground bright black', $seg->describe());
    }

    // --- Step 3: CSI P is DCH (delete chars), not F1 ---

    public function testCsiPIsDeleteChar(): void
    {
        $desc = Inspector::describeCsi('2', 'P');
        $this->assertStringContainsString('delete chars 2', $desc);
        $this->assertStringNotContainsString('F1', $desc);
    }

    public function testCsiQIsGenericCsiQ(): void
    {
        // CSI Q has no standard bare meaning; should fall through to generic CSI label.
        $desc = Inspector::describeCsi('', 'Q');
        $this->assertStringContainsString('CSI', $desc);
        $this->assertStringNotContainsString('F2', $desc);
    }

    // --- Step 4: sixel detection relies on DCS final byte, not 'sixel' substring ---

    public function testDcsSixelNoFalsePositive(): void
    {
        // Payload containing 'sixel' word but no final q byte must NOT claim sixel.
        $desc = Inspector::describeDcs('xsixelx');
        $this->assertStringNotContainsString('sixel image', $desc);
    }

    public function testDcsSixelByFinalQ(): void
    {
        // DCS with final 'q' byte must claim sixel.
        $desc = Inspector::describeDcs('', ord('q'));
        $this->assertStringContainsString('sixel image', $desc);
    }

    // --- Step 5: OSC/APC control-byte sanitization in describe labels ---

    public function testBinaryOscNoRawEscInLabel(): void
    {
        // An embedded ESC in an OSC title must not re-arm a sequence when
        // the report is printed to a terminal.
        $seg = Inspector::parse("\x1b]0;ab\x1bcd\x07")[0];
        $desc = $seg->describe();
        // The ESC byte must be replaced with a visible token.
        $this->assertStringNotContainsString("\x1b", $desc);
        $this->assertStringContainsString('set window title', $desc);
    }

    // --- Step 8: truncated extended-color detection ---

    public function testTruncatedTruecolorIsFlagged(): void
    {
        // 38;2;10 is incomplete (missing G and B channels).
        $desc = Inspector::describeCsi('38;2;10', 'm');
        $this->assertStringContainsString('truncated', $desc);
        $this->assertStringNotContainsString('rgb(10,0,0)', $desc);
    }

    public function testTruncated256IsFlagged(): void
    {
        // 38;5 is incomplete (missing the color index).
        $desc = Inspector::describeCsi('38;5', 'm');
        $this->assertStringContainsString('truncated', $desc);
        $this->assertStringNotContainsString('256-color 0', $desc);
    }
}

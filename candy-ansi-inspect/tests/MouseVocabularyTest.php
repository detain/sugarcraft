<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiInspect\AnsiHandler;
use SugarCraft\AnsiInspect\Inspector;

/**
 * Mouse event decoding (finding 006) and the consolidated vocabulary wave
 * (finding 020): every member shape gets its named label — the report is a
 * lookup table, not just a labeler. X10 additionally pins the handler's
 * arm/abort behaviour because its payload bytes arrive AFTER the dispatch.
 */
final class MouseVocabularyTest extends TestCase
{
    public function testSgrMousePressReleaseWheelAndModifiers(): void
    {
        // The live-probe shapes from finding 006 — all three used to read
        // "delete lines …" / "SGR reset, SGR …".
        $this->assertSame('mouse SGR press btn0 @10,10', Inspector::describeCsi('<0;10;10', 'M'));
        $this->assertSame('mouse SGR release btn0 @10,10', Inspector::describeCsi('<0;10;10', 'm'));
        $this->assertSame('mouse SGR motion wheel-up @12,9', Inspector::describeCsi('<35;12;9', 'M'));
        $this->assertSame('mouse SGR release wheel-up @12,9', Inspector::describeCsi('<35;12;9', 'm'));
        $this->assertSame('mouse SGR press btn0 @5,5 +ctrl', Inspector::describeCsi('<16;5;5', 'M'));
        $this->assertSame('mouse SGR motion btn1 @7,8 +shift +meta', Inspector::describeCsi('<45;7;8', 'M'));
    }

    public function testUrxvtMouseEchoesTheRawButtonCodeHonestly(): void
    {
        $this->assertSame('mouse urxvt press code35 @12,9', Inspector::describeCsi('>35;12;9', 'M'));
        $this->assertSame('mouse urxvt release code35 @12,9', Inspector::describeCsi('>35;12;9', 'm'));
    }

    public function testX10MouseArmCollectsThreePayloadBytes(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[M !!later");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1b[M !!", $segments[0]->raw(), 'the three payload bytes ride in the report');
        $this->assertSame('ESC[M !!  mouse X10 press btn0 @2,2', $segments[0]->describe());
        $this->assertSame('later', $segments[1]->raw());
    }

    public function testX10WheelDragAndReleaseBands(): void
    {
        // Wire values: button byte = 32 + code; code 0-2 buttons, 3 release,
        // 64/65 wheel-up/down, code+32 drag, +4 shift / +8 meta / +16 ctrl.
        $this->assertSame('mouse X10 wheel-up @66,67', Inspector::describeMouseX10('`ab'));
        $this->assertSame('mouse X10 wheel-down @66,67', Inspector::describeMouseX10('aab'));
        $this->assertSame('mouse X10 release @66,67', Inspector::describeMouseX10('#ab'));
        $this->assertSame('mouse X10 drag btn0 @66,67', Inspector::describeMouseX10('@ab'));
        $this->assertSame('mouse X10 press btn0 @66,67 +ctrl', Inspector::describeMouseX10('0ab'));
    }

    public function testIncompleteX10ArmReportsEveryByteItGot(): void
    {
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[M \x1b[0m");

        $this->assertCount(2, $segments);
        $this->assertSame("\x1b[M ", $segments[0]->raw());
        $this->assertSame('ESC[M   X10 mouse report (incomplete payload)', $segments[0]->describe());
        $this->assertSame('ESC[0m  SGR reset', $segments[1]->describe());

        // Stream end mid-payload conserves the bytes the same way.
        $segments = $handler->parse("\x1b[Mab");
        $this->assertSame("\x1b[Mab", $segments[0]->raw());
        $this->assertStringContainsString('incomplete payload', $segments[0]->describe());
    }

    public function testRealDeleteLinesStillReadsAsDeleteLines(): void
    {
        // The disambiguation trade: `ESC[M` ALONE (no payload trail) is what
        // an old terminal would send for DL — the arm gives it the X10
        // reading only while payload bytes actually follow. A counted DL is
        // never affected.
        $handler = new AnsiHandler();
        $segments = $handler->parse("\x1b[3M");
        $this->assertSame('ESC[3M  delete lines 3', $segments[0]->describe());
    }

    public function testDeviceAttributesVocabulary(): void
    {
        $this->assertSame('request primary device attributes (DA1)', Inspector::describeCsi('', 'c'));
        $this->assertSame('request secondary device attributes (DA2)', Inspector::describeCsi('>', 'c'));
        $this->assertSame('request tertiary device attributes (DA3) 0', Inspector::describeCsi('=0', 'c'));
        $this->assertSame('device attributes query (private) ?62;', Inspector::describeCsi('?62;', 'c'));
        $this->assertSame(
            'device attributes reply 63;1;2',
            Inspector::describeCsi('63;1;2', 'c'),
            'a DA1 REPLY is no longer "CSI 63;1;2 c"',
        );
    }

    public function testSgrQueryAndPositionFinalsAreNamed(): void
    {
        $this->assertSame('request operating status (DSR)', Inspector::describeCsi('5', 'n'));
        $this->assertSame('cursor position (HVP) 1;2', Inspector::describeCsi('1;2', 'f'));
        $this->assertSame('line position absolute (VPR) 3', Inspector::describeCsi('3', 'd'));
        $this->assertSame('column position absolute (HPR) 4', Inspector::describeCsi('4', 'e'));
        $this->assertSame('erase chars (ECH) 5', Inspector::describeCsi('5', 'X'));
        $this->assertSame('window manipulation (XTWINOPS) 22', Inspector::describeCsi('22', 't'));
        $this->assertSame('request terminal version (XTVERSION)', Inspector::describeCsi('>0 ', 'q'), 'space spelling');
        $this->assertSame('request terminal version (XTVERSION)', Inspector::describeCsi('>0', 'q'), 'glued spelling');
    }

    public function testTildeDecPrivateAndSgrNameGapsAreClosed(): void
    {
        $this->assertSame('Insert', Inspector::describeCsi('2', '~'));
        $this->assertSame('enable cursor keys application mode (DECCKM)', Inspector::describeCsi('?1', 'h'));
        $this->assertSame('disable origin mode (DECOM)', Inspector::describeCsi('?6', 'l'));
        $this->assertSame('enable UTF-8 mouse encoding (legacy)', Inspector::describeCsi('?1005', 'h'));
        $this->assertSame('enable focused-output mode', Inspector::describeCsi('?2031', 'h'));

        // sgrName gaps: 26, 50-55, 59, 60-65, 73-75 (6/21 are AIP-42's).
        foreach ([
            '26' => 'proportional font off',
            '50' => 'proportional font (character spacing)',
            '51' => 'framed',
            '52' => 'encircled',
            '53' => 'overlined',
            '54' => 'framed/encircled off',
            '55' => 'overlined off',
            '59' => 'underline colour default',
            '73' => 'superscript',
            '74' => 'subscript',
            '75' => 'super/subscript off',
        ] as $code => $label) {
            $this->assertStringContainsString($label, Inspector::describeCsi((string) $code, 'm'), "SGR $code");
        }
        $this->assertStringContainsString(
            'off extended-underline band (cancels 21)',
            Inspector::describeCsi('60', 'm'),
        );
    }

    public function testOscHyperlinkQueryAndPaletteVocabulary(): void
    {
        $this->assertSame(
            'hyperlink (id=bench) to https://example.com',
            Inspector::describeOsc('8;id=bench;https://example.com'),
        );
        $this->assertSame('hyperlink (no URI)', Inspector::describeOsc('8;;'), '8;; closes the link');
        $this->assertSame('query foreground colour', Inspector::describeOsc('10;?'));
        $this->assertSame('query background colour', Inspector::describeOsc('11;?'));
        $this->assertSame('query cursor colour', Inspector::describeOsc('12;?'));
        $this->assertSame('set foreground colour rgb:a/b/c', Inspector::describeOsc('10;rgb:a/b/c'));
        $this->assertSame('reset colour palette register 3', Inspector::describeOsc('104;3'));
        $this->assertSame('reset colour palette', Inspector::describeOsc('104'));
        $this->assertSame('shell integration (OSC 133) A', Inspector::describeOsc('133;A'));
        $this->assertSame(
            'iTerm2 private sequence (OSC 1337) CurrentDir=/tmp',
            Inspector::describeOsc('1337;CurrentDir=/tmp'),
        );
    }

    public function testMarkerPrefixedAndMalformedSgrNeverClaimReset(): void
    {
        // AIP-08 (finding 007): the (int)-cast fabrication class — a private
        // or marker-prefixed `m` is NOT an SGR reset.
        $this->assertSame('private SGR ?7', Inspector::describeCsi('?7', 'm'));
        $this->assertSame('SGR-marker :5', Inspector::describeCsi(':5', 'm'));
        $this->assertSame('SGR-marker 1:x', Inspector::describeCsi('1:x', 'm'));
        $this->assertSame('set modifyOtherKeys 2 (xterm)', Inspector::describeCsi('>4;2', 'm'));
        $this->assertSame('set modifyOtherKeys 3 (xterm)', Inspector::describeCsi('>4:3', 'm'));
        // Plain and colon-spelled real SGRs keep flowing.
        $this->assertSame('SGR underline curly', Inspector::describeCsi('4:3', 'm'));
        $this->assertSame('SGR foreground 256-color 200', Inspector::describeCsi('38;5;200', 'm'));
    }

    public function testUnderlineColourExtendedArm(): void
    {
        // AIP-09 (finding 008): 58 reads the same colour grammar as 38/48 and
        // never re-reads its trailing component as a plain SGR 4.
        $this->assertSame('SGR underline colour 256-color 4', Inspector::describeCsi('58;5;4', 'm'));
        $this->assertSame('SGR underline colour rgb(10,20,30)', Inspector::describeCsi('58;2;10;20;30', 'm'));
        $this->assertSame('SGR underline colour rgb(80,160,240)', Inspector::describeCsi('58:2::80:160:240', 'm'));
        $this->assertSame('SGR underline colour 256-color 9', Inspector::describeCsi('58:5:9', 'm'));
        $this->assertSame('SGR underline colour truncated 256-color', Inspector::describeCsi('58;5', 'm'));
        $this->assertSame('SGR underline colour default', Inspector::describeCsi('59', 'm'));
    }

    public function testKittyKeyboardMarkersAreNamed(): void
    {
        // AIP-13 (finding 015): all four markers plus the digit pop keep
        // their own names — the old table let `ESC[=5u`/`ESC[1u` answer
        // "restore cursor". Parameterless `CSI u` remains ECMA-48 SCR.
        $this->assertSame('set kitty keyboard flags 5', Inspector::describeCsi('=5', 'u'));
        $this->assertSame('push kitty keyboard flags 3', Inspector::describeCsi('>3', 'u'));
        $this->assertSame('pop kitty keyboard layers 2', Inspector::describeCsi('<2', 'u'));
        $this->assertSame('pop kitty keyboard layers 1', Inspector::describeCsi('1', 'u'));
        $this->assertSame('kitty keyboard query', Inspector::describeCsi('?', 'u'));
        $this->assertSame('kitty keyboard reply, flags=7', Inspector::describeCsi('?7', 'u'));
        $this->assertSame('restore cursor', Inspector::describeCsi('', 'u'));

        // Wire shape: the pop travels through the handler intact.
        $segments = Inspector::parse("\x1b[=5u\x1b[1u");
        $this->assertSame("\x1b[=5u\x1b[1u", implode('', array_map(static fn($s): string => $s->raw(), $segments)));
        $this->assertStringContainsString('set kitty keyboard flags 5', $segments[0]->describe());
        $this->assertStringContainsString('pop kitty keyboard layers 1', $segments[1]->describe());
    }
}

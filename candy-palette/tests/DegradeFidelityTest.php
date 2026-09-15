<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Color;
use SugarCraft\Palette\Palette;
use SugarCraft\Palette\Profile;

/**
 * degrade() fidelity matrix (audit §A item 4).
 *
 * Pins the three reported corruption classes with byte-exact assertions:
 *  - channel swaps from mis-indexed capture groups (green→blue, blue→black),
 *  - basic/bright collapse from decoding palette slots 0-15 through the cube
 *    formula (bright red→black),
 *  - underline / underline-color loss or leakage around color parameters.
 *
 * Expected indices below are hand-computed against the documented xterm
 * ramps: cube axes 0/95/135/175/215/255 (16 + 36R + 6G + B), grey ramp
 * 8/18/…/238 (232 + i), and this lib's ANSI-16 entry table (Color::ANSI16_RGB).
 */
final class DegradeFidelityTest extends TestCase
{
    private static function palette(Profile $profile): Palette
    {
        // FORCE_COLOR is irrelevant here — withProfile() pins the target, and
        // degrade() only consults the profile.
        return (new Palette(env: ['FORCE_COLOR' => '3']))->withProfile($profile);
    }

    // -------------------------------------------------------------------------
    // Slot fidelity — all 256 entries
    // -------------------------------------------------------------------------

    /**
     * At ANSI256 every addressed slot is already exact: rewriting must be
     * the identity on `38;5;n`, `48;5;n` AND `58;5;n` for every n 0-255.
     * (Slot 9 is bright red on the user's terminal; decoding it to RGB and
     * re-quantising onto the fixed cube is what turned it into 16 = black.)
     */
    public function testEverySlotPassesThroughAtAnsi256(): void
    {
        $p = self::palette(Profile::ANSI256);
        for ($n = 0; $n <= 255; $n++) {
            foreach ([38, 48, 58] as $intro) {
                $in = "\x1b[{$intro};5;{$n}m";
                $this->assertSame($in, $p->degrade($in), "slot {$intro};5;{$n} must survive ANSI256 intact");
            }
        }
    }

    /**
     * At ANSI (16 colours) each basic slot 0-15 must degrade to its OWN
     * 4-bit SGR — the basic/bright split is exact for exact slot values,
     * never collapsed to black or shifted up a level.
     *
     * @return array<string,array{string,string}>
     */
    public static function basicSlotProvider(): array
    {
        $cases = [];
        foreach (range(0, 15) as $idx) {
            $sgr = Color::ansi16Sgr($idx, false);
            $cases["slot {$idx}"] = ["\x1b[38;5;{$idx}m", "\x1b[{$sgr}m"];
            $bgSgr = Color::ansi16Sgr($idx, true);
            $cases["bg slot {$idx}"] = ["\x1b[48;5;{$idx}m", "\x1b[{$bgSgr}m"];
        }
        return $cases;
    }

    /**
     * @dataProvider basicSlotProvider
     */
    public function testBasicSlotsDegradeToTheirOwnFourBitCodes(string $in, string $expected): void
    {
        $this->assertSame($expected, self::palette(Profile::ANSI)->degrade($in));
    }

    /**
     * Hand-computed cube/grey → 4-bit selections (guards the decode ramp,
     * the R·36+G·6+B index order and the full-16 nearest search together).
     *
     * @return array<string,array{int,string}>
     */
    public static function slotToFourBitProvider(): array
    {
        return [
            // 16=(0,0,0)        → black
            'cube black'    => [16, "\x1b[30m"],
            // 20=(0,0,215)      → nearest is slot 4 (0,0,205), d=100 vs slot 12 d=1600
            'deep blue'     => [20, "\x1b[34m"],
            // 21=(0,0,255)      → exactly slot 12
            'pure blue'     => [21, "\x1b[94m"],
            // 30=(0,175,175)    → slot 6 (0,205,205) d=900 vs 14 d=12800
            'teal'          => [30, "\x1b[36m"],
            // 46=(0,255,0)      → exactly slot 10 (bright green, NOT 32 basic)
            'pure green'    => [46, "\x1b[92m"],
            // 196=(255,0,0)     → exactly slot 9 — the bright→black regression
            'pure red'      => [196, "\x1b[91m"],
            // 226=(255,255,0)   → exactly slot 11
            'pure yellow'   => [226, "\x1b[93m"],
            // 231=(255,255,255) → exactly slot 15
            'pure white'    => [231, "\x1b[97m"],
            // 232=(8,8,8)       → slot 0 (0,0,0) d=192 beats slot 8 (127³) by far
            'first grey'    => [232, "\x1b[30m"],
            // 244=(128,128,128) → exactly slot 8 (bright black)
            'mid grey'      => [244, "\x1b[90m"],
            // 254=(228,228,228) → slot 7 (229,229,229) d=3 — basic white, not bright
            'near white'    => [254, "\x1b[37m"],
        ];
    }

    /**
     * @dataProvider slotToFourBitProvider
     */
    public function testSelectedSlotsDegradeToHandComputedFourBitCodes(int $slot, string $expected): void
    {
        $in = "\x1b[38;5;{$slot}m";
        $this->assertSame($expected, self::palette(Profile::ANSI)->degrade($in));
    }

    // -------------------------------------------------------------------------
    // fromAnsi256Index ↔ toAnsi256Index round trip
    // -------------------------------------------------------------------------

    /**
     * The cube + grey ramp (16-255) is fixed xterm hardware, so decode →
     * nearest-encode is exact: identity for every one of the 240 slots.
     */
    public function testRoundTripIsIdentityAcrossTheFixedPalette(): void
    {
        for ($n = 16; $n <= 255; $n++) {
            $this->assertSame($n, Color::fromAnsi256Index($n)->toAnsi256Index(), "slot {$n} round trip");
        }
    }

    /**
     * Slots 0-15 are themeable, so RGB (the only thing toAnsi256Index can
     * reason about) cannot name them: each collapses onto the nearest FIXED
     * entry. Documented, not a bug:
     *
     *  0 black  (0,0,0)   →16 · 1 red  (205,0,0)→160 · 2 green→40 · 3 yellow→184
     *  4 blue   (0,0,205) →20 · 5 magenta→164        · 6 cyan→44
     *  7 white  (229³)    →254 (grey ramp d=3 beats cube 231 d=2028)
     *  8 brblk  (127³)    →244 · 9 brred→196 · 10 brgrn→46 · 11 bryel→226
     * 12 brblu (0,0,255)  →21  · 13 brmag→201        · 14 brcyn→51  · 15 brwht→231
     */
    public function testRoundTripCollapseOfThemeableSlotsIsDocumented(): void
    {
        $expected = [16, 160, 40, 184, 20, 164, 44, 254, 244, 196, 46, 226, 21, 201, 51, 231];
        foreach ($expected as $slot => $want) {
            $this->assertSame($want, Color::fromAnsi256Index($slot)->toAnsi256Index(), "basic slot {$slot}");
        }
    }

    // -------------------------------------------------------------------------
    // Truecolor probes — hand-computed cube/grey selection
    // -------------------------------------------------------------------------

    /**
     * @return array<string,array{array{int,int,int},int}>
     */
    public static function trueColorProbeProvider(): array
    {
        return [
            // hot pink: (255,95,175) d=100+25 vs grey (180 avg) far → cube 16+180+6+3
            'hot pink'      => [[255, 105, 180], 205],
            // dark grey 63: nearest level per axis is 95 (cube d=3072) but grey bin
            // round((63-8)/10)=6 → (68,68,68) d=75 → ramp 232+6
            'charcoal'      => [[63, 63, 63], 238],
            // violet: (95,95,255) d=144+225 beats grey (avg 147 → 148: d huge) → 16+36+6+5
            'violet'        => [[107, 80, 255], 63],
            // dark green: g=128 → level 2 (135) → 16+2*6=28
            'dark green'    => [[0, 128, 0], 28],
            // xterm slot-8 grey: ramp 244 (128³) d=3 beats cube 135³ d=192
            'grey 127'      => [[127, 127, 127], 244],
            // xterm slot-1 red lands on cube (215,0,0)=160 (r 205 → level 4)
            'xterm red'     => [[205, 0, 0], 160],
            // top of the grey ramp is exact
            'grey 238'      => [[238, 238, 238], 255],
            // white/black shortcuts fall out of the ramp: 16 and 231
            'black'         => [[0, 0, 0], 16],
            'white'         => [[255, 255, 255], 231],
        ];
    }

    /**
     * @dataProvider trueColorProbeProvider
     * @param array{int,int,int} $rgb
     */
    public function testTrueColorProbesQuantiseToHandComputedIndices(array $rgb, int $expectedIndex): void
    {
        [$r, $g, $b] = $rgb;
        $out = self::palette(Profile::ANSI256)
            ->degrade("\x1b[38;2;{$r};{$g};{$b}m");
        $this->assertSame("\x1b[38;5;{$expectedIndex}m", $out);
    }

    public function testChannelOrderSurvivesDegrade(): void
    {
        // The pre-fix callback read capture group 5 (the whole ";G;B" string,
        // int-cast to 0) as green and group 6 as blue — swapping channels so
        // green degraded to blue and blue to black.
        $p = self::palette(Profile::ANSI);
        $this->assertSame("\x1b[32m", $p->degrade("\x1b[38;2;0;205;0m"));
        $this->assertSame("\x1b[94m", $p->degrade("\x1b[38;2;0;0;255m"));
        $this->assertSame("\x1b[91m", $p->degrade("\x1b[38;2;255;0;0m"));
        $this->assertSame("\x1b[104m", $p->degrade("\x1b[48;2;0;0;255m"));
    }

    // -------------------------------------------------------------------------
    // Underline + underline colour
    // -------------------------------------------------------------------------

    public function testUnderlineSurvivesAttributeBeforeColor(): void
    {
        // Pre-fix: the regex was anchored directly after CSI, so `\e[4;38;5;196m`
        // was never rewritten at all — 196 leaked to a 16-colour terminal.
        $p = self::palette(Profile::ANSI);
        $this->assertSame("\x1b[4;58;5;9m", $p->degrade("\x1b[4;58;2;255;0;0m"));
        $this->assertSame("\x1b[4;91m", $p->degrade("\x1b[4;38;5;196m"));
    }

    public function testUnderlineSurvivesColorBeforeAttribute(): void
    {
        // Trailing parameters after the colour must be kept: rewriting the
        // colour may not DELETE the underline sitting behind it.
        $this->assertSame(
            "\x1b[38;5;196;4m",
            self::palette(Profile::ANSI256)->degrade("\x1b[38;5;196;4m"),
        );
        $this->assertSame(
            "\x1b[91;4m",
            self::palette(Profile::ANSI)->degrade("\x1b[38;2;255;0;0;4m"),
        );
    }

    public function testUnderlineColorRewrittenAtAnsi256(): void
    {
        $this->assertSame(
            "\x1b[4;58;5;63m",
            self::palette(Profile::ANSI256)->degrade("\x1b[4;58;2;100;50;255m"),
        );
    }

    public function testUnderlineColorSlotPreservedAndBgFgDegradeTogether(): void
    {
        // fg truecolor + bg slot + underline + underline colour in ONE list.
        $out = self::palette(Profile::ANSI)->degrade("\x1b[38;2;100;50;255;48;5;17;4;58;5;196m");
        // 100,50,255 → bright blue (94); slot 17 = (0,0,95) is nearer black
        // (d=9025) than blue (d=12100) → 40; underline and 58;5;9 survive verbatim.
        $this->assertSame("\x1b[94;40;4;58;5;9m", $out);
    }

    public function testUnderlineColorResetAndDoubleUnderlinePassThrough(): void
    {
        $p = self::palette(Profile::ANSI);
        $this->assertSame("\x1b[21m", $p->degrade("\x1b[21m"));
        $this->assertSame("\x1b[59m", $p->degrade("\x1b[59m"));
        $this->assertSame("\x1b[58;5;3m", $p->degrade("\x1b[58;5;3m"));
    }

    public function testAsciiProfileKeepsUnderlineOnNearestBlackWhite(): void
    {
        $out = self::palette(Profile::Ascii)->degrade("\x1b[4;58;2;255;0;0;38;2;10;10;10m");
        $this->assertSame("\x1b[4;58;5;15;30m", $out);
    }

    // -------------------------------------------------------------------------
    // Non-colour SGR passthrough + ECMA-48 parameter groups
    // -------------------------------------------------------------------------

    public function testNonColourSgrParametersPassThroughUntouched(): void
    {
        $p = self::palette(Profile::ANSI);
        foreach ([
            "\x1b[1;2;3;4;5;7;8;9;21;53m",
            "\x1b[0m",
            "\x1b[m",
            "\x1b[39;49m",
            "\x1b[4:3m",       // curly underline — colon sub-parameter form
            "\x1b[4:0:1m",     // multiple sub-parameters
        ] as $seq) {
            $this->assertSame($seq, $p->degrade($seq));
        }
    }

    public function testMixedAttributesAndColorKeepEveryOtherParameter(): void
    {
        $p = self::palette(Profile::ANSI);
        $out = $p->degrade("\x1b[1;4:3;38;2;0;255;0;7;48;5;52m");
        // bold, curly underline, inverse survive; green → bright green (slot 10);
        // 52=(95,0,0): cube r=1,g=0,b=0 → nearest black (d=9025 vs red 12100) → 40.
        $this->assertSame("\x1b[1;4:3;92;7;40m", $out);
    }

    public function testColonColourFormsAreRewritten(): void
    {
        $this->assertSame(
            "\x1b[91m",
            self::palette(Profile::ANSI)->degrade("\x1b[38:2::255:0:0m"),
        );
        $this->assertSame(
            "\x1b[38;5;196m",
            self::palette(Profile::ANSI256)->degrade("\x1b[38:5:196m"),
        );
    }

    public function testMissingColourArgumentsDefaultToZero(): void
    {
        // ECMA-48 / xterm: truncated specs default absent components to 0.
        $p = self::palette(Profile::ANSI256);
        $this->assertSame("\x1b[38;5;0m", $p->degrade("\x1b[38;5m"));
        $this->assertSame("\x1b[38;5;0m", $p->degrade("\x1b[38m"));
    }

    public function testOutOfRangeSlotIsQuantisedNotCrashed(): void
    {
        // 300 clamps into the grey ramp top (255 = (238,238,238)) → re-encodes 255.
        $this->assertSame(
            "\x1b[38;5;255m",
            self::palette(Profile::ANSI256)->degrade("\x1b[38;5;300m"),
        );
    }

    public function testTextAroundSequencesIsBytePreserved(): void
    {
        $in = "a\x1b[38;5;9mb\x1b[0mc\x1b[4md";
        $this->assertSame(
            "a\x1b[91mb\x1b[0mc\x1b[4md",
            self::palette(Profile::ANSI)->degrade($in),
        );
    }

    // -------------------------------------------------------------------------
    // Review-round regressions (colon tolerance fields, hostile inputs)
    // -------------------------------------------------------------------------

    /**
     * ECMA-48 allows a colour-space id / tolerance ahead of the components
     * (`38:2:cs:tol:r:g:b`); the components are always the LAST three
     * sub-parameters — reading them from the front shifts every channel.
     */
    public function testColonToleranceFieldsDoNotShiftChannels(): void
    {
        $p = self::palette(Profile::ANSI);
        $this->assertSame("\x1b[91m", $p->degrade("\x1b[38:2:1:255:0:0m"));
        $this->assertSame("\x1b[91m", $p->degrade("\x1b[38:2:0:0:255:0:0m"));
        $this->assertSame("\x1b[92m", $p->degrade("\x1b[38:2:5:0:255:0m"));
        $this->assertSame("\x1b[91m", $p->degrade("\x1b[38;2:1;255;0;0m"));
        // Semicolon continuation after a tolerance, underline-colour depth:
        $this->assertSame(
            "\x1b[58;5;9m",
            $p->degrade("\x1b[58;2:3;255;0;0m"),
        );
        $this->assertSame(
            "\x1b[58;5;196m",
            self::palette(Profile::ANSI256)->degrade("\x1b[58;2:3;255;0;0m"),
        );
    }

    public function testIncompleteColonSpecDefaultsMissingComponentsToZero(): void
    {
        // `38:2:1:2` → (1,2,0), matching the semicolon path and xterm.
        $this->assertSame(
            "\x1b[38;5;16m",
            self::palette(Profile::ANSI256)->degrade("\x1b[38:2:1:2m"),
        );
    }

    public function testLeadingZeroPaddingStillIntroducesColour(): void
    {
        // ECMA-48 parameter padding: `038` is 38.
        $this->assertSame(
            "\x1b[91m",
            self::palette(Profile::ANSI)->degrade("\x1b[038;5;196m"),
        );
        $this->assertSame(
            "\x1b[38;5;196m",
            self::palette(Profile::ANSI256)->degrade("\x1b[038;5;196m"),
        );
    }

    public function testAbsurdIntegerDoesNotOverflowThroughInfToBlack(): void
    {
        // A 600-digit run would cast through INF to 0 ("huge red" → black);
        // capped literals saturate like any component above 255 instead.
        foreach ([20, 600] as $digits) {
            $this->assertSame(
                "\x1b[38;5;196m",
                self::palette(Profile::ANSI256)->degrade("\x1b[38;2;" . str_repeat('9', $digits) . ";0;0m"),
                "{$digits}-digit literal",
            );
        }
    }

    public function testOverlongParameterListPassesThroughUntouched(): void
    {
        // Hostile megabyte-scale lists must not be explode()d at all.
        $in = "\x1b[38;5;196;" . str_repeat('1;', 5000) . 'm';
        $this->assertSame($in, self::palette(Profile::ANSI256)->degrade($in));
        $this->assertSame($in, self::palette(Profile::ANSI)->degrade($in));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use SugarCraft\ColorProfile\DetectionChain;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Profile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\ColorProfile\DetectionChain
 *
 * The 1-13 ladder numbering asserted throughout this file is the enumeration
 * in DetectionChain's class docblock and the README ladder section — the three
 * are parallel-maintained; change the ladder and you change all three.
 */
final class DetectionChainTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, string|null>, 1: string, 2: string}>
     */
    public static function detectionProvider(): array
    {
        return [
            // CLICOLOR_FORCE=1 → truecolor
            'clicolor_force_1 returns truecolor' => [
                ['CLICOLOR_FORCE' => '1', 'TERM' => 'dumb'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:CLICOLOR_FORCE',
            ],
            // CP-27: the FORCE_COLOR numeric ladder the pins never carried —
            // level floors answered straight from step 2, outranking TERM
            // rungs on both sides (the roster's documented order).
            'force_color_1 returns ansi' => [
                ['FORCE_COLOR' => '1'],
                DetectionChain::LEVEL_ANSI,
                'env:FORCE_COLOR',
            ],
            'force_color_2 returns ansi256' => [
                ['FORCE_COLOR' => '2', 'TERM' => 'dumb'],
                DetectionChain::LEVEL_ANSI256,
                'env:FORCE_COLOR',
            ],
            'force_color_3 returns truecolor' => [
                ['FORCE_COLOR' => '3', 'TERM' => 'unknownterm'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:FORCE_COLOR',
            ],
            'force_color_word_true returns truecolor' => [
                ['FORCE_COLOR' => 'true', 'TERM' => 'unknownterm'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:FORCE_COLOR',
            ],
            // NO_COLOR set → ascii (the colorless rung — CP-05 Shape B:
            // color SGR dropped, decoration kept; NoTTY is hardware absence only)
            'no_color returns ascii' => [
                ['NO_COLOR' => '1'],
                DetectionChain::LEVEL_ASCII,
                'env:NO_COLOR',
            ],
            // no-color.org: NO_COLOR set to the EMPTY string means UNSET —
            // the chain falls through to the TERM rungs (flipped with the
            // MT2 cure; the old pin treated "" as "set"). Since CP-10 the
            // fall-through lands on the colorless floor: `unknownterm` is
            // no longer over-detected as 16-color.
            'no_color empty string is unset' => [
                ['NO_COLOR' => '', 'TERM' => 'unknownterm'],
                DetectionChain::LEVEL_ASCII,
                'fallback:default',
            ],
            // CLICOLOR=0 → ascii (colorless rung — CP-05)
            'clicolor_0 returns ascii' => [
                ['CLICOLOR' => '0'],
                DetectionChain::LEVEL_ASCII,
                'env:CLICOLOR=0',
            ],
            // COLORTERM=24bit|truecolor → truecolor; yes → ansi256 (CP-09 split)
            'colorterm_24bit returns truecolor' => [
                ['COLORTERM' => '24bit', 'TERM' => 'xterm'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:COLORTERM=24bit',
            ],
            'colorterm_truecolor returns truecolor' => [
                ['COLORTERM' => 'truecolor', 'TERM' => 'xterm'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:COLORTERM=truecolor',
            ],
            'colorterm_yes returns ansi256 (CP-09 — legacy marker, 256-color promise only)' => [
                ['COLORTERM' => 'yes', 'TERM' => 'xterm'],
                DetectionChain::LEVEL_ANSI256,
                'env:COLORTERM=yes',
            ],
            // TERM=dumb → ascii (colorless rung — CP-05)
            'term_dumb returns ascii' => [
                ['TERM' => 'dumb'],
                DetectionChain::LEVEL_ASCII,
                'env:TERM=dumb',
            ],
            // TERM=dumb outranks COLORTERM (unified dumb-first ladder)
            'term_dumb vetoes colorterm' => [
                ['COLORTERM' => 'truecolor', 'TERM' => 'dumb'],
                DetectionChain::LEVEL_ASCII,
                'env:TERM=dumb',
            ],
            // WT_SESSION set → truecolor
            'wt_session returns truecolor' => [
                ['WT_SESSION' => '1'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:WT_SESSION',
            ],
            // GOOGLE_CLOUD_SHELL=true → truecolor
            'google_cloud_shell returns truecolor' => [
                ['GOOGLE_CLOUD_SHELL' => 'true'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:GOOGLE_CLOUD_SHELL',
            ],
            // CP-09: the grant is the exact string "true" — one-way tightening,
            // "false" and "1" no longer force (they were honored by the old
            // set-and-non-empty test). The fall-through answer pins the gate
            // closed, not just the absence of a grant.
            'google_cloud_shell false no longer forces (CP-09)' => [
                ['GOOGLE_CLOUD_SHELL' => 'false', 'TERM' => 'xterm'],
                DetectionChain::LEVEL_ANSI,
                'env:TERM=xterm',
            ],
            'google_cloud_shell 1 no longer forces (CP-09)' => [
                ['GOOGLE_CLOUD_SHELL' => '1', 'TERM' => 'xterm'],
                DetectionChain::LEVEL_ANSI,
                'env:TERM=xterm',
            ],
            // TMUX + screen* → ansi256
            'tmux with screen returns ansi256' => [
                ['TMUX' => '12345', 'TERM' => 'screen-256color'],
                DetectionChain::LEVEL_ANSI256,
                'env:TMUX|STY+TMUX',
            ],
            // CP-09 split caps: the screen leg runs BEFORE COLORTERM (an
            // inherited COLORTERM must not undo the cap — GNU screen never
            // forwards 24-bit), the tmux leg runs BEHIND it (modern tmux may
            // honestly forward truecolor). Twin pins document the split.
            'screen cap outranks inherited COLORTERM (CP-09)' => [
                ['TMUX' => '12345', 'TERM' => 'screen-256color', 'COLORTERM' => 'truecolor'],
                DetectionChain::LEVEL_ANSI256,
                'env:TMUX|STY+TMUX',
            ],
            'sty screen cap outranks inherited COLORTERM (CP-09)' => [
                ['STY' => '12345', 'TERM' => 'screen', 'COLORTERM' => 'truecolor'],
                DetectionChain::LEVEL_ANSI256,
                'env:TMUX|STY+STY',
            ],
            'tmux twin passes under COLORTERM (CP-09 split)' => [
                ['TMUX' => '12345', 'TERM' => 'tmux-256color', 'COLORTERM' => 'truecolor'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:COLORTERM=truecolor',
            ],
            'tmux with tmux returns ansi256' => [
                ['TMUX' => '12345', 'TERM' => 'tmux-256color'],
                DetectionChain::LEVEL_ANSI256,
                'env:TMUX|STY+TMUX',
            ],
            // STY + screen* → ansi256
            'sty with screen returns ansi256' => [
                ['STY' => '12345', 'TERM' => 'screen-256color'],
                DetectionChain::LEVEL_ANSI256,
                'env:TMUX|STY+STY',
            ],
            // TERM=xterm-kitty → ansi256
            'xterm-kitty returns ansi256' => [
                ['TERM' => 'xterm-kitty'],
                DetectionChain::LEVEL_ANSI256,
                'env:TERM=xterm-kitty',
            ],
            // TERM=xterm-ghostty → ansi256
            'xterm-ghostty returns ansi256' => [
                ['TERM' => 'xterm-ghostty'],
                DetectionChain::LEVEL_ANSI256,
                'env:TERM=xterm-ghostty',
            ],
            // TERM=*-256color → ansi256
            'xterm-256color returns ansi256' => [
                ['TERM' => 'xterm-256color'],
                DetectionChain::LEVEL_ANSI256,
                'env:TERM=xterm-256color',
            ],
            'screen-256color returns ansi256' => [
                ['TERM' => 'screen-256color'],
                DetectionChain::LEVEL_ANSI256,
                'env:TERM=screen-256color',
            ],
            // TERM=xterm* → ansi
            'xterm returns ansi' => [
                ['TERM' => 'xterm'],
                DetectionChain::LEVEL_ANSI,
                'env:TERM=xterm',
            ],
            'xterm-color returns ansi' => [
                ['TERM' => 'xterm-color'],
                DetectionChain::LEVEL_ANSI,
                'env:TERM=xterm-color',
            ],
            // TERM=screen* → ansi
            'screen returns ansi' => [
                ['TERM' => 'screen'],
                DetectionChain::LEVEL_ANSI,
                'env:TERM=screen',
            ],
            // TERM=tmux* → ansi
            'tmux returns ansi' => [
                ['TERM' => 'tmux'],
                DetectionChain::LEVEL_ANSI,
                'env:TERM=tmux',
            ],
            // Default (empty TERM) → the colorless floor (CP-10: an unknown
            // or absent TERM no longer claims 16-color support)
            'default floors to the colorless rung' => [
                ['TERM' => ''],
                DetectionChain::LEVEL_ASCII,
                'fallback:default',
            ],
            // CP-10 known-family set stays colored (the other half of the leg
            // widening that makes the floor safe): vt and linux were reaching
            // Ansi only through the old permissive default.
            'vt100 stays ansi on the known leg' => [
                ['TERM' => 'vt100'],
                DetectionChain::LEVEL_ANSI,
                'env:TERM=vt100',
            ],
            'linux console stays ansi on the known leg' => [
                ['TERM' => 'linux'],
                DetectionChain::LEVEL_ANSI,
                'env:TERM=linux',
            ],
            'unknown fictional term floors colorless' => [
                ['TERM' => 'fictional-term'],
                DetectionChain::LEVEL_ASCII,
                'fallback:default',
            ],
        ];
    }

    /**
     * @dataProvider detectionProvider
     * @covers \SugarCraft\ColorProfile\DetectionChain::detect
     */
    public function testDetect(array $env, string $expectedLevel, string $expectedSource): void
    {
        $chain = DetectionChain::detect($env);

        $this->assertSame($expectedLevel, $chain->level());
        $this->assertSame($expectedSource, $chain->source());
    }

    public function testTermIsPreserved(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm-256color']);

        $this->assertSame('xterm-256color', $chain->term());
    }

    public function testAllowsColorWhenColorIsAllowed(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm']);

        $this->assertTrue($chain->allowsColor());
    }

    public function testAllowsColorWhenNoColor(): void
    {
        $chain = DetectionChain::detect(['NO_COLOR' => '1']);

        $this->assertFalse($chain->allowsColor());
    }

    public function testToProfileTrueColor(): void
    {
        $chain = DetectionChain::detect(['CLICOLOR_FORCE' => '1']);

        $this->assertSame(Profile::TrueColor, $chain->toProfile());
    }

    public function testToProfileANSI256(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm-256color']);

        $this->assertSame(Profile::ANSI256, $chain->toProfile());
    }

    public function testToProfileANSI(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm']);

        $this->assertSame(Profile::ANSI, $chain->toProfile());
    }

    public function testToProfileAscii(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm']);
        // Force ascii level for testing
        $reflection = new \ReflectionClass($chain);
        $prop = $reflection->getProperty('level');
        $prop->setAccessible(true);
        $prop->setValue($chain, DetectionChain::LEVEL_ASCII);

        $this->assertSame(Profile::Ascii, $chain->toProfile());
    }

    public function testToProfileSuppressionAnswersAscii(): void
    {
        // CP-05 Shape B: NO_COLOR lands on the colorless Ascii rung, not NoTTY.
        $chain = DetectionChain::detect(['NO_COLOR' => '1']);

        $this->assertSame(Profile::Ascii, $chain->toProfile());
    }

    public function testToColorProfileTrueColor(): void
    {
        $chain = DetectionChain::detect(['CLICOLOR_FORCE' => '1']);

        $this->assertSame(ColorProfile::TrueColor, $chain->toColorProfile());
    }

    public function testToColorProfileANSI256(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm-256color']);

        $this->assertSame(ColorProfile::Ansi256, $chain->toColorProfile());
    }

    public function testToColorProfileANSI(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm']);

        $this->assertSame(ColorProfile::Ansi, $chain->toColorProfile());
    }

    public function testToColorProfileSuppressionAnswersAscii(): void
    {
        // CP-05 Shape B: the suppression rung maps to ColorProfile::Ascii.
        $chain = DetectionChain::detect(['NO_COLOR' => '1']);

        $this->assertSame(ColorProfile::Ascii, $chain->toColorProfile());
    }

    public function testToColorProfileAscii(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm']);
        $reflection = new \ReflectionClass($chain);
        $prop = $reflection->getProperty('level');
        $prop->setAccessible(true);
        $prop->setValue($chain, DetectionChain::LEVEL_ASCII);

        $this->assertSame(ColorProfile::Ascii, $chain->toColorProfile());
    }

    public function testTermExportsAtMostTheByteCeiling(): void
    {
        // term() is where the ambient value leaves the chain; the docs
        // attached a 128-byte bound to env interpolation, which never
        // covered this accessor. An absurd TERM now clamps at the stated
        // 256-byte ceiling instead of streaming out raw.
        $chain = DetectionChain::detect(['TERM' => str_repeat('q', 100000)]);
        $term = $chain->term();

        $this->assertSame(256, strlen($term));
        $this->assertSame(str_repeat('q', 256), $term);
    }

    public function testTermClampsOnACharacterBoundaryNeverMidSequence(): void
    {
        // PROC-007: the clamped TERM is exported as the child's environment
        // value. 'éa' units are 3 bytes with the 2-byte é first, so a raw
        // substr cut at 256 lands INSIDE é (lead byte C3 kept, tail A9
        // orphaned) and emits invalid UTF-8; mb_strcut steps back to the
        // character edge (255 bytes, eight full 85-unit rows ending on 'a').
        $chain = DetectionChain::detect(['TERM' => str_repeat('éa', 86)]);
        $term = $chain->term();

        $this->assertLessThanOrEqual(256, strlen($term));
        $this->assertSame(255, strlen($term), 'the boundary cut lands one byte under the ceiling here');
        $this->assertSame(1, preg_match('//u', $term), 'the exported TERM must be valid UTF-8');
        $this->assertSame(str_repeat('éa', 85), $term);
        $this->assertNotSame(\substr(str_repeat('éa', 86), 0, 256), $term, 'the byte cut must NOT be the answer');
    }

    public function testShortTermExposesByteIdentical(): void
    {
        $chain = DetectionChain::detect(['TERM' => 'xterm-256color']);

        $this->assertSame('xterm-256color', $chain->term(), 'the clamp must not touch ordinary values');
    }

    /**
     * CP-08 (XPLAT-02) roster: CLICOLOR_FORCE honors the termenv/chafa
     * grammar — set, non-empty, not exactly '0'. TERM=dumb is planted as the
     * visible next rung so a non-forcing spelling provably falls through the
     * force arm instead of resting on it.
     *
     * @dataProvider forceSpellingProvider
     */
    public function testEveryForceSpellingMintsTheChainAnswer(string $spelling): void
    {
        $chain = DetectionChain::detect(['CLICOLOR_FORCE' => $spelling, 'TERM' => 'dumb']);

        $this->assertSame(DetectionChain::LEVEL_TRUECOLOR, $chain->level(), $spelling);
        $this->assertSame('env:CLICOLOR_FORCE', $chain->source(), $spelling);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forceSpellingProvider(): array
    {
        $cases = [];
        foreach (['1', 'true', 'TRUE', 'yes', 'on', '2', '3'] as $spelling) {
            $cases[$spelling] = [$spelling];
        }

        return $cases;
    }

    /**
     * @dataProvider falsyForceSpellingProvider
     */
    public function testTheFalsySpellingsFallThroughTheForceArm(string $spelling): void
    {
        $chain = DetectionChain::detect(['CLICOLOR_FORCE' => $spelling, 'TERM' => 'dumb']);

        $this->assertSame(DetectionChain::LEVEL_ASCII, $chain->level(), "[$spelling]");
        $this->assertSame('env:TERM=dumb', $chain->source(), "[$spelling]");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function falsyForceSpellingProvider(): array
    {
        // The exact pair: unset is falsy through Env::value's null and rides
        // the same helper branch; '' is the exported-empty spelling.
        return ['empty' => [''], 'zero' => ['0']];
    }

    public function testIsForcedNamesOnlyTheForceLegs(): void
    {
        // CP-07 seam: the gate's yield decision reads the SOURCE, never the
        // depth — the falsy floor of a force leg is still force, while every
        // capability read and suppression is not.
        $this->assertTrue(DetectionChain::detect(['CLICOLOR_FORCE' => '1'])->isForced());
        $this->assertTrue(DetectionChain::detect(['FORCE_COLOR' => '3'])->isForced());
        $this->assertTrue(DetectionChain::detect(['FORCE_COLOR' => '0'])->isForced());

        $this->assertFalse(DetectionChain::detect(['NO_COLOR' => '1'])->isForced(), 'suppression is not force');
        $this->assertFalse(DetectionChain::detect(['CLICOLOR' => '0'])->isForced(), 'suppression is not force');
        $this->assertFalse(DetectionChain::detect(['TERM' => 'dumb'])->isForced(), 'suppression is not force');
        $this->assertFalse(DetectionChain::detect(['COLORTERM' => 'truecolor'])->isForced(), 'capability read is not force');
        $this->assertFalse(DetectionChain::detect([])->isForced(), 'the floor is not force');
    }

    public function testUserOptedOutNamesTheSuppressionLegsAndNothingElse(): void
    {
        // CP-20 (sugar-colorprofile-023): opt-out vs hardware absence is
        // PROVENANCE, not level — post-CP-05 both colorless stories answer
        // a colorless rung, so only the ladder's tagged source can tell
        // them apart. The door reads that source; a leg that never FIRED
        // (outranked, or not a suppression at all) reads false.
        $this->assertTrue(DetectionChain::userOptedOut(['NO_COLOR' => '1']));
        $this->assertTrue(DetectionChain::userOptedOut(['NO_COLOR' => ' ']), 'presence-and-not-empty is the NO_COLOR standard reading');
        $this->assertTrue(DetectionChain::userOptedOut(['CLICOLOR' => '0']));
        $this->assertTrue(DetectionChain::userOptedOut(['TERM' => 'dumb']));
        $this->assertTrue(DetectionChain::userOptedOut(['TERM' => 'DUMB']), 'the term leg is case-insensitive and the source tag is the normalized spelling');

        $this->assertFalse(DetectionChain::userOptedOut(['TERM' => 'xterm']), 'a capability read is not an opt-out');
        $this->assertFalse(DetectionChain::userOptedOut(['TERM' => '']), 'the unknown-TERM floor (fallback:default) is not a suppression leg');
        $this->assertFalse(DetectionChain::userOptedOut(['CLICOLOR' => '1']), 'CLICOLOR=1 is a deliberate no-op (CP-09e), not a leg');
        $this->assertFalse(
            DetectionChain::userOptedOut(['NO_COLOR' => '1', 'CLICOLOR_FORCE' => '1']),
            'the force leg outranks: the suppression never fired the answer',
        );
        $this->assertFalse(
            DetectionChain::userOptedOut(['NO_COLOR' => '1', 'FORCE_COLOR' => '0']),
            'a pinned floor is the operator choosing the floor, not the NO_COLOR leg firing',
        );
    }

    public function testUserOptedOutReadsTheAmbientLayerWhenTheMapIsEmpty(): void
    {
        // The [] = ambient half of the resolve rule (CP-20): the door parses
        // through Env, so the same putenv-visible suppression the ladder
        // sees is what userOptedOut() names. The force legs are neutralized
        // first — on a hermetic map they cannot interfere, on the ambient
        // one a stray CI FORCE_COLOR would outrank the leg under test.
        $controlled = ['NO_COLOR', 'TERM', 'CLICOLOR_FORCE', 'FORCE_COLOR'];
        $saved = [];
        foreach ($controlled as $key) {
            $saved[$key] = getenv($key);
        }
        try {
            putenv('CLICOLOR_FORCE');
            unset($_ENV['CLICOLOR_FORCE']);
            putenv('FORCE_COLOR');
            unset($_ENV['FORCE_COLOR']);
            putenv('TERM=xterm');
            $_ENV['TERM'] = 'xterm';

            putenv('NO_COLOR=1');
            $_ENV['NO_COLOR'] = '1';
            $this->assertTrue(DetectionChain::userOptedOut(), 'ambient NO_COLOR fires the leg');

            putenv('NO_COLOR');
            unset($_ENV['NO_COLOR']);
            $this->assertFalse(DetectionChain::userOptedOut(), 'without a suppression leg the same TERM answers a capability read');
        } finally {
            foreach ($controlled as $key) {
                if ($saved[$key] === false) {
                    putenv($key);
                    unset($_ENV[$key]);
                } else {
                    putenv("{$key}={$saved[$key]}");
                    $_ENV[$key] = $saved[$key];
                }
            }
        }
    }
}

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
            // NO_COLOR set → none
            'no_color returns none' => [
                ['NO_COLOR' => '1'],
                DetectionChain::LEVEL_NONE,
                'env:NO_COLOR',
            ],
            // no-color.org: NO_COLOR set to the EMPTY string means UNSET —
            // the chain falls through to the TERM rungs (flipped with the
            // MT2 cure; the old pin treated "" as "set").
            'no_color empty string is unset' => [
                ['NO_COLOR' => '', 'TERM' => 'unknownterm'],
                DetectionChain::LEVEL_ANSI,
                'fallback:default',
            ],
            // CLICOLOR=0 → none
            'clicolor_0 returns none' => [
                ['CLICOLOR' => '0'],
                DetectionChain::LEVEL_NONE,
                'env:CLICOLOR=0',
            ],
            // COLORTERM=24bit|truecolor|yes → truecolor
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
            'colorterm_yes returns truecolor' => [
                ['COLORTERM' => 'yes', 'TERM' => 'xterm'],
                DetectionChain::LEVEL_TRUECOLOR,
                'env:COLORTERM=yes',
            ],
            // TERM=dumb → none
            'term_dumb returns none' => [
                ['TERM' => 'dumb'],
                DetectionChain::LEVEL_NONE,
                'env:TERM=dumb',
            ],
            // TERM=dumb outranks COLORTERM (unified dumb-first ladder)
            'term_dumb vetoes colorterm' => [
                ['COLORTERM' => 'truecolor', 'TERM' => 'dumb'],
                DetectionChain::LEVEL_NONE,
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
            // TMUX + screen* → ansi256
            'tmux with screen returns ansi256' => [
                ['TMUX' => '12345', 'TERM' => 'screen-256color'],
                DetectionChain::LEVEL_ANSI256,
                'env:TMUX|STY+TMUX',
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
            // Default (empty TERM) → ansi
            'default returns ansi' => [
                ['TERM' => ''],
                DetectionChain::LEVEL_ANSI,
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

    public function testToProfileNoTTY(): void
    {
        $chain = DetectionChain::detect(['NO_COLOR' => '1']);

        $this->assertSame(Profile::NoTTY, $chain->toProfile());
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

    public function testToColorProfileNoTTY(): void
    {
        $chain = DetectionChain::detect(['NO_COLOR' => '1']);

        $this->assertSame(ColorProfile::NoTTY, $chain->toColorProfile());
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
}

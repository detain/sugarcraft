<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;

/**
 * CP-22: detect() delegates to sugar-colorprofile's chain when installed.
 * Without it there is no local ladder (P1-10a deleted it) and a terminal
 * answers Ascii. sugar-core cannot require colorprofile (it requires us), so
 * each leg skips when the other is live.
 */
final class ColorProfileDelegationTest extends TestCase
{
    private static function chainPresent(): bool
    {
        return class_exists(\SugarCraft\ColorProfile\DetectionChain::class);
    }

    public function testColourIsOffWithoutColorprofile(): void
    {
        if (self::chainPresent()) {
            $this->markTestSkipped('sugar-colorprofile installed; colour-off leg unreachable');
        }
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detect(['FORCE_COLOR' => '1', 'TERM' => 'xterm']));
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detect(['TERM' => 'dumb']));
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detect(['CI' => 'true']));
        $pipe = fopen('php://memory', 'r');
        try {
            $this->assertSame(ColorProfile::Ascii, ColorProfile::detect(['FORCE_COLOR' => '1', 'TERM' => 'xterm'], $pipe), 'a force variable passes the TTY gate');
            $this->assertSame(ColorProfile::NoTty, ColorProfile::detect(['TERM' => 'xterm'], $pipe), 'an unforced pipe is still NoTty');
        } finally {
            fclose($pipe);
        }
    }

    public function testDelegatesToChainWhenInstalled(): void
    {
        if (!self::chainPresent()) {
            $this->markTestSkipped('sugar-colorprofile not installed; delegation leg unreachable');
        }
        foreach ([
            ['TERM' => 'xterm-256color'],
            ['TERM' => 'xterm', 'FORCE_COLOR' => '1'],
            ['TERM' => 'dumb'],
            ['NO_COLOR' => '1', 'TERM' => 'xterm'],
            ['COLORTERM' => 'truecolor', 'TERM' => 'xterm'],
            ['TERM' => ''],
        ] as $env) {
            $this->assertSame(
                \SugarCraft\ColorProfile\DetectionChain::detect($env)->toColorProfile()->toCore(),
                ColorProfile::detect($env),
                json_encode($env),
            );
        }
    }

    public function testEmptyEnvArrayStaysHermetic(): void
    {
        if (!self::chainPresent()) {
            $this->markTestSkipped('delegation leg only');
        }
        putenv('COLORTERM=truecolor');
        try {
            $this->assertSame(ColorProfile::Ascii, ColorProfile::detect([]));
        } finally {
            putenv('COLORTERM');
        }
    }

    public function testNonTtyGateStaysLocalWhenDelegating(): void
    {
        $tmp = fopen('php://memory', 'r+');
        try {
            $this->assertSame(ColorProfile::NoTty, ColorProfile::detect(['TERM' => 'xterm-256color'], $tmp));
        } finally {
            fclose($tmp);
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;

/**
 * CP-22: detect() delegates to sugar-colorprofile's chain when installed and
 * falls back to the local legacy ladder when not. sugar-core cannot require
 * colorprofile (it requires us), so each leg skips when the other is live.
 */
final class ColorProfileDelegationTest extends TestCase
{
    private static function chainPresent(): bool
    {
        return class_exists(\SugarCraft\ColorProfile\DetectionChain::class);
    }

    public function testLegacyLadderWhenColorprofileAbsent(): void
    {
        if (self::chainPresent()) {
            $this->markTestSkipped('sugar-colorprofile installed; legacy leg unreachable');
        }
        $this->assertSame(ColorProfile::TrueColor, ColorProfile::detect(['FORCE_COLOR' => '1', 'TERM' => 'xterm']));
        $this->assertSame(ColorProfile::NoTty, ColorProfile::detect(['TERM' => 'dumb']));
        $this->assertSame(ColorProfile::Ansi, ColorProfile::detect(['CI' => 'true']));
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

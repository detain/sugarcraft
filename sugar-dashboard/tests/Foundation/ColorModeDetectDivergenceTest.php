<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * The ColorMode chain and ColorProfile::detect() are NOT the same answer,
 * and finding sugar-dashboard-110 says the README may not pretend they are.
 * These pins are the falsifiable record of the documented divergences. Since
 * P1-10a, ColorProfile::detect() answers through sugar-colorprofile's chain
 * when it is installed, so NO_COLOR=0 and the force-over-NO_COLOR order now
 * agree; what still differs is pinned below.
 * — if either side's behavior changes, this test is where the README's
 * Color Environment paragraph needs its truth re-derived from.
 */
final class ColorModeDetectDivergenceTest extends TestCase
{
    /**
     * @param array<string, string> $vars environment the chain will read
     * @param array<string, string> $env  the same roster, hand-fed to detect()
     */
    private function pair(array $vars, array $env): array
    {
        $saved = [];
        foreach ($vars as $key => $value) {
            $saved[$key] = getenv($key);
            putenv("{$key}={$value}");
        }
        try {
            ColorMode::resetForTesting();
            $chain = ColorMode::profile();
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$value}");
                }
            }
            ColorMode::resetForTesting();
        }
        // stdout stays null: the divergence probes are about env semantics,
        // not about whether PHPUnit's own stdout is a tty.
        return [$chain, ColorProfile::detect($env, null)];
    }

    public function testNoColorZeroIsHonoredByBothAuthorities(): void
    {
        // no-color.org: NO_COLOR present with ANY value disables color. Both
        // answers agree now that detect() delegates to the chain (P1-10a).
        [$chain, $detected] = $this->pair(
            ['TERM' => 'xterm-256color', 'NO_COLOR' => '0'],
            ['TERM' => 'xterm-256color', 'NO_COLOR' => '0'],
        );
        $this->assertSame(ColorProfile::Ascii, $chain);
        $this->assertSame(ColorProfile::Ascii, $detected);
    }

    public function testForceOutranksNoColorInBothButTheLevelDiffers(): void
    {
        // Both let the force variable win over NO_COLOR. The level differs:
        // this funnel maps any truthy force to TrueColor, while the chain's
        // FORCE_COLOR=1 is its Ansi floor (level 1).
        [$chain, $detected] = $this->pair(
            ['TERM' => 'xterm-256color', 'NO_COLOR' => '1', 'FORCE_COLOR' => '1'],
            ['TERM' => 'xterm-256color', 'NO_COLOR' => '1', 'FORCE_COLOR' => '1'],
        );
        $this->assertSame(ColorProfile::TrueColor, $chain);
        $this->assertSame(ColorProfile::Ansi, $detected);
    }

    public function testTheChainNeverNegotiatesSubTiers(): void
    {
        // A 256-color host asks the chain and gets the TrueColor floor;
        // detect() walks its ladder and answers Ansi256.
        [$chain, $detected] = $this->pair(
            ['TERM' => 'xterm-256color'],
            ['TERM' => 'xterm-256color'],
        );
        $this->assertSame(ColorProfile::TrueColor, $chain);
        $this->assertSame(ColorProfile::Ansi256, $detected);
    }
}

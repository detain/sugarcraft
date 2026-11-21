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

    /**
     * The no-argument detect() snapshots the process, so the keys the chain
     * reads from that snapshot must be in the roster. Chain-independent: it
     * inspects the roster directly, so it runs without sugar-colorprofile.
     */
    public function testDefaultEnvCarriesTheMultiplexerAndCloudShellKeys(): void
    {
        $keys = ['TMUX', 'STY', 'GOOGLE_CLOUD_SHELL'];
        $restore = [];
        foreach ($keys as $key) {
            $restore[$key] = getenv($key);
            putenv($key . '=sc-probe');
        }
        try {
            $method = new \ReflectionMethod(ColorProfile::class, 'defaultEnv');
            $collected = $method->invoke(null);
            foreach ($keys as $key) {
                $this->assertSame('sc-probe', $collected[$key] ?? null, "{$key} must reach the chain");
            }
        } finally {
            foreach ($restore as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }

    /** TMUX with a screen TERM is a chain rung, so a no-argument detect() must see it. */
    public function testNoArgumentDetectMatchesTheChainOnAMultiplexerEnvironment(): void
    {
        if (!self::chainPresent()) {
            $this->markTestSkipped('sugar-colorprofile not installed; parity leg unreachable');
        }
        $restore = [];
        foreach (['TMUX' => '/tmp/tmux-1000/default', 'TERM' => 'screen', 'COLORTERM' => false] as $key => $value) {
            $restore[$key] = getenv($key);
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        try {
            $this->assertSame(
                \SugarCraft\ColorProfile\DetectionChain::detect()->toColorProfile()->toCore(),
                ColorProfile::detect(),
            );
        } finally {
            foreach ($restore as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;

/**
 * The environment matrix for {@see ColorProfile::fromEnvironment()}.
 *
 * Rows in {@see sharedMatrix()} give the same answer under the local fallback
 * ladder and under sugar-colorprofile's DetectionChain, so they run either way.
 * Rows where the two ladders disagree on purpose (the CoreForkDivergencePinTest
 * table) run only on the ladder that answers them, and each skips when the
 * other ladder is live.
 */
final class ColorProfileFromEnvironmentTest extends TestCase
{
    private static function chainPresent(): bool
    {
        return class_exists(\SugarCraft\ColorProfile\DetectionChain::class);
    }

    /** @return iterable<string, array{array<string,string>, bool, ?bool, ColorProfile}> */
    public static function sharedMatrix(): iterable
    {
        yield 'empty env is hermetic and colourless' => [[], true, null, ColorProfile::Ascii];
        yield 'xterm-256color is 256' => [['TERM' => 'xterm-256color'], true, null, ColorProfile::Ansi256];
        yield 'plain xterm is ANSI' => [['TERM' => 'xterm'], true, null, ColorProfile::Ansi];
        yield 'COLORTERM truecolor' => [['TERM' => 'xterm', 'COLORTERM' => 'truecolor'], true, null, ColorProfile::TrueColor];
        yield 'COLORTERM 24bit' => [['TERM' => 'xterm', 'COLORTERM' => '24bit'], true, null, ColorProfile::TrueColor];
        yield 'NO_COLOR is ascii on a tty' => [['TERM' => 'xterm', 'NO_COLOR' => '1'], true, null, ColorProfile::Ascii];
        yield 'CLICOLOR=0 is ascii on a tty' => [['TERM' => 'xterm', 'CLICOLOR' => '0'], true, null, ColorProfile::Ascii];
        yield 'CLICOLOR_FORCE lifts dumb' => [['TERM' => 'dumb', 'CLICOLOR_FORCE' => '1'], true, null, ColorProfile::TrueColor];
        yield 'CLICOLOR_FORCE on a pipe' => [['TERM' => 'xterm', 'CLICOLOR_FORCE' => '1'], false, null, ColorProfile::TrueColor];
        yield 'pipe without force is NoTty' => [['TERM' => 'xterm-256color'], false, null, ColorProfile::NoTty];
        yield 'NO_COLOR on a pipe is NoTty' => [['TERM' => 'xterm', 'NO_COLOR' => '1'], false, null, ColorProfile::NoTty];
        yield 'override true forces TrueColor' => [[], true, true, ColorProfile::TrueColor];
        yield 'override true forces TrueColor on a pipe' => [['TERM' => 'dumb'], false, true, ColorProfile::TrueColor];
        yield 'override true beats NO_COLOR' => [['TERM' => 'xterm', 'NO_COLOR' => '1'], true, true, ColorProfile::TrueColor];
        yield 'override false is ascii on a tty' => [['TERM' => 'xterm-256color'], true, false, ColorProfile::Ascii];
        yield 'override false is NoTty on a pipe' => [['TERM' => 'xterm-256color'], false, false, ColorProfile::NoTty];
        yield 'override false beats CLICOLOR_FORCE' => [['CLICOLOR_FORCE' => '1', 'TERM' => 'xterm'], true, false, ColorProfile::Ascii];
    }

    #[DataProvider('sharedMatrix')]
    public function testSharedMatrix(array $env, bool $isTty, ?bool $override, ColorProfile $expected): void
    {
        $this->assertSame($expected, ColorProfile::fromEnvironment($env, $isTty, $override));
    }

    /** Rows where the fallback ladder answers `NoTty`, and the chain answers `Ascii`. */
    public function testDumbTermAnswersAsTheLiveLadderSays(): void
    {
        $expected = self::chainPresent() ? ColorProfile::Ascii : ColorProfile::NoTty;
        $this->assertSame($expected, ColorProfile::fromEnvironment(['TERM' => 'dumb'], true));
    }

    /** Fallback-only: the legacy ladder reads CI as ANSI; the chain has no CI rung. */
    public function testCiEnvironmentIsAnsiOnTheFallbackLadder(): void
    {
        if (self::chainPresent()) {
            $this->markTestSkipped('sugar-colorprofile installed; the CI rung belongs to the fallback ladder');
        }
        $this->assertSame(ColorProfile::Ansi, ColorProfile::fromEnvironment(['CI' => 'true', 'TERM' => ''], true));
    }

    /** Fallback-only: FORCE_COLOR=1 is TrueColor here, Ansi under the chain's level grammar. */
    public function testForceColorOneOnTheFallbackLadder(): void
    {
        if (self::chainPresent()) {
            $this->markTestSkipped('sugar-colorprofile installed; FORCE_COLOR levels follow the chain');
        }
        $this->assertSame(ColorProfile::TrueColor, ColorProfile::fromEnvironment(['FORCE_COLOR' => '1', 'TERM' => 'xterm'], true));
    }

    /** The answer must not depend on the process environment. */
    public function testFromEnvironmentNeverReadsTheAmbientEnvironment(): void
    {
        $keys = ['NO_COLOR', 'COLORTERM', 'CLICOLOR_FORCE', 'TERM'];
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = getenv($key);
        }
        putenv('NO_COLOR=1');
        putenv('COLORTERM=truecolor');
        putenv('CLICOLOR_FORCE=1');
        putenv('TERM=dumb');
        try {
            $this->assertSame(ColorProfile::Ascii, ColorProfile::fromEnvironment([], true));
            $this->assertSame(ColorProfile::NoTty, ColorProfile::fromEnvironment([], false));
            $this->assertSame(ColorProfile::Ansi, ColorProfile::fromEnvironment(['TERM' => 'xterm'], true));
        } finally {
            foreach ($keys as $key) {
                if ($before[$key] === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $before[$key]);
                }
            }
        }
    }

    public function testDetectIsFromEnvironmentFedWithTheProcessEnvironment(): void
    {
        $env = ['TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor'];
        $this->assertSame(ColorProfile::fromEnvironment($env, true), ColorProfile::detect($env));
        $this->assertSame(ColorProfile::fromEnvironment($env, false), ColorProfile::detect($env, fopen('php://memory', 'r')));
    }

    public function testNullStreamSkipsTheTtyGate(): void
    {
        $this->assertSame(ColorProfile::Ansi256, ColorProfile::detect(['TERM' => 'xterm-256color'], null));
    }
}

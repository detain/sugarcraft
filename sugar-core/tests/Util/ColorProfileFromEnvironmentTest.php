<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;

/**
 * The environment matrix for {@see ColorProfile::fromEnvironment()}.
 *
 * Two kinds of row. The non-terminal and override rows are decided here, with
 * no environment negotiation, so they hold with or without sugar-colorprofile.
 * Every other row is negotiated: with the chain installed the answer is the
 * chain's, and without it (P1-10a deleted the local ladder) a terminal answers
 * `Ascii`. {@see terminalAnswer()} states that expectation for both states.
 */
final class ColorProfileFromEnvironmentTest extends TestCase
{
    private static function chainPresent(): bool
    {
        return class_exists(\SugarCraft\ColorProfile\DetectionChain::class);
    }

    /**
     * The answer for a terminal with no override: the chain's when installed,
     * colour off when not. An empty array reaches the chain as TERM='' so the
     * chain never reads the ambient environment.
     *
     * @param array<string,string> $env
     */
    private static function terminalAnswer(array $env): ColorProfile
    {
        if (!self::chainPresent()) {
            return ColorProfile::Ascii;
        }
        return \SugarCraft\ColorProfile\DetectionChain::detect($env === [] ? ['TERM' => ''] : $env)
            ->toColorProfile()
            ->toCore();
    }

    /** @return iterable<string, array{array<string,string>, bool, ?bool, ColorProfile}> */
    public static function sharedMatrix(): iterable
    {
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

    /**
     * @return iterable<string, array{array<string,string>, bool}>
     */
    public static function negotiatedMatrix(): iterable
    {
        yield 'empty env is hermetic' => [[], true];
        yield 'xterm-256color' => [['TERM' => 'xterm-256color'], true];
        yield 'plain xterm' => [['TERM' => 'xterm'], true];
        yield 'COLORTERM truecolor' => [['TERM' => 'xterm', 'COLORTERM' => 'truecolor'], true];
        yield 'COLORTERM 24bit' => [['TERM' => 'xterm', 'COLORTERM' => '24bit'], true];
        yield 'NO_COLOR on a tty' => [['TERM' => 'xterm', 'NO_COLOR' => '1'], true];
        yield 'CLICOLOR=0 on a tty' => [['TERM' => 'xterm', 'CLICOLOR' => '0'], true];
        yield 'CLICOLOR_FORCE lifts dumb' => [['TERM' => 'dumb', 'CLICOLOR_FORCE' => '1'], true];
        yield 'CLICOLOR_FORCE on a pipe' => [['TERM' => 'xterm', 'CLICOLOR_FORCE' => '1'], false];
        yield 'dumb terminal' => [['TERM' => 'dumb'], true];
        yield 'FORCE_COLOR=1 on a tty' => [['FORCE_COLOR' => '1', 'TERM' => 'xterm'], true];
        yield 'FORCE_COLOR=1 on a pipe' => [['FORCE_COLOR' => '1', 'TERM' => 'xterm'], false];
        yield 'CI runner' => [['CI' => 'true', 'TERM' => ''], true];
    }

    #[DataProvider('negotiatedMatrix')]
    public function testNegotiatedRowsAreTheChainsOrColourOff(array $env, bool $isTty): void
    {
        $this->assertSame(self::terminalAnswer($env), ColorProfile::fromEnvironment($env, $isTty));
    }

    /**
     * With no chain the ambient-free answer is colour off, whatever the rung:
     * the CI, FORCE_COLOR and dumb rows that the deleted ladder answered
     * differently all read Ascii here.
     */
    public function testWithoutTheChainNoNegotiationRungAnswers(): void
    {
        if (self::chainPresent()) {
            $this->markTestSkipped('sugar-colorprofile installed; the chain answers negotiated rows');
        }
        $this->assertSame(ColorProfile::Ascii, ColorProfile::fromEnvironment(['CI' => 'true', 'TERM' => ''], true));
        $this->assertSame(ColorProfile::Ascii, ColorProfile::fromEnvironment(['FORCE_COLOR' => '1', 'TERM' => 'xterm'], true));
        $this->assertSame(ColorProfile::Ascii, ColorProfile::fromEnvironment(['TERM' => 'dumb'], true));
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
            $this->assertSame(self::terminalAnswer([]), ColorProfile::fromEnvironment([], true));
            $this->assertSame(ColorProfile::NoTty, ColorProfile::fromEnvironment([], false));
            $this->assertSame(
                self::terminalAnswer(['TERM' => 'xterm']),
                ColorProfile::fromEnvironment(['TERM' => 'xterm'], true),
            );
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
        $env = ['TERM' => 'xterm-256color'];
        $this->assertSame(self::terminalAnswer($env), ColorProfile::detect($env, null));
        $this->assertNotSame(ColorProfile::NoTty, ColorProfile::detect($env, null));
    }

    /**
     * FORCE_COLOR=off|no|0|false answer colour-off in the chain (DetectionChain
     * step 2), so the pipe gate must not read them as a force: a pipe gets no
     * escape bytes at all. Chain-independent, the gate runs before any delegation.
     */
    public function testColourOffSpellingsDoNotForceAPipe(): void
    {
        foreach (['off', 'no', '0', 'false', 'OFF'] as $value) {
            $this->assertSame(
                ColorProfile::NoTty,
                ColorProfile::fromEnvironment(['FORCE_COLOR' => $value, 'TERM' => 'xterm'], false),
                "FORCE_COLOR={$value} on a pipe",
            );
        }
    }

    /** A truthy word or a level of 1+ still forces a pipe, as the chain reads it. */
    public function testForceSpellingsThatGrantColourStillForceAPipe(): void
    {
        foreach (['1', 'true', 'yes', 'on', '2'] as $value) {
            $this->assertNotSame(
                ColorProfile::NoTty,
                ColorProfile::fromEnvironment(['FORCE_COLOR' => $value, 'TERM' => 'xterm'], false),
                "FORCE_COLOR={$value} on a pipe",
            );
        }
    }
}

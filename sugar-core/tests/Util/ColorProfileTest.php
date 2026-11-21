<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\ColorProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorProfileTest extends TestCase
{
    private static function chainPresent(): bool
    {
        return class_exists(\SugarCraft\ColorProfile\DetectionChain::class);
    }

    /**
     * The answer for a terminal with no override: the chain's when
     * sugar-colorprofile is installed, colour off (`Ascii`) when not. The
     * negotiation rungs live in that chain now; this suite does not restate
     * them (P1-10a deleted the local ladder).
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

    /** @return iterable<string, array{array<string,string>}> */
    public static function negotiatedEnvironments(): iterable
    {
        yield 'dumb terminal' => [['TERM' => 'dumb']];
        yield 'dumb outranks colour hints' => [['TERM' => 'dumb', 'COLORTERM' => 'truecolor', 'TERM_PROGRAM' => 'iTerm.app']];
        yield 'dumb with NO_COLOR' => [['TERM' => 'dumb', 'NO_COLOR' => '1']];
        yield 'CLICOLOR_FORCE lifts dumb' => [['TERM' => 'dumb', 'CLICOLOR_FORCE' => '1']];
        yield 'FORCE_COLOR and NO_COLOR on dumb' => [['TERM' => 'dumb', 'FORCE_COLOR' => '1', 'NO_COLOR' => '1']];
        yield 'FORCE_COLOR beats CLICOLOR=0' => [['CLICOLOR' => '0', 'FORCE_COLOR' => '1', 'TERM' => 'xterm']];
        yield 'CLICOLOR=1 with 256 term' => [['CLICOLOR' => '1', 'TERM' => 'xterm-256color']];
        yield 'CLICOLOR=0 downgrades a truecolor hint' => [['CLICOLOR' => '0', 'TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor']];
        yield 'COLORTERM truecolor' => [['COLORTERM' => 'truecolor', 'TERM' => 'xterm']];
        yield 'TERM 256' => [['TERM' => 'xterm-256color']];
        yield 'plain xterm' => [['TERM' => 'xterm']];
        yield 'iTerm via TERM_PROGRAM' => [['TERM_PROGRAM' => 'iTerm.app', 'TERM' => 'xterm']];
        yield 'Apple Terminal' => [['TERM_PROGRAM' => 'Apple_Terminal', 'TERM' => 'xterm-256color']];
        yield 'Windows Terminal' => [['WT_SESSION' => 'abcd', 'TERM' => 'xterm']];
        yield 'CI' => [['CI' => 'true']];
        yield 'GitHub Actions' => [['GITHUB_ACTIONS' => 'true']];
        yield 'empty TERM' => [['TERM' => '']];
    }

    #[DataProvider('negotiatedEnvironments')]
    public function testNegotiatedEnvironmentIsTheChainsOrColourOff(array $env): void
    {
        $this->assertSame(self::terminalAnswer($env), ColorProfile::detect($env));
    }

    public function testNoColorOverridesEverything(): void
    {
        $this->assertSame(
            ColorProfile::Ascii,
            ColorProfile::detect(['NO_COLOR' => '1', 'COLORTERM' => 'truecolor', 'TERM' => 'xterm-256color']),
        );
    }

    public function testEmptyTermIsAscii(): void
    {
        $this->assertSame(ColorProfile::Ascii, ColorProfile::detect([]));
    }

    /** Upstream checks tty-ness before NO_COLOR: a pipe gets no escape bytes at all. */
    public function testNoColorOnNonTtyStreamIsNoTty(): void
    {
        $tmp = fopen('php://memory', 'r+');
        try {
            $this->assertSame(
                ColorProfile::NoTty,
                ColorProfile::detect(['NO_COLOR' => '1', 'TERM' => 'xterm-256color'], $tmp),
            );
        } finally {
            fclose($tmp);
        }
    }

    /**
     * With the chain installed a force variable outranks NO_COLOR (the declared
     * house order, sugar-core README). Without it there is no negotiation, so
     * the colour-off answer stands: forced output keeps decoration, loses colour.
     */
    public function testNoColorAndForceOnNonTtyStreamFollowTheDeclaredOrder(): void
    {
        $tmp = fopen('php://memory', 'r+');
        try {
            $this->assertSame(
                self::chainPresent() ? ColorProfile::Ansi : ColorProfile::Ascii,
                ColorProfile::detect(['NO_COLOR' => '1', 'FORCE_COLOR' => '1', 'TERM' => 'xterm'], $tmp),
            );
        } finally {
            fclose($tmp);
        }
    }

    /** A forced pipe goes to the negotiation, which decides its tier. */
    public function testForceColorOverridesNoTtyGate(): void
    {
        $tmp = fopen('php://memory', 'r+');
        try {
            $env = ['FORCE_COLOR' => '1', 'TERM' => 'xterm'];
            $this->assertSame(self::terminalAnswer($env), ColorProfile::detect($env, $tmp));
        } finally {
            fclose($tmp);
        }
    }

    public function testNonTtyStreamYieldsNoTty(): void
    {
        $tmp = fopen('php://memory', 'r+');
        try {
            $this->assertSame(
                ColorProfile::NoTty,
                ColorProfile::detect(['TERM' => 'xterm-256color'], $tmp),
            );
        } finally {
            fclose($tmp);
        }
    }

    /**
     * CLICOLOR=0 is the BSD colour opt-out and yields to an explicit force
     * (unlike NO_COLOR, which outranks it). Colour off is checked here with no
     * chain; the force and enable rows are negotiated.
     */
    public function testClicolorZeroDisablesColour(): void
    {
        $this->assertSame(
            ColorProfile::Ascii,
            ColorProfile::detect(['CLICOLOR' => '0', 'TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor']),
            'CLICOLOR=0 downgrades even a truecolor hint',
        );
    }

    /**
     * The rung above passes an explicit env array. This one drives the
     * no-argument default path, which reads getenv() through defaultEnv():
     * if the roster omits CLICOLOR the documented `CLICOLOR=0 → Ascii`
     * rung silently never fires for real callers.
     */
    public function testClicolorRungFiresOnTheDefaultEnvPath(): void
    {
        $keys = [
            'NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR',
            'TERM', 'COLORTERM', 'TERM_PROGRAM', 'WT_SESSION',
            'CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE',
        ];
        $saved = [];
        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $saved[$k] = $v;
            }
            putenv($k);
        }
        try {
            putenv('TERM=xterm-256color');
            putenv('CLICOLOR=0');
            $this->assertSame(
                ColorProfile::Ascii,
                ColorProfile::detect(),
                'CLICOLOR=0 reaches the Ascii rung via defaultEnv()',
            );
            putenv('CLICOLOR=1');
            $this->assertSame(
                self::terminalAnswer(['CLICOLOR' => '1', 'TERM' => 'xterm-256color']),
                ColorProfile::detect(),
                'CLICOLOR=1 reaches the negotiation via defaultEnv()',
            );
        } finally {
            foreach ($keys as $k) {
                if (array_key_exists($k, $saved)) {
                    putenv($k . '=' . $saved[$k]);
                } else {
                    putenv($k);
                }
            }
        }
    }

    public function testCapabilityHelpers(): void
    {
        $this->assertTrue(ColorProfile::TrueColor->supportsTrueColor());
        $this->assertTrue(ColorProfile::TrueColor->supports256());
        $this->assertTrue(ColorProfile::TrueColor->supportsAnsi());

        $this->assertFalse(ColorProfile::Ansi->supportsTrueColor());
        $this->assertFalse(ColorProfile::Ansi->supports256());
        $this->assertTrue(ColorProfile::Ansi->supportsAnsi());

        $this->assertFalse(ColorProfile::Ascii->supportsAnsi());
    }
}

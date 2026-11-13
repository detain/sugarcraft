<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\ScriptUi\Log\LogLevel;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use SugarCraft\ScriptUi\Style\StyleBuilder;
use SugarCraft\Style\Style;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The colour contract (review CC4 + M22): before this lane the README
 * promised the NO_COLOR / CLICOLOR / FORCE_COLOR family but no render
 * path consulted it — piped `style --bold hi` with NO_COLOR=1 still
 * emitted TrueColor escapes, and Style::setDefaultProfile() had zero
 * callers in the fleet. ColourGate now resolves the family once per
 * command (Application::doRunCommand) and pins the ambient sugar-style
 * profile; StyleBuilder::fromFlags clamps so a parsed palette index
 * cannot re-widen past the cap.
 */
final class ColourGateTest extends TestCase
{
    private const GATED_ENVS = [
        'NO_COLOR', 'FORCE_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'TERM',
        'COLORTERM', 'TERM_PROGRAM', 'WT_SESSION', 'CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'BUILDKITE',
        'CANDY_SCRIPTUI_NO_DECORATION',
    ];

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    private ?ColorProfile $savedAmbient = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAmbient = Style::defaultProfile();
        foreach (self::GATED_ENVS as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::GATED_ENVS as $name) {
            $original = $this->savedEnv[$name];
            if ($original === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $original);
            }
        }
        Style::setDefaultProfile($this->savedAmbient);
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:array<string,string>,1:ColorProfile}>
     */
    public static function ladderProvider(): array
    {
        return [
            // scriptui-36 re-pin (DISCLOSED): unset TERM now belongs to the
            // dumb-equivalent class, so legs that exercise the COLOUR family
            // must declare a real terminal to keep their rich-tier baseline.
            'default unrestricted'    => [['TERM' => 'xterm', 'COLORTERM' => 'truecolor'], ColorProfile::TrueColor],
            // scriptui-35: the TERM family narrows the tier when nothing else decides.
            'TERM xterm is 16'        => [['TERM' => 'xterm'], ColorProfile::Ansi],
            'TERM screen is 16'       => [['TERM' => 'screen'], ColorProfile::Ansi],
            'TERM screen-256color'    => [['TERM' => 'screen-256color'], ColorProfile::Ansi256],
            'TERM xterm-256color'     => [['TERM' => 'xterm-256color'], ColorProfile::Ansi256],
            'TERM vt100 has no colour' => [['TERM' => 'vt100'], ColorProfile::Ascii],
            'TERM linux console'      => [['TERM' => 'linux'], ColorProfile::Ansi],
            'COLORTERM wins over TERM' => [['TERM' => 'screen', 'COLORTERM' => '24bit'], ColorProfile::TrueColor],
            'NO_DECORATION is plain'  => [['TERM' => 'xterm-256color', 'CANDY_SCRIPTUI_NO_DECORATION' => '1'], ColorProfile::NoTty],
            'NO_DECORATION falsy'     => [['TERM' => 'xterm', 'CANDY_SCRIPTUI_NO_DECORATION' => '0'], ColorProfile::Ansi],
            'force beats NO_DECORATION' => [['CANDY_SCRIPTUI_NO_DECORATION' => '1', 'FORCE_COLOR' => '2'], ColorProfile::Ansi256],
            'NO_COLOR truthy'         => [['NO_COLOR' => '1'], ColorProfile::Ascii],
            'NO_COLOR any value'      => [['NO_COLOR' => 'blue'], ColorProfile::Ascii],
            'NO_COLOR empty keeps'    => [['TERM' => 'xterm', 'COLORTERM' => 'truecolor', 'NO_COLOR' => ''], ColorProfile::TrueColor],
            // no-color.org: ANY non-empty value disables colour (scriptui-35).
            'NO_COLOR zero disables'  => [['TERM' => 'xterm', 'COLORTERM' => 'truecolor', 'NO_COLOR' => '0'], ColorProfile::Ascii],
            'NO_COLOR false disables' => [['TERM' => 'xterm', 'COLORTERM' => 'truecolor', 'NO_COLOR' => 'false'], ColorProfile::Ascii],
            'empty TERM is plain too' => [['TERM' => ''], ColorProfile::NoTty],
            'FORCE_COLOR 16 tier'     => [['FORCE_COLOR' => '1'], ColorProfile::Ansi],
            'FORCE_COLOR 256 tier'    => [['FORCE_COLOR' => '2'], ColorProfile::Ansi256],
            'FORCE_COLOR truecolor'   => [['FORCE_COLOR' => '3'], ColorProfile::TrueColor],
            'FORCE_COLOR generic'     => [['FORCE_COLOR' => 'yes'], ColorProfile::TrueColor],
            'force beats NO_COLOR'    => [['FORCE_COLOR' => '1', 'NO_COLOR' => '1'], ColorProfile::Ansi],
            'falsy force defers'      => [['FORCE_COLOR' => '0', 'NO_COLOR' => '1'], ColorProfile::Ascii],
            'NO_DECORATION is stricter than NO_COLOR' => [['NO_COLOR' => '1', 'CANDY_SCRIPTUI_NO_DECORATION' => '1'], ColorProfile::NoTty],
            'CLICOLOR_FORCE beats'    => [['CLICOLOR_FORCE' => '1', 'NO_COLOR' => '1'], ColorProfile::TrueColor],
            'TERM dumb is plain'      => [['TERM' => 'dumb'], ColorProfile::NoTty],
            'NO_COLOR beats dumb'     => [['TERM' => 'dumb', 'NO_COLOR' => '1'], ColorProfile::Ascii],
        ];
    }

    /**
     * @param array<string,string> $assignments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ladderProvider')]
    public function testTheEnvLadderResolvesTheDocumentedTier(array $assignments, ColorProfile $expected): void
    {
        foreach ($assignments as $name => $value) {
            putenv($name . '=' . $value);
        }
        self::assertSame($expected, ColourGate::cap());
    }

    public function testClicolorZeroMutesOnlyWhenStdoutIsNotATty(): void
    {
        putenv('TERM=xterm');
        putenv('COLORTERM=truecolor');
        putenv('CLICOLOR=0');
        $piped = !(defined('STDOUT') && is_resource(STDOUT) && stream_isatty(STDOUT));
        self::assertSame(
            $piped ? ColorProfile::Ascii : ColorProfile::TrueColor,
            ColourGate::cap(),
            'CLICOLOR=0 must mute colour on pipes and stay silent on real terminals',
        );
    }

    public function testGlobalNoAnsiFlagBeatsEverything(): void
    {
        putenv('FORCE_COLOR=3');
        $input = new ArgvInput(['candy-scriptui', 'style', '--no-ansi', 'hi']);
        self::assertSame(ColorProfile::NoTty, ColourGate::cap($input));
    }

    public function testGlobalAnsiFlagOverridesNoColorAtTheSixteenColourTier(): void
    {
        putenv('NO_COLOR=1');
        $input = new ArgvInput(['candy-scriptui', 'style', '--ansi', 'hi']);
        self::assertSame(ColorProfile::Ansi, ColourGate::cap($input));
    }

    public function testInstallPinsTheAmbientProfileForFreshStyles(): void
    {
        putenv('NO_COLOR=1');
        self::assertSame(ColorProfile::Ascii, ColourGate::install());

        $rendered = Style::new()->foreground(Color::hex('#ff0000'))->bold()->render('hi');
        self::assertSame("\x1b[1mhi\x1b[0m", $rendered, 'decoration survives, colour is gone');
    }

    public function testFromFlagsCannotReWidenPastTheAmbientCap(): void
    {
        // A parsed 0-15 index claims Ansi on its own; under the ambient
        // Ascii cap (NO_COLOR) it must render colour-free, not leak 34m.
        putenv('NO_COLOR=1');
        ColourGate::install();

        $style = StyleBuilder::fromFlags(['foreground' => '4', 'bold' => true]);
        self::assertSame("\x1b[1mhi\x1b[0m", $style->render('hi'));
    }

    public function testForceColorTierRequantisesFlagColours(): void
    {
        putenv('FORCE_COLOR=2');
        ColourGate::install();

        $style = StyleBuilder::fromFlags(['foreground' => '#ff0000']);
        self::assertSame("\x1b[38;5;196mhi\x1b[0m", $style->render('hi'));
    }

    public function testNamedColoursParseToTheAnsiPalette(): void
    {
        [$color, $profile] = StyleBuilder::parseColorWithProfile('Red');
        self::assertEquals(Color::ansi(1), $color);
        self::assertSame(ColorProfile::Ansi, $profile);

        [$bright, $brightProfile] = StyleBuilder::parseColorWithProfile('BRIGHT-MAGENTA');
        self::assertEquals(Color::ansi(13), $bright);
        self::assertSame(ColorProfile::Ansi, $brightProfile);
    }

    public function testStyleCommandAcceptsNamedColoursEndToEnd(): void
    {
        $tester = new CommandTester(new \SugarCraft\ScriptUi\Command\StyleCommand());
        $tester->execute(['text' => ['hi'], '--foreground' => 'red']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("\x1b[31mhi\x1b[0m", $tester->getDisplay());
    }

    public function testUnknownColourRefusalListsTheAllowedSet(): void
    {
        try {
            StyleBuilder::parseColor('#ff00');
            self::fail('a 4-digit hex must be refused');
        } catch (\InvalidArgumentException $failure) {
            // hex path — specific message unchanged.
            self::assertStringContainsString('hex', $failure->getMessage());
        }

        try {
            StyleBuilder::parseColorWithProfile('pineapple');
            self::fail('an unknown colour name must be refused');
        } catch (\InvalidArgumentException $failure) {
            $message = $failure->getMessage();
            self::assertStringContainsString('pineapple', $message);
            // scriptui-56: the refusal voice is sentence-case + period, so
            // the Allowed token capitalises with it ('allowed:' was the
            // untranslated remnant this sweep translated per locale).
            self::assertStringContainsString('Allowed', $message);
            self::assertStringContainsString('black, red', $message);
            self::assertStringNotContainsString('{allowed}', $message, 'placeholder must interpolate');
            self::assertStringNotContainsString('{value}', $message);
        }
    }

    public function testLogBadgesRenderInsideTheAmbientCap(): void
    {
        putenv('NO_COLOR=1');
        ColourGate::install();
        // Badge colours are ANSI-16 (bright red 9); under Ascii only the
        // bold decoration may remain.
        self::assertSame("\x1b[1mERROR\x1b[0m", LogLevel::Error->style()->render('ERROR'));
    }

    public function testTheShippedBinStripsColourUnderNoColor(): void
    {
        $observe = self::runBinColourProbe(['style', '--foreground', 'red', 'hi'], ['NO_COLOR' => '1']);
        self::assertSame('hi', $observe[0], 'piped child with NO_COLOR=1 must print plain bytes');

        // scriptui-36: a clean-env child has NO TERM at all — now the
        // dumbest class — so the styled control must declare a terminal.
        $control = self::runBinColourProbe(['style', '--foreground', 'red', 'hi'], ['TERM' => 'xterm-256color']);
        self::assertSame("\x1b[31mhi\x1b[0m", $control[0], 'a declared terminal without NO_COLOR stays styled');

        $noTerm = self::runBinColourProbe(['style', '--foreground', 'red', 'hi'], []);
        self::assertSame('hi', $noTerm[0], 'unset TERM is dumb-equivalent: plain bytes');
    }

    /**
     * @param list<string>              $args
     * @param array<string,string>      $extraEnv
     * @return array{0:string,1:int}    [stdout, exit code]
     */
    private static function runBinColourProbe(array $args, array $extraEnv): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $bin = \dirname(__DIR__, 2) . '/bin/candy-scriptui';
        $cmd = array_merge(['timeout', '-s', 'KILL', '20', PHP_BINARY, $bin], $args);
        $env = array_merge([
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
        ], $extraEnv);

        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, \dirname($bin), $env);
        self::assertIsResource($proc, 'the bin child must spawn');
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertSame(0, $code, 'child failed: ' . $stderr);

        return [rtrim($stdout, "\n"), $code];
    }
}

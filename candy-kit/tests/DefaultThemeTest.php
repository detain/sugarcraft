<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Kit\Banner;
use SugarCraft\Kit\HelpText;
use SugarCraft\Kit\Section;
use SugarCraft\Kit\Stage;
use SugarCraft\Kit\StatusLine;
use SugarCraft\Kit\Theme;
use SugarCraft\Sprinkles\Style;

/**
 * The colour-capability guard: a presenter handed no theme renders through
 * Theme::detect() — the ansi palette downgraded via candy-core's
 * ColorProfile::detect() — instead of emitting SGR unconditionally. Before
 * the guard, `StatusLine::success('done') | cat` wrote `\x1b[1m\x1b[92m✓`.
 */
final class DefaultThemeTest extends TestCase
{
    private const ENV_KEYS = ['NO_COLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            putenv($v === false ? $k : "{$k}={$v}");
        }
    }

    /**
     * Every presenter entry point, rendered with the given theme (null = the
     * no-theme fallback).
     *
     * @return array<string, array{\Closure(?Theme): string}>
     */
    public static function presenters(): array
    {
        return [
            'StatusLine::success' => [static fn (?Theme $t): string => StatusLine::success('done', $t)],
            'StatusLine::error'   => [static fn (?Theme $t): string => StatusLine::error('bad', $t)],
            'StatusLine::warn'    => [static fn (?Theme $t): string => StatusLine::warn('hmm', $t)],
            'StatusLine::info'    => [static fn (?Theme $t): string => StatusLine::info('fyi', $t)],
            'StatusLine::prompt'  => [static fn (?Theme $t): string => StatusLine::prompt('ok?', $t)],
            'Banner::title'       => [static fn (?Theme $t): string => Banner::title('App', 'v1', $t)],
            'Section::header'     => [static fn (?Theme $t): string => Section::header('Setup', $t, width: 30)],
            'Section::rule'       => [static fn (?Theme $t): string => Section::rule($t, 10)],
            'Section::subHeader'  => [static fn (?Theme $t): string => Section::subHeader('Opts', $t, width: 30)],
            'Stage::step'         => [static fn (?Theme $t): string => Stage::step(1, 3, 'build', $t)],
            'Stage::subStep'      => [static fn (?Theme $t): string => Stage::subStep('fetch', $t)],
            'Stage::progress'     => [static fn (?Theme $t): string => Stage::subStepWithProgress('dl', 4, 10, $t)],
            'HelpText::render'    => [static fn (?Theme $t): string => HelpText::render('app [flags]', ['flags' => ['-v' => 'verbose']], 'A tool.', $t)],
            'HelpText::renderRows' => [static fn (?Theme $t): string => HelpText::renderRows(['-v' => 'verbose'], $t)],
        ];
    }

    /** Non-tty output (a pipe, a file): not one escape byte, same text as plain. */
    #[DataProvider('presenters')]
    public function testDetectOnNonTtyStreamEmitsNoEscapes(\Closure $render): void
    {
        $pipe = fopen('php://memory', 'w+');
        $theme = Theme::detect([], $pipe);
        fclose($pipe);

        $out = $render($theme);
        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertSame($render(Theme::plain()), $out);
    }

    /** CLICOLOR_FORCE/FORCE_COLOR keep colour even when the stream is not a tty. */
    public function testDetectHonoursForceColorOnNonTtyStream(): void
    {
        $pipe = fopen('php://memory', 'w+');
        $out = StatusLine::success('done', Theme::detect(['CLICOLOR_FORCE' => '1'], $pipe));
        fclose($pipe);

        $this->assertSame(StatusLine::success('done', Theme::ansi()), $out);
    }

    /** NO_COLOR drops colour but keeps emphasis (no-color.org forbids colour only). */
    public function testDetectHonoursNoColor(): void
    {
        $out = StatusLine::success('done', Theme::detect(['NO_COLOR' => '1', 'FORCE_COLOR' => '1']));
        $this->assertSame("\x1b[1m✓\x1b[0m done", $out);
    }

    /**
     * NO_COLOR on a non-tty stream: no escape byte at all, on every presenter.
     *
     * candy-core's ColorProfile::detect() follows charmbracelet/colorprofile:
     * tty-ness is checked before NO_COLOR, so a pipe resolves NoTty and the
     * theme renders exactly as Theme::plain(). (It used to check NO_COLOR
     * first and answer Ascii, which still wrote bold/faint into the pipe.)
     */
    #[DataProvider('presenters')]
    public function testDetectNoColorOnNonTtyStreamDropsColour(\Closure $render): void
    {
        $pipe = fopen('php://memory', 'w+');
        $theme = Theme::detect(['NO_COLOR' => '1'], $pipe);
        fclose($pipe);

        $out = $render($theme);
        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertSame($render(Theme::plain()), $out);
    }

    /** Exact bytes for one presenter: no bold survives into the pipe. */
    public function testDetectNoColorOnNonTtyStreamEmitsPlainText(): void
    {
        $pipe = fopen('php://memory', 'w+');
        $out = StatusLine::success('done', Theme::detect(['NO_COLOR' => '1'], $pipe));
        fclose($pipe);

        $this->assertSame('✓ done', $out);
    }

    /**
     * The no-theme fallback goes through detect() against STDOUT with the
     * process env: NO_COLOR set there never lets colour through.
     *
     * Whether emphasis survives depends on STDOUT itself — a terminal with
     * NO_COLOR resolves Ascii (bold/faint kept), a pipe resolves NoTty (plain)
     * — and PHPUnit's STDOUT is a tty when run interactively but a pipe in
     * CI, so the exact bytes are pinned against detect() on the same stream,
     * and to Theme::plain() whenever that stream is not a terminal.
     */
    #[DataProvider('presenters')]
    public function testNullThemeHonoursNoColorEnv(\Closure $render): void
    {
        putenv('NO_COLOR=1');
        $out = $render(null);
        $this->assertSame($render(Theme::detect(null, \STDOUT)), $out);
        $this->assertDoesNotMatchRegularExpression('/\x1b\[(?:3[0-9]|9[0-7]|38;)/', $out, 'no foreground colour SGR');
        if (!stream_isatty(\STDOUT)) {
            $this->assertSame($render(Theme::plain()), $out);
        }
    }

    /** ...and FORCE_COLOR in the process env restores the full palette, tty or not. */
    #[DataProvider('presenters')]
    public function testNullThemeHonoursForceColorEnv(\Closure $render): void
    {
        putenv('FORCE_COLOR=1');
        $this->assertSame($render(Theme::ansi()), $render(null));
    }

    public function testDetectRejectsNonResourceStream(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::detect([], 'php://stdout');
    }

    /** A closed stream the CALLER passed is still the caller's error. */
    public function testDetectRejectsClosedExplicitStream(): void
    {
        $closed = fopen('php://memory', 'r');
        self::assertIsResource($closed);
        fclose($closed);

        $this->expectException(\InvalidArgumentException::class);
        Theme::detect([], $closed);
    }

    /**
     * Regression: with no theme, detect() substitutes STDOUT itself, so a
     * process that has `fclose(STDOUT)`d (daemons, workers logging
     * elsewhere) must not have every presenter throw a bad-argument error
     * for a stream it never passed. A closed STDOUT is not a terminal, so
     * every fallback renders exactly as Theme::plain() does.
     */
    public function testNoThemeFallbackWithClosedStdoutRendersPlain(): void
    {
        $result = $this->runClosedStdoutProbe([]);

        self::assertTrue($result['ok'], 'a no-theme presenter threw after fclose(STDOUT): ' . ($result['error'] ?? ''));
        self::assertCount(\count(self::presenters()), $result['rendered']);
        self::assertSame($result['plain'], $result['rendered']);
    }

    /** The closed-STDOUT path is "a non-tty stream", so FORCE_COLOR still wins. */
    public function testNoThemeFallbackWithClosedStdoutHonoursForceColor(): void
    {
        $result = $this->runClosedStdoutProbe(['FORCE_COLOR' => '1']);

        self::assertTrue($result['ok'], 'a no-theme presenter threw after fclose(STDOUT): ' . ($result['error'] ?? ''));
        self::assertStringContainsString("\x1b[", $result['rendered']['StatusLine::success']);
    }

    /**
     * Run tests/Support/closed-stdout-probe.php in a child that closes its
     * own STDOUT (an in-process fclose would take PHPUnit's output with it).
     *
     * @param array<string, string> $extraEnv
     * @return array{ok: bool, error?: string, rendered?: array<string, string>, plain?: array<string, string>}
     */
    private function runClosedStdoutProbe(array $extraEnv): array
    {
        $probe = __DIR__ . '/Support/closed-stdout-probe.php';
        $resultFile = tempnam(sys_get_temp_dir(), 'sc_kit_closed_stdout_');
        self::assertIsString($resultFile);

        $env = getenv();
        foreach (self::ENV_KEYS as $k) {
            unset($env[$k]);
        }
        $env = $extraEnv + $env;

        try {
            $process = proc_open(
                [\PHP_BINARY, $probe, $resultFile],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $env,
            );
            self::assertIsResource($process, 'could not start the closed-stdout probe');
            fclose($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            self::assertSame(0, $exit, "closed-stdout probe failed.\nstderr: " . $stderr);
            $raw = (string) file_get_contents($resultFile);
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded, 'closed-stdout probe wrote no JSON: ' . $raw);

            return $decoded;
        } finally {
            @unlink($resultFile);
        }
    }

    /** NoTty strips every SGR attribute and OSC 8, but keeps layout (padding). */
    public function testWithColorProfileNoTtyStripsEveryAttributeButKeepsLayout(): void
    {
        $s = Style::new()->bold()->italic()->underline()->strikethrough()->faint()
            ->blink()->rapidBlink()->reverse()->overline()->invisible()
            ->hyperlink('https://example.com')->foreground(\SugarCraft\Core\Util\Color::hex('#ff0000'))
            ->padding(0, 1);
        $t = (new Theme($s, $s, $s, $s, $s, $s, $s))->withColorProfile(ColorProfile::NoTty);

        foreach (['success', 'error', 'warn', 'info', 'prompt', 'accent', 'muted'] as $slot) {
            $this->assertSame(' x ', $t->{$slot}->render('x'), $slot);
        }
    }

    /**
     * For a direct render, Theme::withColorProfile() is byte-identical to
     * candy-sprinkles' own Style::colorProfile() at every tier — NoTty
     * included, where sprinkles now strips every escape itself — across every
     * preset slot, a style carrying every attribute plus OSC 8 and a border,
     * and pre-styled content. Theme adds nothing to what a render emits; its
     * NoTty attribute unsets exist only for the state-carrying paths pinned
     * by the two tests below.
     *
     * @return iterable<string, array{ColorProfile}>
     */
    public static function renderProfiles(): iterable
    {
        yield 'NoTty'     => [ColorProfile::NoTty];
        yield 'Ascii'     => [ColorProfile::Ascii];
        yield 'Ansi'      => [ColorProfile::Ansi];
        yield 'TrueColor' => [ColorProfile::TrueColor];
    }

    #[DataProvider('renderProfiles')]
    public function testWithColorProfileRendersExactlyAsSprinklesProfile(ColorProfile $profile): void
    {
        $kitchenSink = Style::new()->bold()->italic()->underline()->strikethrough()->faint()
            ->blink()->rapidBlink()->reverse()->overline()->invisible()
            ->hyperlink('https://example.com')
            ->foreground(\SugarCraft\Core\Util\Color::hex('#ff0000'))
            ->background(\SugarCraft\Core\Util\Color::ansi(4))
            ->padding(0, 1)->border(\SugarCraft\Sprinkles\Border::rounded())->width(14);
        $themes = [
            'ansi'       => Theme::ansi(),
            'charm'      => Theme::charm(),
            'dracula'    => Theme::dracula(),
            'nord'       => Theme::nord(),
            'catppuccin' => Theme::catppuccin(),
            'kitchen'    => new Theme($kitchenSink, $kitchenSink, $kitchenSink, $kitchenSink, $kitchenSink, $kitchenSink, $kitchenSink),
        ];
        $contents = ['x', 'hello world', "two\nlines", "\x1b[31mpre\x1b[0m-styled"];

        foreach ($themes as $name => $theme) {
            $fitted = $theme->withColorProfile($profile);
            foreach (['success', 'error', 'warn', 'info', 'prompt', 'accent', 'muted'] as $slot) {
                foreach ($contents as $content) {
                    self::assertSame(
                        $theme->{$slot}->colorProfile($profile)->render($content),
                        $fitted->{$slot}->render($content),
                        "{$name}.{$slot} at {$profile->name} for " . json_encode($content),
                    );
                }
            }
        }
    }

    /**
     * The NoTty downgrade is one-way for text attributes and hyperlinks: a
     * detected-for-a-pipe theme re-profiled to a richer tier gets its colour
     * back but not bold/OSC 8. Sprinkles' NoTty strip alone cannot hold this
     * — it keys off the current profile — which is why Theme still unsets
     * the attributes itself.
     */
    public function testNoTtyDowngradeIsOneWayForTextAttributes(): void
    {
        $linked = Style::new()->bold()->hyperlink('https://example.com');
        $pipe = (new Theme($linked, $linked, $linked, $linked, $linked, $linked, $linked))
            ->withColorProfile(ColorProfile::NoTty);

        foreach ([ColorProfile::Ascii, ColorProfile::Ansi, ColorProfile::TrueColor] as $richer) {
            self::assertSame('x', $pipe->withColorProfile($richer)->success->render('x'), $richer->name);
            self::assertSame('x', $pipe->success->colorProfile($richer)->render('x'), $richer->name);
        }

        $colourBack = Theme::ansi()->withColorProfile(ColorProfile::NoTty)
            ->withColorProfile(ColorProfile::Ansi)->success->render('x');
        self::assertSame("\x1b[92mx\x1b[0m", $colourBack);
    }

    /**
     * inherit() copies attribute flags but not the parent's NoTty profile when
     * the child pins its own, so a NoTty theme style composed into such a
     * child must not hand it bold.
     */
    public function testNoTtyStyleInheritedIntoProfilePinnedChildCarriesNoAttributes(): void
    {
        $pipe = Theme::ansi()->withColorProfile(ColorProfile::NoTty);

        $child = Style::new()->colorProfile(ColorProfile::Ascii)->inherit($pipe->success);

        self::assertSame('x', $child->render('x'));
    }

    /** Ansi profile re-quantises truecolor presets to the 16-colour tier. */
    public function testWithColorProfileAnsiDownsamplesTruecolor(): void
    {
        $out = Theme::charm()->withColorProfile(ColorProfile::Ansi)->success->render('x');
        $this->assertStringNotContainsString('38;2;', $out);
        $this->assertStringContainsString("\x1b[1m", $out);
    }

    public function testWithColorProfileReturnsNewInstance(): void
    {
        $t = Theme::ansi();
        $this->assertNotSame($t, $t->withColorProfile(ColorProfile::TrueColor));
        $this->assertSame(ColorProfile::TrueColor, $t->accent->getColorProfile());
        $this->assertSame(ColorProfile::Ascii, $t->withColorProfile(ColorProfile::Ascii)->accent->getColorProfile());
    }
}

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
     * NO_COLOR on a non-tty stream: never any colour, on every presenter.
     *
     * Pins the CURRENT attribute behaviour too. candy-core's
     * ColorProfile::detect() checks NO_COLOR before tty-ness and so answers
     * Ascii (bold/faint kept) where upstream charmbracelet/colorprofile
     * answers NoTTY (no escape bytes). When core adopts the upstream order
     * this expectation must deliberately change to `$render(Theme::plain())`.
     */
    #[DataProvider('presenters')]
    public function testDetectNoColorOnNonTtyStreamDropsColour(\Closure $render): void
    {
        $pipe = fopen('php://memory', 'w+');
        $theme = Theme::detect(['NO_COLOR' => '1'], $pipe);
        fclose($pipe);

        $out = $render($theme);
        $this->assertDoesNotMatchRegularExpression('/\x1b\[(?:[0-9;]*;)?(?:3[0-9]|4[0-9]|9[0-7]|10[0-7])(?:[;m])/', $out, 'no colour SGR');
        $this->assertSame(
            $render(Theme::ansi()->withColorProfile(ColorProfile::Ascii)),
            $out,
            'candy-core ColorProfile::detect() resolves NO_COLOR before tty-ness (Ascii); '
            . 'once it matches upstream (non-tty => NoTty) expect $render(Theme::plain()) here',
        );
    }

    /** Exact bytes of the gap the test above describes, for one presenter. */
    public function testDetectNoColorOnNonTtyStreamCurrentlyKeepsBold(): void
    {
        $pipe = fopen('php://memory', 'w+');
        $out = StatusLine::success('done', Theme::detect(['NO_COLOR' => '1'], $pipe));
        fclose($pipe);

        $this->assertSame("\x1b[1m✓\x1b[0m done", $out);
    }

    /** The no-theme fallback goes through detect(): NO_COLOR in the process env applies. */
    #[DataProvider('presenters')]
    public function testNullThemeHonoursNoColorEnv(\Closure $render): void
    {
        putenv('NO_COLOR=1');
        $out = $render(null);
        $this->assertSame($render(Theme::ansi()->withColorProfile(ColorProfile::Ascii)), $out);
        $this->assertDoesNotMatchRegularExpression('/\x1b\[(?:3[0-9]|9[0-7]|38;)/', $out, 'no foreground colour SGR');
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

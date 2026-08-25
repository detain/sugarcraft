<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentViewPane;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer;
use SugarCraft\Crush\Tui\TerminalBackground;
use SugarCraft\Sprinkles\Theme as SprinklesTheme;

/**
 * The shell's legibility contract, asserted as a RATIO on the bytes that reach
 * the terminal.
 *
 * This exists because of a bug the user reported three times — "there are
 * borderes.. just foreground matchs background color so invis", then "im using
 * the 'ansi' thme right now which the mnyu bordr is not visible". Nothing was
 * missing and nothing was clipped: {@see MenuBar::renderDropdown()} drew a
 * complete box, and {@see Renderer::renderView()} splices it in after both
 * clips precisely so it cannot be trimmed. The box was simply painted #6b7280,
 * a literal, which measures 2.95:1 against a dracula background and 4.34:1
 * against pure black — and the shell had 100 such literals (15 distinct values,
 * 74 of them inside a `Color::hex()` call) across 14 files, none of which
 * consulted a Theme at all. Re-MEASURED at 6c1e51c8^ with
 * `git grep -oE "'#[0-9a-fA-F]{3,8}'" -- sugar-crush/src/Tui`; the 84/14 this
 * comment used to quote was a different, unrecorded count.
 *
 * So the assertion here is deliberately NOT "MenuBar uses $theme->border". That
 * would pin the presence of a clause, say nothing about legibility, and pass
 * against a theme whose border equals its background. It renders the WHOLE
 * shell frame, walks the SGR stream, and measures every coloured run against
 * the colour it is actually seen against — the terminal's own background
 * ({@see TerminalBackground::color()}, which is why W3 had to stop reducing the
 * OSC 11 reply to one bit), or the fill when a run paints its own. Written that
 * way it covers every file the frame reaches and every theme added later.
 *
 * ONE EXCLUSION, deliberate and measured. The transcript's assistant turns are
 * rendered by CandyShine from `Theme::$markdown` — a {@see \SugarCraft\Shine\Theme},
 * a second colour system with its own tokens that W3 did not touch — so the
 * history driven here is user/system only. Running the same assertion WITH an
 * assistant turn present fails, and legitimately: on a white terminal
 * `dracula`'s markdown body paints #f8f8f2 (1.07:1) and `tokyoNight`'s #a9b1d6
 * (2.11:1). That is the same class of bug one layer down and it needs
 * candy-shine's palette put under the same guard; it is NOT covered here, and
 * saying so beats a test that quietly skips it.
 */
final class ShellContrastTest extends TestCase
{
    /** Terminal width/height the frame is composed at. */
    private const COLS = 120;
    private const ROWS = 40;

    /**
     * Backgrounds a real terminal actually paints, each one a case the old
     * literals got wrong in a different way:
     *
     *   - #000000  pure black: what SprinklesTheme::ansi() assumes, and where
     *              #6b7280 scored its BEST result (4.34:1) and still failed.
     *   - #ffffff  pure white: the reverse case entirely.
     *   - #0e0e14  the `dark` palette's own background.
     *   - #282a36  dracula's: where #6b7280 measured 2.95:1.
     *   - #1a1b26  tokyoNight's.
     *   - #fafafa  the `light` palette's own.
     *   - #808080  the crossover-ish mid grey no palette token survives, and the
     *              one that catches a direction computed with the wrong rule.
     *   - #333f58  a mid-dark blue: "dark" is not one colour.
     *   - #666666  the grey the ROLE-COLLAPSE was reported on, added because the
     *              list above did not contain it: MEASURED on the version of
     *              `Theme::pair()` this file was written against, `dark` on
     *              #666666 projected 7 of its 8 shell tokens onto one #ffffff
     *              while every ratio here stayed green, so the domain the
     *              distinctness assertion holds over has to include it.
     *
     * @return list<string>
     */
    private const BACKGROUNDS = [
        '#000000',
        '#ffffff',
        '#0e0e14',
        '#282a36',
        '#1a1b26',
        '#fafafa',
        '#808080',
        '#333f58',
        '#666666',
    ];

    /**
     * The one file under `src/Tui/` allowed to construct a Color, and why: it is
     * the file that RESOLVES the background, so it necessarily builds one from
     * the OSC 11 reply's r/g/b and one from a palette slot for each nominal
     * pre-reply floor. It names no colour of the shell's own.
     *
     * @var list<string>
     */
    private const COLOUR_CONSTRUCTORS_ALLOWED = ['TerminalBackground.php'];

    /**
     * Every ratio in this file is measured against a background the test itself
     * observes, so an inherited `SUGARCRUSH_BACKGROUND` — which outranks the
     * observed reply — silently replaces all nine of them with #000000 or
     * #ffffff. MEASURED: `SUGARCRUSH_BACKGROUND=dark` turns 10 of these 12 tests
     * red, `=light` another 10, and every message blames a colour. Cleared once
     * in `tests/bootstrap.php`; asserted here so that line cannot be deleted
     * silently.
     */
    protected function setUp(): void
    {
        $this->assertFalse(
            getenv(TerminalBackground::ENV_OVERRIDE),
            TerminalBackground::ENV_OVERRIDE . ' must be unset for this suite to measure what it claims',
        );
        $this->assertFalse(getenv('COLORFGBG'), 'COLORFGBG must be unset for this suite');
    }

    protected function tearDown(): void
    {
        MenuBar::closeMenu();
        TerminalBackground::forget();
        Renderer::setSize(self::COLS, self::ROWS);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function themeProvider(): array
    {
        $cases = [];
        foreach (Theme::names() as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @dataProvider themeProvider
     */
    public function testEveryColourTheShellPaintsIsLegibleAgainstTheRealTerminalBackground(string $themeName): void
    {
        foreach (self::BACKGROUNDS as $hex) {
            $background = Color::hex($hex);
            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($background->r, $background->g, $background->b));

            // Every pane in turn: the sidebars, boxes and status colours differ
            // per pane, and a focused box is a different token from an
            // unfocused one.
            foreach (Pane::cases() as $pane) {
                $app = $this->app($themeName, $pane);

                // Menu 1 open, so the dropdown panel — the reported surface —
                // is part of the frame being measured.
                MenuBar::openMenu(1);
                $this->assertFrameIsLegible(
                    Renderer::render($app, self::COLS, self::ROWS),
                    $background,
                    "{$themeName} on {$hex}, pane {$pane->name}, menu open",
                );
                MenuBar::closeMenu();

                $this->assertFrameIsLegible(
                    Renderer::render($app, self::COLS, self::ROWS),
                    $background,
                    "{$themeName} on {$hex}, pane {$pane->name}",
                );
            }
        }
    }

    /**
     * Every coloured, non-blank run in `$frame` reaches {@see Theme::CONTRAST_MIN}
     * against what it is painted on.
     *
     * Runs with no explicit foreground are skipped: those are the terminal's own
     * default foreground on its own default background, a pairing the user
     * configured and this app does not choose. Blank runs are skipped because a
     * space has no glyph to see (a space with a BACKGROUND is the highlight fill
     * itself, whose visibility is a separate property — see the highlight test).
     */
    private function assertFrameIsLegible(string $frame, Color $background, string $where): void
    {
        // Violations are COLLECTED and asserted once, rather than one assertion
        // per run: a 120x40 frame holds thousands of runs, and per-run
        // assertions would put six figures of them on the suite's counter while
        // reporting only the first failure of each frame. One assertion per
        // frame reports every offending colour in that frame at once.
        $violations = [];

        foreach (self::runs($frame) as [$fg, $bg, $text]) {
            if ($fg === null || trim($text) === '') {
                continue;
            }

            $against = $bg ?? $background;
            $ratio = Theme::contrast($fg, $against);
            if ($ratio >= Theme::CONTRAST_MIN) {
                continue;
            }

            $violations[sprintf('%s on %s = %.2f:1', $fg->toHex(), $against->toHex(), $ratio)]
                = var_export(mb_substr(trim($text), 0, 40), true);
        }

        $this->assertSame(
            [],
            $violations,
            $where . ': every colour the shell paints must reach ' . Theme::CONTRAST_MIN . ':1',
        );
    }

    /**
     * The highlight fill has to be TELLABLE from the terminal's background, or
     * the selected row is not marked at all. A weak floor on purpose: 1.05:1 is
     * roughly the smallest step that is not the same colour, and
     * {@see Theme}'s highlight deliberately gives ground to legibility near the
     * crossover background rather than holding a fixed shift.
     */
    public function testTheRowHighlightIsDistinguishableFromEveryBackground(): void
    {
        foreach (self::BACKGROUNDS as $hex) {
            $background = Color::hex($hex);
            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($background->r, $background->g, $background->b));

            foreach (Theme::names() as $name) {
                $fill = Theme::byName($name)->shellSeparator;

                $this->assertGreaterThan(
                    1.05,
                    Theme::contrast($fill, $background),
                    "{$name} on {$hex}: highlight fill {$fill->toHex()} is indistinguishable from the background",
                );
            }
        }
    }

    /**
     * The guard that keeps the sweep from rotting: no file under `src/Tui/` may
     * name a colour itself.
     *
     * A presence check, and it earns its place only as a COMPANION to the ratio
     * assertions above — those measure the frame, this one covers the code paths
     * a 120x40 frame with no agents and no sessions does not reach (the agent
     * dashboard's rows, the stall indicator, the session picker's footer). A new
     * literal in one of those is a colour no theme can move and no frame test
     * would see.
     */
    public function testNoFileUnderSrcTuiNamesAColourItself(): void
    {
        $offenders = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Tui', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dir as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            foreach (explode("\n", $source) as $number => $line) {
                if (preg_match("/'#[0-9a-fA-F]{3,8}'/", $line) === 1) {
                    $offenders[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                }
                // A hex literal is only the FORM the reported bug took.
                // `Color::rgb(107, 114, 128)` is the same #6b7280 and used to
                // walk straight past this guard: MEASURED, that exact
                // substitution at src/Tui/SessionPicker.php survived this test
                // 12/12 AND SessionPickerTest 34/34, because the picker is not
                // in a 120x40 frame with no sessions. Constructing a Color at
                // all is the thing to catch, so the pattern is the constructor,
                // not the notation.
                if (
                    preg_match('/\bColor::(rgb|hex|hsl|hsv|ansi|ansi256|parse)\s*\(|new\s+Color\s*\(/', $line) === 1
                    && !in_array($file->getFilename(), self::COLOUR_CONSTRUCTORS_ALLOWED, true)
                ) {
                    $offenders[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, 'src/Tui/ must take its colours from Theme, not from literals');
    }

    /**
     * Split a rendered frame into `[foreground, background, text]` runs by
     * replaying its SGR stream.
     *
     * COLOUR is what is tracked: `38;2;r;g;b`, `48;2;r;g;b`, `38;5;n`/`48;5;n`,
     * the 4-bit `30-37`/`90-97`/`40-47`/`100-107` forms, and the resets
     * `0`/`39`/`49`. The palette forms matter because the `ansi` theme is built
     * from {@see Color::ansi()} and W3(c) makes those slots survive to the wire;
     * their RGB is xterm's DEFAULT palette, so for a terminal that has remapped
     * its 16 colours these numbers are nominal and the terminal owns the result.
     *
     * INTENSITY is not, and that is a real hole rather than a tidy exclusion.
     * `src/Renderer.php` has 17 `->faint()` call sites (system rows, tool rows,
     * the thinking spinner, the gutter), and SGR 2 is rendered by most terminals
     * as roughly half-way toward the background — which halves the ratio this
     * walker measured. MEASURED at that assumption on a #282a36 terminal:
     * dracula's projected `shellMuted` #a3acc9 reads 6.31:1 here and 2.70:1 as
     * painted (#666b80), and #abaebf 6.47:1 becomes 2.74:1. Both are inside this
     * test's claimed domain and both fail the threshold. It is NOT modelled,
     * because the fix is in the renderer (a token that has already been chosen
     * for legibility must not then be dimmed) and not in the measurement, and
     * because "half-way" is a terminal-by-terminal guess this suite would then
     * be pinning as fact. Recorded as a known gap, the same way the CandyShine
     * markdown palette is. SGR 7 (inverse) genuinely does not occur: 0
     * occurrences in a 120x40 frame in any palette.
     *
     * @return list<array{0: ?Color, 1: ?Color, 2: string}>
     */
    private static function runs(string $frame): array
    {
        $runs = [];
        $fg = null;
        $bg = null;
        $offset = 0;

        // The 'm' terminator is the only SGR this walker cares about; cursor
        // moves and the like carry no colour and are treated as text (they are
        // stripped from the measured text by the trim() in the caller only if
        // they are blank, which is exactly right: they paint nothing).
        while (preg_match('/\e\[([0-9;]*)m/', $frame, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = (int) $m[0][1];
            $text = substr($frame, $offset, $start - $offset);
            if ($text !== '') {
                $runs[] = [$fg, $bg, $text];
            }

            [$fg, $bg] = self::apply((string) $m[1][0], $fg, $bg);
            $offset = $start + strlen((string) $m[0][0]);
        }

        $tail = substr($frame, $offset);
        if ($tail !== '') {
            $runs[] = [$fg, $bg, $tail];
        }

        return $runs;
    }

    /**
     * @return array{0: ?Color, 1: ?Color}
     */
    private static function apply(string $params, ?Color $fg, ?Color $bg): array
    {
        $codes = array_map('intval', explode(';', $params === '' ? '0' : $params));

        for ($i = 0; $i < count($codes); $i++) {
            $code = $codes[$i];

            if ($code === 0) {
                $fg = null;
                $bg = null;

                continue;
            }
            if ($code === 39) {
                $fg = null;

                continue;
            }
            if ($code === 49) {
                $bg = null;

                continue;
            }
            if ($code >= 30 && $code <= 37) {
                $fg = Color::ansi($code - 30);

                continue;
            }
            if ($code >= 90 && $code <= 97) {
                $fg = Color::ansi($code - 90 + 8);

                continue;
            }
            if ($code >= 40 && $code <= 47) {
                $bg = Color::ansi($code - 40);

                continue;
            }
            if ($code >= 100 && $code <= 107) {
                $bg = Color::ansi($code - 100 + 8);

                continue;
            }
            if ($code === 38 || $code === 48) {
                $mode = $codes[$i + 1] ?? null;
                if ($mode === 2) {
                    $colour = Color::rgb($codes[$i + 2] ?? 0, $codes[$i + 3] ?? 0, $codes[$i + 4] ?? 0);
                    $i += 4;
                } elseif ($mode === 5) {
                    $colour = Color::ansi256($codes[$i + 2] ?? 0);
                    $i += 2;
                } else {
                    continue;
                }

                if ($code === 38) {
                    $fg = $colour;
                } else {
                    $bg = $colour;
                }
            }
        }

        return [$fg, $bg];
    }

    private function app(string $themeName, Pane $pane): App
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('stub');

        $chat = (new Chat(history: [Message::user('hello'), Message::system('a note')]))
            ->withThemeName($themeName)
            ->withSize(self::COLS, self::ROWS);

        return App::new($provider, 'stub-model')
            ->withChat($chat)
            ->withPane($pane)
            ->withStatus('Working');
    }
    /**
     * W3(c), and the user's third report: "im using the 'ansi' thme right now
     * which the mnyu bordr is not visible".
     *
     * {@see \SugarCraft\Sprinkles\Theme::ansi()} is built exclusively from
     * `Color::ansi(0..8)` precisely so an app on that theme DEFERS to the
     * terminal's own 16 colours. That intent was defeated on any terminal
     * advertising more than 16: candy-core's `Color` stored only RGB, so
     * `Color::ansi(8)` collapsed to [127,127,127] and came back out as
     * `\e[38;2;127;127;127m` — an absolute value overriding the palette the user
     * chose the theme to honour. Measured on this host before the fix:
     * `ColorProfile::Ansi` -> `\e[90m`, `Ansi256` -> `\e[38;5;244m`, `TrueColor`
     * -> `\e[38;2;127;127;127m`, and candy-sprinkles' Style renders at TrueColor
     * unconditionally, so the shell always got the third one.
     *
     * So: under `ansi` the frame must carry NO absolute colour at all. Under
     * `dark` it must carry them, or the assertion would pass on a shell that had
     * simply stopped emitting colour.
     */
    public function testTheAnsiThemeReachesTheTerminalAsPaletteCodesNotAbsoluteColour(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(0, 0, 0));

        $ansi = Renderer::render($this->app('ansi', Pane::Chat), self::COLS, self::ROWS);

        $this->assertDoesNotMatchRegularExpression('/\e\[[0-9;]*[34]8;2;/', $ansi, 'truecolor leaked');
        $this->assertDoesNotMatchRegularExpression('/\e\[[0-9;]*[34]8;5;/', $ansi, '256-cube leaked');
        // ...and it is still coloured: at least one 4-bit palette code.
        $this->assertMatchesRegularExpression('/\e\[(?:3[0-7]|4[0-7]|9[0-7]|10[0-7])m/', $ansi);

        // The control: a hex-built palette does emit absolute colour, so the
        // assertions above are about the palette slots and not about the shell
        // having gone monochrome.
        $dark = Renderer::render($this->app('dark', Pane::Chat), self::COLS, self::ROWS);
        $this->assertMatchesRegularExpression('/\e\[38;2;/', $dark);
    }

    /**
     * The one place the `ansi` theme legitimately stops being palette slots: a
     * token {@see Theme} has to NUDGE for legibility is no longer that slot, so
     * it renders as the absolute colour it now is. Asserted so the trade is
     * recorded rather than discovered.
     *
     * On a white terminal `ansi`'s foreground is `Color::ansi(7)` — #e5e5e5,
     * MEASURED at 1.26:1 — so it must move; on black it is 16.67:1 and must
     * not.
     */
    public function testAnAnsiTokenNudgedForLegibilityStopsBeingAPaletteSlot(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(0, 0, 0));
        $this->assertSame(8, Theme::byName('ansi')->border->ansiIndex, 'untouched slot must stay a slot');

        TerminalBackground::forget();
        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255));
        $onWhite = Theme::byName('ansi')->shellForeground;

        $this->assertNull($onWhite->ansiIndex, 'a nudged colour is no longer the slot it came from');
        $this->assertGreaterThanOrEqual(
            Theme::CONTRAST_MIN,
            Theme::contrast($onWhite, Color::rgb(255, 255, 255)),
        );
    }
    /**
     * The token-level property behind the frame walk: every token must clear
     * {@see Theme::CONTRAST_MIN} against BOTH the bare terminal background and
     * the row-highlight fill.
     *
     * Needed because the frame walk cannot reach the fill. The only surface that
     * paints its own background is a SELECTED agent row, and a shell with no
     * agents registered renders none — so a `pair()` that resolved its tokens
     * against the bare background instead of the fill (leaving text at exactly
     * 4.5:1 failing inside a highlighted row) passed every frame assertion.
     * Measured, not assumed: that mutation was killed only by the
     * distinguishability test, which is about the fill's visibility and says
     * nothing about the text on it.
     */
    public function testEveryTokenClearsTheThresholdAgainstBothTheBackgroundAndTheHighlight(): void
    {
        $tokens = [
            'border', 'userLabel', 'assistantLabel', 'systemLabel',
            'shellForeground', 'shellMuted', 'shellPrimary',
            'shellSuccess', 'shellWarning', 'shellError', 'shellInfo',
        ];

        $violations = [];
        foreach (self::BACKGROUNDS as $hex) {
            $background = Color::hex($hex);
            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($background->r, $background->g, $background->b));

            foreach (Theme::names() as $name) {
                $theme = Theme::byName($name);
                foreach ($tokens as $token) {
                    /** @var Color $colour */
                    $colour = $theme->$token;
                    foreach (['background' => $background, 'highlight' => $theme->shellSeparator] as $label => $against) {
                        $ratio = Theme::contrast($colour, $against);
                        if ($ratio < Theme::CONTRAST_MIN) {
                            $violations[] = sprintf(
                                '%s on %s: %s (%s) vs %s %s = %.2f:1',
                                $name,
                                $hex,
                                $token,
                                $colour->toHex(),
                                $label,
                                $against->toHex(),
                                $ratio,
                            );
                        }
                    }
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * The selected agent row is the shell's one self-backgrounding surface, and
     * the frame walk above never reaches it (no agents are registered in a bare
     * App). Rendered directly so the highlight's own bytes are measured.
     */
    public function testTheSelectedAgentRowIsLegibleOnItsOwnHighlightFill(): void
    {
        $agents = [
            new AgentDisplayState(
                name: 'coder-1',
                status: 'working',
                operation: 'Reading auth.php',
                elapsedSeconds: 45,
                tokensUsed: 500,
                costUsd: 0.0012,
            ),
            new AgentDisplayState(
                name: 'coder-2',
                status: 'failed',
                operation: 'Boom',
                elapsedSeconds: 3,
                tokensUsed: 10,
                costUsd: 0.0,
            ),
        ];

        foreach (self::BACKGROUNDS as $hex) {
            $background = Color::hex($hex);
            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($background->r, $background->g, $background->b));

            foreach (Theme::names() as $name) {
                $theme = Theme::byName($name);
                foreach ([0, 1] as $selected) {
                    $this->assertFrameIsLegible(
                        AgentViewPane::render($agents, $selected, 80, 20, $theme),
                        $background,
                        "{$name} on {$hex}, agent row {$selected} selected",
                    );
                }
            }
        }
    }

    /**
     * Two palette colours that DIFFER must still differ after the projection.
     *
     * The companion property to the ratio assertions, and the one they cannot
     * see: legibility is satisfied by painting the entire shell one colour, so a
     * suite that only measures ratios passes a theme that has lost every
     * distinction it has. MEASURED on the version of `Theme::pair()` this test
     * was written against: replacing all eleven token sources with the terminal
     * background itself — a twelve-token palette collapsed to one colour — left
     * all ten contrast tests in this file green. And unmutated, an `ansi` theme
     * on a #ffffff terminal projected `border`/`muted` (`Color::ansi(8)`) and
     * `foreground` (`ansi(7)`) onto one byte-identical #666666, so the dropdown's
     * box lines were exactly its item text: the user's first report.
     *
     * Distinctness is checked as BYTES, deliberately. A contrast ratio between
     * two tokens is the wrong instrument — {@see Theme::contrast()} is
     * luminance-only, and `dark`'s projected `error` #f5a0a0 and `info` #87c0d0
     * measure 1.006:1 against each other while being obviously different
     * colours.
     *
     * Sources that are EQUAL are expected to stay equal and are not asserted on:
     * `ansi` writes `border` and `muted` as the same `Color::ansi(8)`, and
     * inventing a difference its author did not is not the projection's job.
     * That is why the expectation is derived from the SprinklesTheme rather than
     * from the Theme under test.
     *
     * `adaptive` is not iterated: it IS `dark` or `light` (whichever
     * {@see TerminalBackground::isDark()} calls for) run through the same
     * `pair()`, so it adds a third resolution of an already-covered palette.
     */
    public function testTwoPaletteColoursThatDifferStillDifferAfterTheProjection(): void
    {
        // token => the SprinklesTheme colour it is projected FROM.
        $sources = [
            'border' => 'border',
            'userLabel' => 'primary',
            'assistantLabel' => 'secondary',
            'systemLabel' => 'muted',
            'shellForeground' => 'foreground',
            'shellMuted' => 'muted',
            'shellPrimary' => 'primary',
            'shellSuccess' => 'success',
            'shellWarning' => 'warning',
            'shellError' => 'error',
            'shellInfo' => 'info',
        ];

        $palettes = [
            'dark' => SprinklesTheme::dark(),
            'light' => SprinklesTheme::light(),
            'dracula' => SprinklesTheme::dracula(),
            'tokyoNight' => SprinklesTheme::tokyoNight(),
            'ansi' => SprinklesTheme::ansi(),
        ];

        $violations = [];
        foreach (self::BACKGROUNDS as $hex) {
            $background = Color::hex($hex);
            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($background->r, $background->g, $background->b));

            foreach ($palettes as $name => $sprinkles) {
                $theme = Theme::byName($name);

                // projected hex => the source hex that got there first.
                $claimed = [];
                foreach ($sources as $token => $source) {
                    /** @var Color $projected */
                    $projected = $theme->$token;
                    /** @var Color $from */
                    $from = $sprinkles->$source;

                    $key = $projected->toHex();
                    $owner = $claimed[$key] ?? null;
                    if ($owner !== null && $owner[0] !== $from->toHex()) {
                        $violations[] = sprintf(
                            '%s on %s: %s (from %s) and %s (from %s) both project to %s',
                            $name,
                            $hex,
                            $token,
                            $from->toHex(),
                            $owner[1],
                            $owner[0],
                            $key,
                        );

                        continue;
                    }
                    $claimed[$key] = [$from->toHex(), $token];
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * The threshold is 4.5, and 4.5 is what MOVES the colour that was reported.
     *
     * Every other assertion in this file compares against
     * {@see Theme::CONTRAST_MIN}, which makes them all silent about the constant
     * itself: MEASURED, setting it to 1.0 left 11 of the 12 tests here green
     * while the shell rendered its raw palette values — dracula's border at
     * 1.56:1 on its own background. So this test writes 4.5 out as a literal and
     * pins two pre-projection measurements that the constant has to be strictly
     * above to do its job.
     *
     * The figures are hardcoded on purpose. #6b7280 is the literal the shell
     * actually painted the dropdown box with, and 2.95:1 / 4.34:1 are what it
     * measured on the two backgrounds the user reported it on — facts about a
     * colour, not about this code, so nothing in `Theme` can move them.
     */
    public function testTheThresholdIsFourPointFiveAndIsWhatMovesTheReportedColour(): void
    {
        $this->assertSame(4.5, Theme::CONTRAST_MIN);

        // The reported literal, pre-projection, on dracula's background and on
        // pure black (where it scored its best result and still failed).
        $this->assertEqualsWithDelta(
            2.9456,
            Theme::contrast(Color::hex('#6b7280'), Color::hex('#282a36')),
            0.0005,
        );
        $this->assertEqualsWithDelta(
            4.3438,
            Theme::contrast(Color::hex('#6b7280'), Color::hex('#000000')),
            0.0005,
        );

        // And the same class of failure inside the palettes themselves, which is
        // what the projection has to correct. Each row: theme, terminal
        // background, token, the palette's raw colour for it, and that raw
        // colour's ratio against that background.
        $cases = [
            ['dracula', '#282a36', 'border', '#44475a', 1.5561],
            ['ansi', '#ffffff', 'shellForeground', '#e5e5e5', 1.2597],
            ['light', '#fafafa', 'shellWarning', '#f59e0b', 2.0576],
        ];

        foreach ($cases as [$name, $hex, $token, $raw, $rawRatio]) {
            $background = Color::hex($hex);
            $this->assertEqualsWithDelta(
                $rawRatio,
                Theme::contrast(Color::hex($raw), $background),
                0.0005,
                "{$name} {$token}: the raw palette colour's ratio is a fact about #{$raw}",
            );

            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($background->r, $background->g, $background->b));
            /** @var Color $projected */
            $projected = Theme::byName($name)->$token;

            $this->assertNotSame(
                $raw,
                $projected->toHex(),
                "{$name} on {$hex}: {$token} reached the terminal as the raw {$raw} at {$rawRatio}:1",
            );
            $this->assertGreaterThanOrEqual(
                4.5,
                Theme::contrast($projected, $background),
                "{$name} on {$hex}: {$token} must clear 4.5:1 — the literal, not the constant",
            );
        }
    }
}

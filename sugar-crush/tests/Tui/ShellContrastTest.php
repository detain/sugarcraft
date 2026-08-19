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
 * against pure black — and the shell had 84 such literals across 14 files, none
 * of which consulted a Theme at all.
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
    ];

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
            }
        }

        $this->assertSame([], $offenders, 'src/Tui/ must take its colours from Theme, not from literals');
    }

    /**
     * Split a rendered frame into `[foreground, background, text]` runs by
     * replaying its SGR stream.
     *
     * Only the four things this shell emits are tracked: `38;2;r;g;b`,
     * `48;2;r;g;b`, and the resets `0`/`39`/`49`. `38;5;n` and the 4-bit
     * `30-37`/`90-97` forms are decoded too, because the `ansi` palette is built
     * from {@see Color::ansi()} and W3(c) makes those slots survive to the wire;
     * their RGB is xterm's DEFAULT palette, so for a terminal that has remapped
     * its 16 colours these numbers are nominal and the terminal owns the result.
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
     * On a white terminal `ansi`'s foreground is `Color::ansi(7)` at 1.16:1, so
     * it must move; on black it is 16.67:1 and must not.
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
}

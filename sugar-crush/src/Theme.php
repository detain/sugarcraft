<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Util\Color;
use SugarCraft\Crush\Tui\TerminalBackground;
use SugarCraft\Shine\Theme as ShineTheme;
use SugarCraft\Sprinkles\Theme as SprinklesTheme;

/**
 * A named color theme for {@see Renderer}'s chrome (borders, user/assistant/
 * system labels), the whole `src/Tui/` shell (menu bar and its dropdown, pane
 * boxes, sidebars, status colours) and CandyShine's markdown rendering,
 * resolved from the two theme classes that already exist elsewhere in the
 * monorepo rather than inventing a third color system:
 *
 *   - {@see SprinklesTheme} supplies the chrome colors (border/labels/`shell*`).
 *   - {@see ShineTheme} supplies the markdown-rendering theme.
 *
 * The `shell*` tokens exist because `src/Tui/` used to be theme-blind: 14 files
 * hardcoded 14 distinct hex literals across 84 call sites and none of them
 * consulted a Theme, so `/theme` moved the transcript and left the shell
 * around it exactly where it was. Those literals were also a single palette's
 * values scattered into the wrong roles — the menu dropdown's border was
 * #6b7280, which is the LIGHT palette's `muted`, and its item text was
 * #e5e7eb, the light palette's `border`.
 *
 * Every projected token is contrast-checked against the terminal's real
 * background before it leaves {@see pair()}; see there for why that is load
 * bearing rather than decorative.
 *
 * The offered name list ({@see names()}) is deliberately restricted to the
 * intersection of presets both classes support by the same name
 * (`dark`/`light`/`dracula`/`tokyoNight`/`ansi`) - SprinklesTheme also has
 * `oneDark`/`githubDark`/`solarizedDark`/`solarizedLight` and ShineTheme also
 * has `pink`/`plain`/`notty`/`ascii`, but offering a name only one side
 * recognizes would silently mismatch chrome and markdown colors, so those
 * extras are left out of the picker for now.
 *
 * `adaptive` is the one name neither class resolves on its own: it is paired
 * here, from `dark`/`light` on BOTH sides at once, against
 * {@see TerminalBackground}'s answer for the attached terminal. That keeps the
 * chrome/markdown pairing invariant intact (both halves always come from the
 * same side of the light/dark split) while giving the user a preset that does
 * not require them to know to run `/theme light` on a light terminal. It stays
 * resolved-on-read rather than snapshotted, so a persisted `adaptive` re-reads
 * the terminal on the next launch instead of freezing whatever the first one
 * happened to detect — and, since {@see \SugarCraft\Crush\App\App::init()}
 * asks the terminal over OSC 11 and the reply lands some frames later,
 * resolved-on-read is also what lets that answer take effect mid-session
 * without any cache to invalidate.
 */
final class Theme
{
    private const NAMES = ['dark', 'light', 'dracula', 'tokyoNight', 'ansi', 'adaptive'];

    /**
     * The one contrast ratio every colour the shell paints must reach against
     * what is actually behind it. WCAG 2.1 AA for body text.
     *
     * Deliberately ONE tier, not WCAG's two. The obvious split — 4.5:1 for text
     * and 3.0:1 for shapes (box lines, dividers, status dots) — is not
     * assertable on this shell, because candy-sprinkles paints a box's TITLE in
     * the border colour: an unfocused pane's `border` token is what draws both
     * the `─` run and the word `chat` sitting in it, and the dropdown's `border`
     * draws `│ ` and ` │` around item rows. There is no colour here that is only
     * ever a shape, so a 3.0:1 tier would be a tier the renderer cannot honour.
     * 4.5:1 subsumes 3.0:1, so holding everything to the text threshold
     * satisfies both.
     */
    public const CONTRAST_MIN = 4.5;

    /**
     * Largest HSL-lightness distance the row-highlight fill may sit from the
     * terminal's own background: enough to read as a band, small enough that
     * the shift costs the text on top of it very little contrast.
     *
     * A MAXIMUM, not a constant — {@see highlight()} shrinks it in
     * {@see HIGHLIGHT_STEP} decrements, to zero if it has to, whenever the shift
     * would put CONTRAST_MIN out of reach for the text painted on the fill.
     */
    private const HIGHLIGHT_SHIFT = 0.08;

    /** Granularity {@see highlight()} gives back its shift in. */
    private const HIGHLIGHT_STEP = 0.02;

    /**
     * How far {@see legible()} moves a token's HSL lightness per step. 0.05 is
     * 20 steps to the extreme — fine enough that the nudge is invisible when it
     * is small, coarse enough that the loop is at most 20 contrast measurements
     * for a token that needs the whole range.
     */
    private const LEGIBILITY_STEP = 0.05;

    public function __construct(
        public readonly string $name,
        public readonly ShineTheme $markdown,
        public readonly Color $border,
        public readonly Color $userLabel,
        public readonly Color $assistantLabel,
        public readonly Color $systemLabel,
        public readonly Color $shellForeground,
        public readonly Color $shellMuted,
        public readonly Color $shellPrimary,
        public readonly Color $shellSuccess,
        public readonly Color $shellWarning,
        public readonly Color $shellError,
        public readonly Color $shellInfo,
        public readonly Color $shellSeparator,
    ) {}

    /**
     * Contrast ratio between two colours, WCAG 2.1 §1.4.3: the lighter
     * relative luminance plus 0.05 over the darker plus 0.05, so 1.0 is
     * "identical" and 21.0 is black on white.
     *
     * Public because it is the shell's own legibility contract, asserted
     * directly by the contrast suite rather than inferred from which token a
     * call site happens to name.
     */
    public static function contrast(Color $a, Color $b): float
    {
        $la = $a->luminance();
        $lb = $b->luminance();

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @throws \InvalidArgumentException When $name isn't one of {@see names()}
     */
    public static function byName(string $name): self
    {
        $canonical = self::canonicalize($name);
        if ($canonical === null) {
            throw new \InvalidArgumentException(
                "Unknown theme '{$name}'. Available themes: " . implode(', ', self::NAMES) . '.'
            );
        }

        if ($canonical === 'adaptive') {
            return self::adaptive();
        }

        $sprinkles = match ($canonical) {
            'dark' => SprinklesTheme::dark(),
            'light' => SprinklesTheme::light(),
            'dracula' => SprinklesTheme::dracula(),
            'tokyoNight' => SprinklesTheme::tokyoNight(),
            'ansi' => SprinklesTheme::ansi(),
        };

        return self::pair($canonical, $sprinkles, ShineTheme::byName($canonical) ?? ShineTheme::ansi());
    }

    /**
     * The `dark`/`light` preset the attached terminal actually calls for, kept
     * under the name `adaptive` so the choice re-resolves on every read (and on
     * every launch) instead of being frozen at pick time.
     *
     * {@see SprinklesTheme::adaptive()} is deliberately NOT used: it resolves
     * only the chrome half, leaving the markdown half to be detected a second
     * time by a second rule, and its `COLORFGBG >= 8` heuristic disagrees with
     * {@see TerminalBackground::detect()} on the two indices that matter (see
     * that method). One detector, both halves.
     *
     * Note the two-stage answer this can give within one session, by design.
     * With `SUGARCRUSH_BACKGROUND` set there are no stages at all: the override
     * is the top tier of {@see TerminalBackground::isDark()} and outranks both
     * detection sources, so every frame from the first resolves against it and
     * the palette never moves. Only WITHOUT it do the two stages appear — the
     * first frames resolve against `COLORFGBG` because the OSC 11 query
     * `App::init()` sends has not been answered yet, and every frame after the
     * reply resolves against the terminal's real background. On a terminal
     * whose `COLORFGBG` is absent or lying, that shows up as the palette
     * correcting itself a beat after launch, which is the intended trade: a
     * synchronous read would mean blocking the first frame on a query some
     * terminals never answer at all.
     */
    public static function adaptive(): self
    {
        return TerminalBackground::isDark()
            ? self::pair('adaptive', SprinklesTheme::dark(), ShineTheme::dark())
            : self::pair('adaptive', SprinklesTheme::light(), ShineTheme::light());
    }

    /**
     * Project a {@see SprinklesTheme} onto the tokens this app paints with,
     * every one of them checked for legibility against the colour the terminal
     * ACTUALLY paints behind the frame ({@see TerminalBackground::color()}).
     *
     * The check is not belt-and-braces, it is the point. Measured against each
     * palette's own `background` token, every `border` in the monorepo sits
     * between 1.19:1 (`light`) and 1.56:1 (`dracula`) — deliberately so, because
     * lipgloss `border` is a divider drawn INSIDE a filled panel, where 1.5:1
     * reads as a seam. Nothing in this shell fills a panel: the menu dropdown
     * is spliced over the composed frame and the chat box is drawn straight
     * onto it, so every one of those borders is seen against the terminal's own
     * background and 1.5:1 reads as absent. That is the bug reported three
     * times ("foreground matchs background color so invis"), and projecting
     * `border` straight through would have reproduced it in five palettes
     * instead of one.
     *
     * One threshold for every token, not a text/chrome split — see
     * {@see CONTRAST_MIN} for the measurement that ruled the split out.
     */
    private static function pair(string $name, SprinklesTheme $sprinkles, ShineTheme $markdown): self
    {
        // Every token is measured against the row-highlight FILL, not against
        // the bare terminal background — because the fill is the harder of the
        // two and clearing it clears both. The fill is the background moved
        // TOWARD the side the foregrounds live on, so for any colour that
        // clears CONTRAST_MIN against the fill, the bare background is strictly
        // further away and therefore strictly higher-contrast. Measuring
        // against the background instead would leave a token at exactly 4.5:1
        // failing inside a highlighted row, which is the one place in the shell
        // that paints its own background.
        //
        // ONE direction, computed from the terminal's background and shared by
        // both — this is the bug that measurement caught. Letting `legible()`
        // recompute the direction from the fill flipped it on a #808080
        // terminal (black out-contrasts white against the background, white
        // out-contrasts black against the darkened fill), so every token was
        // nudged toward white and scored 3.39-3.95:1 against the background it
        // was actually seen on.
        $background = TerminalBackground::color();
        $lighten = self::towardsWhite($background);
        $fill = self::highlight($background, $lighten);

        return new self(
            name: $name,
            markdown: $markdown,
            border: self::legible($sprinkles->border, $fill, $lighten),
            userLabel: self::legible($sprinkles->primary, $fill, $lighten),
            assistantLabel: self::legible($sprinkles->secondary, $fill, $lighten),
            systemLabel: self::legible($sprinkles->muted, $fill, $lighten),
            shellForeground: self::legible($sprinkles->foreground, $fill, $lighten),
            shellMuted: self::legible($sprinkles->muted, $fill, $lighten),
            shellPrimary: self::legible($sprinkles->primary, $fill, $lighten),
            shellSuccess: self::legible($sprinkles->success, $fill, $lighten),
            shellWarning: self::legible($sprinkles->warning, $fill, $lighten),
            shellError: self::legible($sprinkles->error, $fill, $lighten),
            shellInfo: self::legible($sprinkles->info, $fill, $lighten),
            // A row-highlight FILL, not a glyph colour, so it has no contrast
            // threshold of its own — what has to be legible is whatever is
            // painted on top of it, which is why the contrast suite tracks the
            // active SGR background rather than assuming the terminal's.
            shellSeparator: $fill,
        );
    }

    /**
     * `$preferred` if it already clears {@see CONTRAST_MIN} against
     * `$background`, else the same colour moved along its own HSL lightness
     * axis, away from the background, until it does.
     *
     * Nudging rather than substituting is what keeps a theme looking like
     * itself: `dracula`'s dropdown border stays a dracula slate, just light
     * enough to see. The earlier draft of this method escalated to OTHER tokens
     * of the same palette (`border` -> `muted` -> `foreground`) and measurement
     * killed it: under `light` on a white terminal both `warning` (2.06:1) and
     * `info` (2.66:1) failed and BOTH landed on `foreground`, so the four
     * provenance badges that exist to be told apart collapsed to three colours.
     * Moving each token along its own hue cannot cause that collision.
     *
     * Direction is chosen by measuring both extremes rather than by
     * {@see Color::isDark()}, whose 0.5 threshold answers a different question:
     * #808080 has luminance 0.2158, so isDark() calls it dark and would send
     * every token toward white, at 3.95:1, when black scores 5.32:1 on that
     * same background.
     *
     * Termination and the floor are the same fact: {@see Color::lighten()} and
     * {@see Color::darken()} clamp HSL lightness at 1.0 and 0.0, so amount 1.0
     * is #ffffff or #000000 for ANY input hue, and `$lighten` names whichever
     * of those two measures better against the TERMINAL's background. Those two
     * are equal only at background luminance 0.1791, where both measure
     * 4.583:1, and the better one rises monotonically away from there — so the
     * last step always clears CONTRAST_MIN (4.5) against the background. It
     * clears it against `$background` here too, because {@see highlight()} only
     * ever hands over a fill the extreme can still reach. That total guarantee
     * is what lets the contrast suite assert its threshold over palettes x
     * backgrounds rather than over a hand-picked pairing.
     *
     * @param Color $background What the glyph is actually seen against.
     * @param bool  $lighten    Direction, from {@see towardsWhite()} on the
     *                          TERMINAL background — never recomputed from
     *                          `$background`, see {@see pair()}.
     */
    private static function legible(Color $preferred, Color $background, bool $lighten): Color
    {
        if (self::contrast($preferred, $background) >= self::CONTRAST_MIN) {
            return $preferred;
        }

        for ($amount = self::LEGIBILITY_STEP; $amount < 1.0; $amount += self::LEGIBILITY_STEP) {
            $candidate = $lighten ? $preferred->lighten($amount) : $preferred->darken($amount);
            if (self::contrast($candidate, $background) >= self::CONTRAST_MIN) {
                return $candidate;
            }
        }

        return $lighten ? $preferred->lighten(1.0) : $preferred->darken(1.0);
    }

    /**
     * The row-highlight fill for a terminal painted `$background`: that same
     * colour, {@see HIGHLIGHT_SHIFT} of HSL lightness toward the side the
     * foregrounds live on.
     *
     * Derived from the TERMINAL's background rather than from the palette's
     * `separator` token, which is what the shell used to do by way of a literal
     * #232338. A palette `separator` is a shade of a background nothing paints:
     * `ansi`'s is `Color::ansi(0)`, so on a white terminal the selected row
     * painted a black band under text that had been chosen to be read on white.
     * A highlight has to be a shift of what is actually behind it.
     *
     * The shift YIELDS. It moves toward the same side the foregrounds do, so it
     * eats into the contrast available to the text on the row, and near the
     * crossover background (relative luminance 0.1791) there is barely any to
     * eat: the whole range there tops out at 4.583:1, and 4.5:1 needs the fill
     * to stay under luminance 0.1833 when lightening. So the shift is stepped
     * back until the direction's extreme can still reach CONTRAST_MIN on it,
     * which at shift 0 it always can (the fill is then the background itself).
     * A mid-grey terminal therefore gets legible rows and a faint-to-absent
     * highlight, rather than a clear band of unreadable text.
     */
    private static function highlight(Color $background, bool $lighten): Color
    {
        $extreme = $lighten ? Color::rgb(255, 255, 255) : Color::rgb(0, 0, 0);

        for ($shift = self::HIGHLIGHT_SHIFT; $shift > 0.0; $shift -= self::HIGHLIGHT_STEP) {
            $fill = $lighten ? $background->lighten($shift) : $background->darken($shift);
            if (self::contrast($extreme, $fill) >= self::CONTRAST_MIN) {
                return $fill;
            }
        }

        return $background;
    }

    /**
     * Whether white out-contrasts black against `$background` — i.e. which way
     * "away from the background" points. True for every background below
     * relative luminance 0.1791.
     */
    private static function towardsWhite(Color $background): bool
    {
        return self::contrast(Color::rgb(255, 255, 255), $background)
            >= self::contrast(Color::rgb(0, 0, 0), $background);
    }

    public static function default(): self
    {
        return self::byName('dark');
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    private static function canonicalize(string $name): ?string
    {
        foreach (self::NAMES as $canonical) {
            if (strtolower($canonical) === strtolower($name)) {
                return $canonical;
            }
        }

        return null;
    }
}

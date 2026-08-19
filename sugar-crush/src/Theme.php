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
 * hardcoded 100 hex literals, 15 distinct values, and none of them consulted a
 * Theme (re-MEASURED at 6c1e51c8^ with
 * `git grep -oE "'#[0-9a-fA-F]{3,8}'" -- sugar-crush/src/Tui`, which is where
 * the 14-distinct/84-call-site figures this comment used to quote came
 * apart), so `/theme` moved the transcript and left the shell around it
 * exactly where it was. Those literals were also a single palette's
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
     * Smallest contrast ratio that still reads as a BAND rather than as the
     * terminal's own background, so a shift below it is no highlight at all.
     *
     * 1.06 rather than 1.05 on purpose: the contrast suite asserts the fill is
     * distinguishable at > 1.05:1, and a constant equal to that assertion would
     * make the assertion a restatement of this line instead of an independent
     * check of it.
     */
    private const HIGHLIGHT_MIN_CONTRAST = 1.06;

    /**
     * How far {@see project()} moves a token's HSL lightness per step. 0.05 is
     * 20 steps to the extreme — fine enough that the nudge is invisible when it
     * is small, coarse enough that the loop is at most 20 contrast measurements
     * for a token that needs the whole range.
     */
    private const LEGIBILITY_STEP = 0.05;

    /**
     * {@see pair()}'s memo: projected themes by (palette, terminal background).
     * Static because both inputs are process-wide and the projection is a pure
     * function of them — see the comment in `pair()` for the key and the
     * measurement that made it worth having.
     *
     * @var array<string, self>
     */
    private static array $projected = [];

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
        // Every token is measured against BOTH surfaces it can be seen on: the
        // bare terminal background and the row-highlight fill. Neither one
        // implies the other, which is the correction W3 needed. The earlier
        // draft measured the fill alone, on the claim that a fill shifted
        // toward the foregrounds is always the harder of the two — false for
        // any token sitting on the far side of the background from the
        // foregrounds, because the shift then moves the fill AWAY from it.
        // MEASURED at CONTRAST_MIN 3.0: dracula's `warning` #ffb86c on a
        // #808080 terminal cleared the darkened fill at 3.12:1 and measured
        // 2.32:1 against the background it is mostly seen on. Requiring both is
        // one extra contrast() per candidate step and needs no invariant.
        //
        // ONE direction, computed from the terminal's background and shared by
        // both — this is the bug that measurement caught. Letting the projection
        // recompute the direction from the fill flipped it on a #808080
        // terminal (black out-contrasts white against the background, white
        // out-contrasts black against the darkened fill), so every token was
        // nudged toward white and scored 3.39-3.95:1 against the background it
        // was actually seen on.
        $background = TerminalBackground::color();

        // MEMOISED because the projection is not free and `src/Tui/` asks for it
        // 15 times per frame (`Tui\Renderer` at four sites, then once per pane).
        // MEASURED on this host, 200 iterations each: 0.570 ms per uncached
        // `byName('dracula')` — up to 11 tokens x 21 nudge steps x 2 contrast()
        // each — so ~8.6 ms of a frame that has 16 ms to make 60fps, against
        // 0.165 ms on a hit. The 0.165 is NOT this memo: it is
        // `ShineTheme::byName()` building the markdown half, which sits upstream
        // of here (`SprinklesTheme::dracula()` alone is 0.009 ms). Memoising
        // that too belongs in candy-shine.
        //
        // The key is every input the result depends on: the palette by its own
        // source colours (which is what makes `adaptive` safe — one name, two
        // palettes), and the terminal background. The background's palette SLOT is
        // in the key defensively, not because anything observable turns on it
        // today: it would matter only where `shellSeparator` is the background
        // itself, and MEASURED, `highlight()` never falls back that far for any
        // of the nine backgrounds the contrast suite uses, nor for five more
        // greys probed off-suite (#b4b4b4 #404040 #999999 #1f1f1f #e0e0e0) —
        // dropping this one
        // line from the key moves no test in the suite. It is a line of
        // insurance against #000000-the-slot and #000000-the-value sharing an
        // entry if that fallback ever becomes reachable. The markdown
        // half needs no key of its own: both call sites pick it in the same
        // branch that picks the palette, so the palette colours already separate
        // them. Nothing here reads the clock or the environment, so a hit is
        // byte-identical to a miss.
        $key = implode('|', [
            $name,
            $background->toHex(),
            $background->ansiIndex ?? -1,
            $sprinkles->background->toHex(),
            $sprinkles->foreground->toHex(),
            $sprinkles->primary->toHex(),
            $sprinkles->secondary->toHex(),
            $sprinkles->muted->toHex(),
            $sprinkles->border->toHex(),
            $sprinkles->success->toHex(),
            $sprinkles->warning->toHex(),
            $sprinkles->error->toHex(),
            $sprinkles->info->toHex(),
        ]);
        if (isset(self::$projected[$key])) {
            return self::$projected[$key];
        }

        $lighten = self::towardsWhite($background);
        $fill = self::highlight($background, $lighten);

        // Which projected colour each source colour has taken, so two tokens
        // that started as DIFFERENT palette colours cannot end as one. Threaded
        // by reference through the token list in declaration order, so the
        // result is a pure function of (palette, background) and does not
        // depend on which token asked first at run time.
        $claimed = [];

        return self::$projected[$key] = new self(
            name: $name,
            markdown: $markdown,
            border: self::project($sprinkles->border, $background, $fill, $lighten, $claimed),
            userLabel: self::project($sprinkles->primary, $background, $fill, $lighten, $claimed),
            assistantLabel: self::project($sprinkles->secondary, $background, $fill, $lighten, $claimed),
            systemLabel: self::project($sprinkles->muted, $background, $fill, $lighten, $claimed),
            shellForeground: self::project($sprinkles->foreground, $background, $fill, $lighten, $claimed),
            shellMuted: self::project($sprinkles->muted, $background, $fill, $lighten, $claimed),
            shellPrimary: self::project($sprinkles->primary, $background, $fill, $lighten, $claimed),
            shellSuccess: self::project($sprinkles->success, $background, $fill, $lighten, $claimed),
            shellWarning: self::project($sprinkles->warning, $background, $fill, $lighten, $claimed),
            shellError: self::project($sprinkles->error, $background, $fill, $lighten, $claimed),
            shellInfo: self::project($sprinkles->info, $background, $fill, $lighten, $claimed),
            // A row-highlight FILL, not a glyph colour, so it has no contrast
            // threshold of its own — what has to be legible is whatever is
            // painted on top of it, which is why the contrast suite tracks the
            // active SGR background rather than assuming the terminal's.
            shellSeparator: $fill,
        );
    }

    /**
     * `$preferred` if it already clears {@see CONTRAST_MIN} on both surfaces it
     * is seen on, else the same colour moved along its own HSL lightness axis,
     * away from the background, until it does — and then a step further for as
     * long as some other palette colour has already taken the result.
     *
     * Nudging rather than substituting is what keeps a theme looking like
     * itself: `dracula`'s dropdown border stays a dracula slate, just light
     * enough to see. The earlier draft of this method escalated to OTHER tokens
     * of the same palette (`border` -> `muted` -> `foreground`) and measurement
     * killed it: under `light` on a white terminal both `warning` (2.06:1) and
     * `info` (2.66:1) failed and BOTH landed on `foreground`, so the four
     * provenance badges that exist to be told apart collapsed to three colours.
     *
     * Moving each token along its own hue does not cause THAT collision, but on
     * its own it causes a worse one, which is what `$claimed` is for. A nudge
     * ends where the threshold is first met, so two different source colours
     * whose ranges end in the same place both land there: MEASURED before this
     * pass existed, `ansi` on a #ffffff terminal projected `border`/`muted`
     * (`Color::ansi(8)`, #7f7f7f) and `foreground` (`ansi(7)`, #bfbfbf) onto one
     * byte-identical #666666, so the menu dropdown's box lines were exactly its
     * item text — the user's first report, "difficult to tell at a glance where
     * the menu listing txt starts/ends", reproduced. Stepping on is free:
     * every further step is further from BOTH surfaces, so it cannot cost
     * legibility, only faithfulness to the palette.
     *
     * Distinctness is asserted as BYTES, not as a contrast ratio between the
     * two tokens, because most role pairs differ in hue at near-equal
     * luminance and {@see contrast()} cannot see hue at all: `dark`'s projected
     * `error` #f5a0a0 and `info` #87c0d0 measure 1.006:1 against each other and
     * are not remotely the same colour. A separation ratio would demand a
     * lightness split that these palettes deliberately do not have.
     *
     * Source colours that are EQUAL stay equal — `$claimed` is keyed by the
     * source hex, so `ansi`'s `border` and `muted` (both `ansi(8)`) still
     * project to one colour, and one that is still a palette slot. Inventing a
     * difference the palette's author did not write is not this method's call.
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
     * clears it against `$fill` too, because {@see highlight()} only ever hands
     * over a fill the extreme can still reach. That total guarantee is what
     * lets the contrast suite assert its threshold over palettes x backgrounds
     * rather than over a hand-picked pairing — and it is a guarantee about
     * LEGIBILITY only. Distinction yields to it, see the fallback below.
     *
     * @param Color                $background The terminal's own background.
     * @param Color                $fill       The row-highlight fill the same
     *                                         glyph is seen on inside a
     *                                         selected row. Checked alongside
     *                                         `$background`, not instead of it
     *                                         — see {@see pair()} for the
     *                                         measurement that killed the "the
     *                                         fill is always harder" invariant.
     * @param bool                 $lighten    Direction, from
     *                                         {@see towardsWhite()} on the
     *                                         TERMINAL background — never
     *                                         recomputed from either surface,
     *                                         see {@see pair()}.
     * @param array<string,string> $claimed    Projected hex => the source hex
     *                                         that took it, mutated in place.
     */
    private static function project(
        Color $preferred,
        Color $background,
        Color $fill,
        bool $lighten,
        array &$claimed,
    ): Color {
        $source = $preferred->toHex();
        $legible = null;

        foreach (self::steps($preferred, $lighten) as $candidate) {
            if (!self::clears($candidate, $background, $fill)) {
                continue;
            }

            // The first legible step is the one that keeps the theme looking
            // most like itself, so it is what we hold on to and what we fall
            // back to. Later steps are only reached to break a collision, and
            // every one of them is FURTHER from both surfaces, so stepping on
            // never costs legibility.
            $legible ??= $candidate;

            $hex = $candidate->toHex();
            if (($claimed[$hex] ?? $source) === $source) {
                $claimed[$hex] = $source;

                return $candidate;
            }
        }

        // Legibility outranks distinction: a background could leave a range too
        // narrow to hold every role apart, and there the shell keeps the
        // readable colour and accepts the collision rather than the reverse.
        // MEASURED by replaying `steps()`/`clears()` off-suite, no case reaches
        // it — five palettes x fourteen distinct backgrounds (the contrast
        // suite's nine plus #b4b4b4 #404040 #999999 #1f1f1f #e0e0e0), zero
        // fallbacks, every distinct source colour held distinct. So this is the
        // ORDER OF PRECEDENCE written down,
        // not a path with measured traffic on it; a palette or a background that
        // needs it will find it, and the contrast tests rather than the
        // distinctness test are the ones that must still hold when it does.
        $out = $legible ?? self::extreme($preferred, $lighten);
        $claimed[$out->toHex()] ??= $source;

        return $out;
    }

    /**
     * `$preferred`, then every {@see LEGIBILITY_STEP} nudge of it away from the
     * background, ending at the extreme (#ffffff or #000000, which
     * {@see Color::lighten()}/{@see Color::darken()} clamp to for any hue).
     *
     * @return list<Color>
     */
    private static function steps(Color $preferred, bool $lighten): array
    {
        $steps = [$preferred];
        for ($amount = self::LEGIBILITY_STEP; $amount < 1.0; $amount += self::LEGIBILITY_STEP) {
            $steps[] = $lighten ? $preferred->lighten($amount) : $preferred->darken($amount);
        }
        $steps[] = self::extreme($preferred, $lighten);

        return $steps;
    }

    private static function extreme(Color $preferred, bool $lighten): Color
    {
        return $lighten ? $preferred->lighten(1.0) : $preferred->darken(1.0);
    }

    /**
     * Whether `$colour` reaches {@see CONTRAST_MIN} on both surfaces it is seen
     * on: the terminal's own background, and the row-highlight fill.
     */
    private static function clears(Color $colour, Color $background, Color $fill): bool
    {
        return self::contrast($colour, $background) >= self::CONTRAST_MIN
            && self::contrast($colour, $fill) >= self::CONTRAST_MIN;
    }

    /**
     * The row-highlight fill for a terminal painted `$background`: that same
     * colour, {@see HIGHLIGHT_SHIFT} of HSL lightness AWAY from the side the
     * foregrounds live on where that is a visible band, toward them where it is
     * not (a pure #000000 or #ffffff terminal, where the away step clamps back
     * to the background).
     *
     * Derived from the TERMINAL's background rather than from the palette's
     * `separator` token, which is what the shell used to do by way of a literal
     * #232338. A palette `separator` is a shade of a background nothing paints:
     * `ansi`'s is `Color::ansi(0)`, so on a white terminal the selected row
     * painted a black band under text that had been chosen to be read on white.
     * A highlight has to be a shift of what is actually behind it.
     *
     * AWAY-FIRST, and that direction is the fix for a measured collapse, not a
     * preference. A fill shifted TOWARD the foregrounds eats the contrast the
     * text on that row has left, and near the crossover background (relative
     * luminance 0.1791) there is barely any to eat: the whole range there tops
     * out at 4.583:1. MEASURED with a toward-fill on a #666666 terminal, the
     * token bar landed at luminance 0.976, which only #ffffff clears, so ten of
     * `dark`'s eleven tokens projected onto white and the shell lost every role
     * distinction it has. Away-first leaves the bar on the bare background
     * (0.773) and all five palettes keep every distinct source colour distinct
     * on all nine backgrounds the contrast suite uses. It costs the band its
     * conventional direction — a selected row on a dark terminal reads darker
     * than the terminal, not lighter — which is the trade taken knowingly.
     *
     * The shift still YIELDS, for the toward fallback: it is stepped back until
     * the direction's extreme can still reach CONTRAST_MIN on the fill, which
     * at shift 0 it always can (the fill is then the background itself). A
     * #ffffff terminal therefore gets legible rows and a faint highlight rather
     * than a clear band of unreadable text.
     */
    private static function highlight(Color $background, bool $lighten): Color
    {
        $extreme = $lighten ? Color::rgb(255, 255, 255) : Color::rgb(0, 0, 0);

        // AWAY from the foregrounds first, TOWARD them only when away buys no
        // band at all. Away costs the text on the row nothing — it moves the
        // fill further from every token — while toward eats the row's whole
        // contrast budget. MEASURED, projecting all five palettes over the
        // backgrounds the contrast suite uses (eight at the time, nine now):
        // a toward-fill on a #666666 terminal put the token bar at luminance
        // 0.976, which only
        // #ffffff clears, so ten of `dark`'s eleven tokens collapsed onto white
        // and the shell lost every role distinction it has (the user's first
        // report: "difficult to tell where the menu listing txt starts/ends").
        // Away-first puts the bar back on the bare background (0.773) and the
        // same projection keeps 8 of 8 distinct source colours distinct.
        foreach ([!$lighten, $lighten] as $direction) {
            for ($shift = self::HIGHLIGHT_SHIFT; $shift > 0.0; $shift -= self::HIGHLIGHT_STEP) {
                $fill = $direction ? $background->lighten($shift) : $background->darken($shift);
                // A band that is not a band is no use: lighten() on #ffffff
                // and darken() on #000000 clamp straight back to the
                // background, and on #fafafa the away step reaches only
                // #ffffff, 1.04:1 — which is why this is a RATIO and not a
                // hex comparison. Either way the away direction falls through
                // to the toward one, as it did before away-first existed.
                if (self::contrast($fill, $background) < self::HIGHLIGHT_MIN_CONTRAST) {
                    continue;
                }
                if (self::contrast($extreme, $fill) >= self::CONTRAST_MIN) {
                    return $fill;
                }
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

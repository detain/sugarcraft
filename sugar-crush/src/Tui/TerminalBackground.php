<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Util\Color;

/**
 * Whether the terminal this process is attached to paints on a dark
 * background — the single fact the `adaptive` theme ({@see \SugarCraft\Crush\Theme::byName()})
 * needs in order to pick a readable palette without the user knowing to run
 * `/theme light`.
 *
 * Two sources, in precedence order:
 *
 *   1. **The terminal's own answer.** candy-core can ask over OSC 11
 *      ({@see \SugarCraft\Core\Cmd::requestBackgroundColor()}); the reply comes
 *      back as a {@see BackgroundColorMsg}, whose `isDark()` is a real
 *      luminance test on real RGB. Feed it here via {@see observe()} and every
 *      later theme resolution uses it. This is deliberately a process-scoped
 *      memo rather than model state: "what colour is the terminal I am attached
 *      to" is a property of the process, not of any one {@see \SugarCraft\Crush\Chat},
 *      and the answer arrives asynchronously long after the theme name was
 *      chosen — the same reason candy-sprinkles' Renderer carries a
 *      `hasDarkBackground` flag set "once the program has the answer".
 *
 *   2. **The environment.** No OSC 11 reply (not asked yet, terminal doesn't
 *      answer, non-TTY): fall back to {@see detect()}, which is a pure function
 *      of the environment and is what makes this testable.
 *
 * Dark is the fallback because every terminal default this project targets is
 * dark, and light-on-dark text on a dark terminal is the failure mode that
 * merely looks unstyled, whereas the reverse is unreadable.
 */
final class TerminalBackground
{
    /**
     * Explicit user override, checked before `COLORFGBG`. Accepts `light` or
     * `dark`; anything else is ignored. Mirrors the escape-hatch convention the
     * rest of the shell already uses (`SUGARCRUSH_DISABLE_MOUSE` and friends) —
     * detection heuristics are guesses, and a guess needs an off switch.
     */
    public const ENV_OVERRIDE = 'SUGARCRUSH_BACKGROUND';

    /**
     * The terminal's own OSC 11 answer for this process, or null while we
     * haven't been told. Static because the fact is per-process; {@see forget()}
     * exists so a test can put it back.
     */
    private static ?bool $observed = null;

    /** Static-only: this is a detector, not a value object. */
    private function __construct() {}

    /**
     * Record the terminal's OSC 11 reply.
     *
     * Intentionally not yet called from the live shell — the theme is fully
     * functional on the environment path below, and this is the strictly-better
     * upgrade waiting on its two call sites in the Program's root model
     * ({@see \SugarCraft\Crush\App\App}): batch
     * `\SugarCraft\Core\Cmd::requestBackgroundColor()` into `init()`, and have
     * `update()` call this for a {@see BackgroundColorMsg} and then fall through
     * to the hosted chat unchanged. Both are additive; neither changes any
     * existing branch.
     *
     * One shape note for whoever does it: `update()` dispatches through a
     * `match (true)`, and a `match` arm is an expression while this method
     * returns void, so the arm cannot call it directly. Give App a small private
     * `void` helper that calls this and returns the fall-through result, and
     * point the arm at that.
     */
    public static function observe(BackgroundColorMsg $msg): void
    {
        self::$observed = $msg->isDark();
    }

    /** The observed OSC 11 answer, or null if the terminal hasn't told us. */
    public static function observed(): ?bool
    {
        return self::$observed;
    }

    /** Drop the observed answer (tests; also correct after a terminal swap). */
    public static function forget(): void
    {
        self::$observed = null;
    }

    /**
     * The effective answer: the terminal's own reply when we have it, the
     * environment's guess otherwise.
     *
     * @param array<string,string>|null $env Defaults to a snapshot of getenv().
     */
    public static function isDark(?array $env = null): bool
    {
        return self::$observed ?? self::detect($env);
    }

    /**
     * Pure environment-only detection, mirroring the injectable-env shape of
     * {@see \SugarCraft\Core\Util\ColorProfile::detect()} so it can be tested
     * without touching the real environment.
     *
     * `COLORFGBG` is `foreground;background` (xterm/rxvt also emit a
     * three-field `fg;faint;bg` form, so the *last* field is the background).
     * The background field is an xterm-256 palette index, which
     * {@see Color::ansi256()} turns into real RGB and {@see Color::isDark()}
     * then puts through the same WCAG relative-luminance test the OSC 11 path
     * uses on the terminal's own answer — so the two sources agree by
     * construction instead of by coincidence. Anything that is not a palette
     * index at all (the literal `default`, an empty or malformed value, an
     * out-of-range number) falls back to dark.
     *
     * Reading the index rather than allow-listing 7 and 15 is what makes a
     * 256-colour light terminal work: `COLORFGBG=0;255` is the top of the
     * greyscale ramp (#eeeeee) and used to read as dark, as did every pale
     * colour in the 6x6x6 cube. The two system white slots still come out
     * light (7 is #e5e5e5, 15 is #ffffff) and bright-black still comes out dark
     * (8 is #7f7f7f, luminance 0.21), so no previously-correct answer moves.
     *
     * That last pair is also why this deliberately differs from
     * {@see \SugarCraft\Sprinkles\Theme::adaptive()}, which calls any index
     * `>= 8` light: that misreads plain white (7) as dark and bright-black (8)
     * — a dark grey — as light, which are exactly the two cases the flag exists
     * to get right. It is unusable here for a second reason too: it
     * `return self::dark()` whenever the value does not split into exactly two
     * fields, so it cannot read rxvt's three-field form at all. Nothing here
     * calls `SprinklesTheme::adaptive()`, so the two rules never have to agree.
     *
     * @param array<string,string>|null $env Defaults to a snapshot of getenv().
     */
    public static function detect(?array $env = null): bool
    {
        $env ??= self::defaultEnv();

        $override = strtolower(trim($env[self::ENV_OVERRIDE] ?? ''));
        if ($override === 'light') {
            return false;
        }
        if ($override === 'dark') {
            return true;
        }

        $raw = trim($env['COLORFGBG'] ?? '');
        if ($raw === '') {
            return true;
        }

        $fields = explode(';', $raw);
        $background = trim((string) end($fields));
        if (!ctype_digit($background)) {
            return true;
        }

        $index = (int) $background;
        // Range-check before the call rather than catching after it:
        // Color::ansi256() throws outside 0-255, and this runs on the theme
        // resolution path reached from view(), where a throw would take the
        // Program down with the terminal still in raw mode.
        if ($index > 255) {
            return true;
        }

        return Color::ansi256($index)->isDark();
    }

    /** @return array<string,string> */
    private static function defaultEnv(): array
    {
        $env = [];
        foreach ([self::ENV_OVERRIDE, 'COLORFGBG'] as $key) {
            $value = getenv($key);
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}

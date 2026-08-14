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
 * Three sources, in precedence order:
 *
 *   1. **The user's explicit override.** `SUGARCRUSH_BACKGROUND=light|dark`
 *      ({@see ENV_OVERRIDE}) is a statement, not a measurement, so it outranks
 *      even the terminal's own answer. It has to: nearly every terminal worth
 *      running this in answers OSC 11, so ranking the measurement first would
 *      make the documented escape hatch inert in exactly the common case —
 *      and the escape hatch is what a user reaches for when the measurement
 *      is right about the terminal and wrong about what they can read (a
 *      translucent background over a light desktop, a screen-sharing session,
 *      a colour-vision preference).
 *
 *   2. **The terminal's own answer.** candy-core asks over OSC 11
 *      ({@see \SugarCraft\Core\Cmd::requestBackgroundColor()}, batched into
 *      {@see \SugarCraft\Crush\App\App::init()}); the reply comes back as a
 *      {@see BackgroundColorMsg}, whose `isDark()` is a real luminance test on
 *      real RGB, and {@see \SugarCraft\Crush\App\App::update()} feeds it here
 *      via {@see observe()}. This is deliberately a process-scoped memo rather
 *      than model state: "what colour is the terminal I am attached to" is a
 *      property of the process, not of any one {@see \SugarCraft\Crush\Chat},
 *      and the answer arrives asynchronously long after the theme name was
 *      chosen — the same reason candy-sprinkles' Renderer carries a
 *      `hasDarkBackground` flag set "once the program has the answer".
 *
 *   3. **The environment.** No OSC 11 reply (not asked yet, terminal doesn't
 *      answer, non-TTY, or the one-shot `-p` path, which runs no Program and
 *      so never asks): fall back to `COLORFGBG` via {@see detect()}, which is a
 *      pure function of the environment and is what makes this testable.
 *
 * Dark is the fallback because every terminal default this project targets is
 * dark, and light-on-dark text on a dark terminal is the failure mode that
 * merely looks unstyled, whereas the reverse is unreadable.
 */
final class TerminalBackground
{
    /**
     * Explicit user override, checked before BOTH detection sources — the
     * terminal's own OSC 11 answer as well as `COLORFGBG`. Accepts `light` or
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
     * Called from the live shell by
     * {@see \SugarCraft\Crush\App\App::observeBackground()}, the arm
     * `App::update()` routes a {@see BackgroundColorMsg} to — the reply to the
     * query `App::init()` batches in. Nothing else calls it in production; the
     * one-shot `-p`/`run` path runs no Program, asks nothing, and stays on the
     * environment guess.
     *
     * Static, and therefore process-scoped rather than model state, on purpose:
     * `App` is immutable and rebuilt on every keystroke, so a field here would
     * have to be threaded back out through the returned model and would be lost
     * by any caller that drops it — while the fact being recorded ("the
     * terminal this process is attached to is dark") outlives every model
     * instance and is read from {@see \SugarCraft\Crush\Theme::adaptive()},
     * deep inside `view()`, where no model is in hand. {@see forget()} is the
     * matching reset, for tests and for a genuine terminal swap.
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
     * The effective answer: the user's explicit override when there is one, the
     * terminal's own reply when we have it, the environment's guess otherwise.
     *
     * The override is lifted ABOVE the observed reply rather than being left
     * inside {@see detect()} where it also lives. Before `observe()` was wired
     * the distinction was unobservable — nothing ever set `$observed`, so both
     * orderings behaved identically. Now that the live shell asks OSC 11 on
     * every launch, leaving the override underneath the reply would silently
     * retire `SUGARCRUSH_BACKGROUND` on every terminal that answers, which is
     * most of them. See the class docblock for why an override outranks a
     * measurement.
     *
     * {@see detect()} still checks the override itself, so the environment-only
     * path is unchanged and still a pure function of its argument.
     *
     * @param array<string,string>|null $env Defaults to a snapshot of getenv().
     */
    public static function isDark(?array $env = null): bool
    {
        // This is a NET ADDITION of one getenv() sweep per frame, not a saving.
        // Before observe() was wired, `self::$observed ?? self::detect($env)`
        // short-circuited once the OSC 11 reply landed and no getenv() ran at
        // all in that steady state — which is now the common state. Lifting the
        // override above the observed answer (see the docblock) is what makes an
        // environment read unconditional: an override that is only consulted
        // after the measurement is not an override. Two getenv() calls per frame
        // is the price of the escape hatch working on a terminal that answers.
        //
        // What resolving here rather than leaving it null for detect() to
        // resolve again does save is the SECOND sweep, on the pre-reply path
        // that actually reaches detect().
        $env ??= self::defaultEnv();

        return self::override($env) ?? self::$observed ?? self::detect($env);
    }

    /**
     * The explicit `SUGARCRUSH_BACKGROUND` answer, or null when it is unset or
     * holds anything other than `light`/`dark` — an unrecognised value falls
     * through to detection rather than pinning a palette on a typo.
     *
     * @param array<string,string> $env
     */
    private static function override(array $env): ?bool
    {
        return match (strtolower(trim($env[self::ENV_OVERRIDE] ?? ''))) {
            'light' => false,
            'dark' => true,
            default => null,
        };
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

        $override = self::override($env);
        if ($override !== null) {
            return $override;
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

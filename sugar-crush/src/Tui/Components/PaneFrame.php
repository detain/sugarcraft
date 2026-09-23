<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Sprinkles\Style;

/**
 * The 256-colour side-frame gradient for docked pane boxes (L3 of the
 * pane-docking feature — the user-facing ask was a frame whose top edge
 * holds the accent colour while the sides ease down into the neutral
 * border colour "over several rows", so the transition reads as gradient
 * rather than a hard two-tone seam).
 *
 * HOW IT WORKS ON THE BYTES. The pane components paint their frame through
 * the candy-sprinkles border walk, which resolves ONE flat SGR per side for
 * the whole run of a border — a flat colour per side is the most that
 * public API expresses. So the fade is composed after the walk: the frame is
 * rendered exactly as before, each body row is verified to carry the walk's
 * own left/right border runes verbatim, and only those edge runes are
 * re-spelled per row. A row whose shape deviates from the walk's contract
 * fails the whole frame back to today's bytes — all-or-nothing, never a
 * half-restyled frame.
 *
 * THE BYTE-IDENTITY LAW. Below 256-colour capability the frame comes back
 * byte-for-byte as {@see Style::render()} produced it: a 16-colour terminal
 * has no gradient to fade through, and an existing snapshot rendered on a
 * pipe (no TTY) must never shift under this feature. The same law covers an
 * unfocused pane, whose top and side colours are one and the same — the
 * fade would interpolate a colour into itself.
 *
 * THE ENDPOINTS ARE NOT INTERPOLATED. The first body row emits the top
 * colour and every row from the fade's end emits the side colour via their
 * own {@see Color::toFg()}, so palette-slot colours keep their `38;5;n`
 * spelling at the endpoints and only the interior rows ride the truecolour
 * blend — exactly the rows that need new colours to exist at all.
 *
 * THE FADE BUDGET. max(2, min(8, intdiv(height, 4))) rows: two stops is
 * the minimum a transition can be, eight keeps the effect a flourish rather
 * than a theme of its own on tall panes, and height/4 scales it with the
 * box. {@see gradientRows()} is public so the budget formula has ONE
 * definition and a test can straddle every clamp edge.
 */
final class PaneFrame
{
    /** Process-lifetime memo of the detected capability (a terminal's colour
     *  support is not renegotiated mid-session here). */
    private static ?ColorProfile $detected = null;

    private function __construct()
    {
    }

    /**
     * Render the frame `$st` would paint, fading each vertical side border
     * from the frame's top-border colour down to `$side` over
     * {@see gradientRows()} rows. `$profile` forces the capability tier for
     * the gradient's SGR spelling; the default is the live terminal
     * detection.
     */
    public static function render(Style $st, string $body, Color $side, ?ColorProfile $profile = null): string
    {
        $frame = $st->render($body);
        $profile ??= self::profile();

        // Below 256 colours there is nothing to fade through: hand back
        // today's bytes untouched (the byte-identity law, pinned by test).
        if (!$profile->supports256()) {
            return $frame;
        }

        $border = $st->getBorder();
        $top = $st->getBorderForeground();
        if ($border === null || $top === null) {
            return $frame;
        }
        if ($top->toHex() === $side->toHex()) {
            return $frame;
        }

        $rows = explode("\n", $frame);
        if (count($rows) < 3) {
            return $frame;
        }

        // The walk spells the side runes with the STYLE's own profile; that
        // exact string is the contract check for every row we re-colour.
        $styleProfile = $st->getColorProfile();
        $leftRune = $top->toFg($styleProfile) . $border->left . Ansi::reset();
        $rightRune = $top->toFg($styleProfile) . $border->right . Ansi::reset();
        $trim = strlen($leftRune) + strlen($rightRune);

        $stops = self::gradientRows(count($rows));
        for ($i = 1; $i < count($rows) - 1; $i++) {
            $row = $rows[$i];
            if (strlen($row) < $trim
                || !str_starts_with($row, $leftRune)
                || !str_ends_with($row, $rightRune)) {
                // The walk's output is not the shape we know how to restyle.
                // Fall back whole-frame rather than restyle a frame we do not
                // understand.
                return $frame;
            }

            $colour = self::fadeColour($top, $side, $i - 1, $stops);
            $sgr = $colour->toFg($profile);
            $rows[$i] = $sgr . $border->left . Ansi::reset()
                . substr($row, strlen($leftRune), strlen($row) - $trim)
                . $sgr . $border->right . Ansi::reset();
        }

        return implode("\n", $rows);
    }

    /**
     * How many body rows a side fade spans for a frame of `$height` rows:
     * at least 2 (a one-stop "fade" is a hard seam), at most 8 (a flourish,
     * not a gradient theme), scaled by height/4 between the clamps.
     */
    public static function gradientRows(int $height): int
    {
        return max(2, min(8, intdiv($height, 4)));
    }

    /**
     * The live terminal's colour capability, detected once per process.
     * A piped stdout (CI, tests, redirected frames) is NoTty — which keeps
     * every existing snapshot on today's bytes without any test-side setup.
     */
    public static function profile(): ColorProfile
    {
        return self::$detected ??= ColorProfile::detect(null, STDOUT);
    }

    /**
     * Injectable-env form of {@see profile()} for tests and any future
     * caller that must reason about a capability without living in it.
     *
     * @param array<string,string>|null $env
     */
    public static function detectProfile(?array $env = null, mixed $stdout = STDOUT): ColorProfile
    {
        /** @var resource|null $stdout */
        return ColorProfile::detect($env, $stdout);
    }

    public static function resetProfileCacheForTesting(): void
    {
        self::$detected = null;
    }

    /**
     * The colour for stop `$index` of a `$stops`-step fade: the first stop
     * IS the top colour, every stop from the last onwards IS the side
     * colour (endpoints spelled directly, never via the blend), and the
     * interior rows linearly interpolate RGB.
     */
    private static function fadeColour(Color $top, Color $side, int $index, int $stops): Color
    {
        if ($index <= 0) {
            return $top;
        }
        if ($index >= $stops - 1) {
            return $side;
        }
        return $top->blend($side, $index / ($stops - 1));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Theme;

/**
 * Renders the Agent View list pane — a scrollable, selectable list of all
 * active agents with their real-time status, current operation, elapsed
 * time, and token usage.
 *
 * Agent row format (left-to-right):
 *   [dot] name  [status]  operation…  elapsed  usage
 *
 * Colour coding mirrors AgentStatusBar, by {@see Theme} token:
 *   `shellSuccess`: working
 *   `shellWarning`: waiting
 *   `shellInfo`:    streaming
 *   `shellError`:   failed
 *   `shellMuted`:   completed / stopped
 *
 * The selected-agent highlight fills the row with the palette's `separator`
 * and keeps the status colour on top. It used to fill a literal #232338, which
 * is an inversion only on a dark terminal — on a light one it painted a dark
 * band under text chosen to be read on light.  Arrow-key navigation
 * is handled by the caller (KeyboardHandler / App state); this class only
 * renders the current selection state passed in.
 *
 * Mirrors charmbracelet/crush agent view list design.
 */
final class AgentViewPane
{
    /** Theme tokens matched to {@see AgentStatusBar}'s STATUS_TOKEN. */
    private const STATUS_TOKEN = [
        'working'   => 'shellSuccess',
        'waiting'   => 'shellWarning',
        'streaming' => 'shellInfo',
        'failed'    => 'shellError',
        'completed' => 'shellMuted',
        'stopped'   => 'shellMuted',
    ];

    /**
     * Cells this pane draws OUTSIDE the width {@see render()} is handed.
     *
     * `$width` goes to `Style::width()`, which sizes the CONTENT box; the
     * rounded border adds a column on each side and `padding(0, 1)` one more
     * on each side, so a finished row is `$width + CHROME_WIDTH` cells. That
     * is an invariant, not a narrow-terminal edge case -- measured at
     * `$width` = 20, 28, 30, 40, 43, 44, 58, 60, 80 and 98, populated list and
     * empty placeholder alike, it is `+4` in every one.
     *
     * It exists as a named constant because the two callers each used to
     * write their own literal for it and one of them wrote the wrong one:
     * {@see \SugarCraft\Crush\Renderer::renderAgentView()} subtracted 4 and
     * {@see \SugarCraft\Crush\Tui\Components\AgentDashboardPane::render()}
     * subtracted 2, having charged for the border and forgotten the padding.
     * Both now go through {@see contentWidth()}.
     */
    public const CHROME_WIDTH = 4;

    /**
     * Content width to hand {@see render()} for an OUTSIDE budget of
     * `$outerWidth` cells, floored at `$minimum`.
     *
     * `$minimum` stays the caller's to choose -- the in-transcript strip
     * floors at 40 and the full-pane dashboard at 20, and those floors are
     * about legibility, not geometry. Note what the floor costs: when
     * `$outerWidth` is under `$minimum + CHROME_WIDTH` the floor wins and the
     * rendered rows are wider than the budget after all. Nothing here can fix
     * that -- a pane cannot be both at least `$minimum` wide and narrower than
     * the terminal -- so the shell renderer's `clipWidth()` remains the
     * backstop for terminals that narrow, exactly as before.
     */
    public static function contentWidth(int $outerWidth, int $minimum): int
    {
        return max($minimum, $outerWidth - self::CHROME_WIDTH);
    }

    /**
     * Render the agent list pane.
     *
     * @param list<AgentDisplayState> $agents        Ordered list of agents to display.
     * @param int                     $selectedIndex Index of the currently-selected agent (passed
     *                                               from App state; -1 means no selection).
     * @param int                     $width        CONTENT columns for the pane -- the width of the
     *                                               box inside the border and padding, not the
     *                                               columns the finished pane occupies. A rendered
     *                                               row is `$width + CHROME_WIDTH` cells; callers
     *                                               working from an outside budget should reach
     *                                               this through {@see contentWidth()}.
     * @param int                     $maxRows      Maximum rows to render before clipping (longer
     *                                               lists are silently truncated at render time;
     *                                               scrolling is handled by the caller).
     */
    public static function render(array $agents, int $selectedIndex, int $width, int $maxRows, Theme $theme): string
    {
        // Early exit — empty list: show a tasteful placeholder inside the border.
        if ($agents === []) {
            $body = Style::new()
                ->foreground($theme->shellMuted)
                ->render('(no active agents)');

            return Style::new()
                ->border(Border::rounded()->withTitle(' agents '))
                ->padding(0, 1)
                ->width($width)
                ->render($body);
        }

        // ── Column-budget layout ─────────────────────────────────────────────
        // Right-side fixed columns (estimated; shrinks gracefully on narrow terms):
        //   sep(2) + elapsed(~7) + sep(2) + usage(~15)
        $fixedRight = 26;

        $rows = [];
        $count = min(count($agents), $maxRows);

        for ($i = 0; $i < $count; $i++) {
            $agent = $agents[$i];
            $isSelected = ($i === $selectedIndex);
            $statusColor = self::statusColor($agent->status, $theme);

            // Colour dot + name + status badge.
            $dot  = Style::new()->foreground($statusColor)->render("\u{25CF}");
            $name = $agent->name;

            // Right-side (elapsed + usage).
            $rightSection = $agent->elapsedDisplay() . '  ' . $agent->usageDisplay();

            // Truncate operation to fill remaining space.
            // name(~12) + dot(2) + sep(2) + status_bracket(~14) + sep(2) + right(~26)
            //
            // $width is a CELL budget, so the name has to be charged against it
            // in cells. strlen() charged BYTES, which meant an agent named with
            // any non-ASCII character silently bought less operation text than
            // an identically-wide ASCII name -- "abc" got 17 columns while
            // "abc" with an acute accent on the a got 16, for the same three
            // cells on screen.
            $opBudget = max(5, $width - Width::string($name) - 60);
            $operation = self::truncate($agent->operation, $opBudget);

            // Left section: dot + name + status + operation.
            $leftSection = "{$dot} {$name} [{$agent->status}]  {$operation}";

            if ($isSelected) {
                // Inverted highlight — dark background, status-colour foreground.
                $rowStyle = Style::new()
                    ->foreground($statusColor)
                    ->background($theme->shellSeparator);
            } else {
                $rowStyle = Style::new()
                    ->foreground($theme->shellMuted);
            }

            $rightStyle = Style::new()->foreground($theme->shellMuted);

            // Right-pad the left section so the right section lands at the edge.
            //
            // Width::padRight(), not str_pad(), for TWO reasons that both make
            // str_pad() the wrong tool here and only one of which is about
            // Unicode:
            //
            // 1. $rightSection's cost must be counted in cells, same argument
            //    as $opBudget above.
            // 2. $leftSection is STYLED -- $dot is a rendered Style, so the
            //    string carries SGR escape bytes. str_pad() counts those as
            //    occupied columns, so on a colour-capable terminal the measure
            //    overshot by the whole escape sequence and str_pad() returned
            //    the string untouched, i.e. the pad did nothing at all.
            //    Width::string() strips ANSI before measuring, so the pad is
            //    computed against what the terminal actually shows.
            //
            // padRight() is a no-op once the section already meets the width,
            // so this cannot widen a row past its budget.
            $leftPadded = Width::padRight($leftSection, $width - Width::string($rightSection) - 1);

            $rows[] = $rowStyle->render($leftPadded)
                . $rightStyle->render($rightSection);
        }

        $body = implode("\n", $rows);

        return Style::new()
            ->border(Border::rounded()->withTitle(' agents '))
            ->padding(0, 1)
            ->width($width)
            ->render($body);
    }

    /**
     * Resolve the colour for a given operational status string.
     * Falls back to the `completed` token for unknown statuses.
     */
    public static function statusColor(string $status, Theme $theme): Color
    {
        $token = self::STATUS_TOKEN[strtolower(trim($status))] ?? self::STATUS_TOKEN['completed'];

        return $theme->$token;
    }

    /**
     * Truncate $text to at most $maxWidth CELLS, appending "…" when it does
     * not fit. Returns the text verbatim when it already fits.
     *
     * The loop steps over {@see clusters()}, not over codepoints, and that is
     * the whole point of it. Summing {@see charWidth()} per codepoint scored
     * the family emoji U+1F468 ZWJ U+1F469 ZWJ U+1F467 as 6 cells where
     * {@see visualWidth()} scores the same string 2 -- the truncator and the
     * pad disagreeing about one string. The over-count on its own was
     * harmless (the loop under-filled and cut early; it could never over-run),
     * but cutting BETWEEN the codepoints of that sequence emitted a joiner
     * with nothing after it, which is a rendering hazard in its own right.
     * A cluster is now taken whole or not at all.
     */
    private static function truncate(string $text, int $maxWidth): string
    {
        if ($text === '' || $maxWidth <= 0) {
            return '';
        }

        $visualWidth = self::visualWidth($text);
        if ($visualWidth <= $maxWidth) {
            return $text;
        }

        $result = '';
        $w = 0;

        foreach (self::clusters($text) as $cluster) {
            $clusterWidth = self::visualWidth($cluster);
            if ($w + $clusterWidth > $maxWidth - 1) { // leave room for "…"
                break;
            }
            $result .= $cluster;
            $w += $clusterWidth;
        }

        return $result . "\u{2026}";
    }

    /**
     * Split $text into units {@see truncate()} may not cut apart: a base
     * codepoint plus everything that renders as part of the same glyph.
     *
     * Hand-rolled rather than delegated, for a version reason rather than a
     * taste one. {@see Width} is this class's width authority and it IS
     * grapheme-aware, but its splitter is private, and the intl function that
     * would replace this outright -- `grapheme_str_split()` -- is PHP 8.4+
     * while this tree targets 8.3. `grapheme_extract()` does ship with intl
     * on 8.3, but intl is not a declared dependency of sugar-crush or of
     * candy-core, so reaching for it would make the truncator behave one way
     * on a build that has it and another on a build that does not. This runs
     * on `preg_split('//u')`, which needs no extension at all and so cannot
     * fatal anywhere PHP itself runs.
     *
     * Four things join the unit before them:
     *
     * 1. anything following a zero-width joiner -- the joiner's entire job;
     * 2. any zero-width codepoint (combining mark, variation selector, the
     *    joiner itself), which has no cell of its own to be cut into;
     * 3. an emoji skin-tone modifier, U+1F3FB..U+1F3FF;
     * 4. a regional indicator following exactly one other regional indicator,
     *    which is how a flag is spelled -- "exactly one" because a run of four
     *    is two flags, not one.
     *
     * Widths still come from {@see charWidth()}/{@see visualWidth()}, so this
     * adds a segmentation rule and NOT a second width table -- the mistake
     * this class already made once, when its truncator carried a local width
     * table that disagreed with `Width`. What the grouping does to a TOTAL
     * differs by rule, and stating it of all four at once would be wrong.
     *
     * Neither of the two EMOJI rules -- 3 and 4 -- moves a total. On this
     * tree's PHP 8.3 `Width::string()` scores a flag 1+1 and a skin-toned
     * thumb 2+2, the same grouped or ungrouped, so for those two the grouping
     * changes what may be SPLIT and nothing else; measured over a ZWJ-free
     * alphabet, 60,000 random strings produced 0 cases where grouping moved
     * the total. Rule 1 is the opposite and deliberately so: the family emoji
     * is 2 cells grouped against 6 summed per codepoint, and closing exactly
     * that gap is why this function exists.
     *
     * The 1+1 and 2+2 figures are PHP 8.3's, not universal. On an 8.4 build
     * `Width` splits with `grapheme_str_split()` and the same flag measures 1
     * grouped against 2 summed, the same thumb 2 against 4 -- measured against
     * a simulated 8.4 path, not assumed.
     *
     * This is not full UAX #29 and does not claim to be. Hangul syllables,
     * Indic conjuncts and prepend marks are still split at codepoint
     * boundaries, exactly as they were before.
     *
     * @return list<string>
     */
    private static function clusters(string $text): array
    {
        $units = [];

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $last = $units === [] ? null : $units[array_key_last($units)];

            if ($last !== null && self::joinsPrevious($char, $last)) {
                $units[array_key_last($units)] = $last . $char;
                continue;
            }

            $units[] = $char;
        }

        return $units;
    }

    /**
     * Whether $char renders as part of the unit $previous, per the four rules
     * on {@see clusters()}.
     *
     * The two emoji ranges are matched on UTF-8 BYTES rather than on a decoded
     * codepoint so that this needs neither mbstring's `mb_ord()` nor a second
     * copy of candy-core's hand-rolled decoder. U+1F3FB..U+1F3FF encode as
     * `F0 9F 8F BB..BF` and U+1F1E6..U+1F1FF as `F0 9F 87 A6..BF`; both are
     * four bytes behind a fixed three-byte prefix, so the prefix plus a range
     * check on the final byte identifies them exactly.
     */
    private static function joinsPrevious(string $char, string $previous): bool
    {
        if (str_ends_with($previous, "\u{200D}") || self::charWidth($char) === 0) {
            return true;
        }

        if (strlen($char) !== 4 || !str_starts_with($char, "\xf0\x9f")) {
            return false;
        }

        $third = $char[2];
        $final = ord($char[3]);

        // Skin-tone modifier: attaches to whatever emoji it follows.
        if ($third === "\x8f" && $final >= 0xbb && $final <= 0xbf) {
            return true;
        }

        // Regional indicator: pairs with a SINGLE preceding regional
        // indicator. Against a unit that is already a pair this answers
        // false, which is what starts the next flag.
        return $third === "\x87" && $final >= 0xa6 && $final <= 0xbf
            && strlen($previous) === 4
            && str_starts_with($previous, "\xf0\x9f\x87");
    }

    /**
     * Compute visual width of a string in terminal cells.
     *
     * Delegates to {@see Width::string()}, so this class's truncator and its
     * right-pad read the same width table. They did not: the pad counted bytes
     * while the truncator walked a local table, and that table disagreed with
     * Width on real codepoints (emoji scored 1 here, 2 there; a combining
     * accent scored 1 here, 0 there). Two tables cannot hold a column still
     * however carefully either is written.
     *
     * One table was not yet one ANSWER, and for a while this comment said so:
     * `Width::string()` runs a ZWJ state machine across a WHOLE string, while
     * {@see truncate()} summed {@see charWidth()} one codepoint at a time, and
     * the two scored the family emoji U+1F468 ZWJ U+1F469 ZWJ U+1F467 as 2 and
     * as 6. That gap is closed: the truncator now steps over
     * {@see clusters()} and measures each unit through THIS function, so both
     * paths ask `Width::string()` and a ZWJ sequence gets one answer.
     *
     * What that does and does not buy is worth keeping straight. Agreement is
     * per unit, and units are grouped by {@see clusters()}'s four rules rather
     * than by UAX #29 — so a sequence outside those rules (conjoining Hangul
     * jamo, an Indic conjunct) is still split at a codepoint boundary. On this
     * tree's PHP 8.3 that costs no width error at all: `Width` splits with
     * `mb_str_split()` there, one codepoint per cluster, so the whole-string
     * measure IS the per-codepoint sum for anything without a ZWJ in it —
     * measured, L+V+T is 4 either way. On a PHP 8.4 build `Width` would split
     * with `grapheme_str_split()` and score that syllable by its first
     * codepoint, 2, where the sum here is still 4.
     *
     * That residual gap runs in the safe direction: on 8.4 the per-unit sum is
     * the LARGER -- measured, a jamo-spelled Hangul syllable is 4 summed here
     * against 2 whole, a Devanagari conjunct 4 against 1 -- so the loop spends
     * its budget early and cuts sooner than it had to. It can drop a character
     * that would have fit; it does not over-run.
     *
     * Do NOT read that reassurance back onto the truncator this replaced. The
     * same "over-truncates, never over-runs" was said of the per-codepoint
     * loop, and of that loop it was false. `Width::string()` charges +2 for
     * `<emoji> ZWJ` -- it credits the emoji its ZWJ state machine skipped --
     * where the per-codepoint sum charges 1 + 0, so on those inputs the
     * WHOLE-string measure is the larger of the two and the early return let
     * an over-wide string through. Measured at 087a3179,
     * `truncate(U+1F1E6 U+11A8 U+2764 ZWJ U+1F1F8, 4)` came back 5 cells, and
     * 400,000 fuzzed calls over an emoji-heavy alphabet produced 727 over-runs
     * (worst +2). The cluster loop above produced 0 on the same 400,000. This
     * change therefore closes an over-run as well as a dangling joiner, which
     * is more than the finding it was written for claimed for it.
     */
    private static function visualWidth(string $text): int
    {
        return Width::string($text);
    }

    /**
     * Visual width of a single codepoint: 0 for control, 1 for regular, 2 for
     * wide.
     *
     * {@see truncate()} no longer sums this — it measures whole clusters with
     * {@see visualWidth()} instead. What still reads it is
     * {@see joinsPrevious()}, and it reads it for one bit of information only:
     * whether a codepoint has a cell of its own. A zero answer is NOT only the
     * cases the rule is aimed at -- a combining mark, a variation selector,
     * the joiner itself, none of which can be cut away from what they attach
     * to -- but anything `Width` scores 0, which per the line above includes
     * controls. Measured, "a\x00b\x07c" clusters as `a\x00` | `b\x07` | `c`.
     * Attaching a control to the character before it moves no budget, since it
     * had no cell either way, and a row carrying one has a worse problem than
     * where its truncator cut.
     */
    private static function charWidth(string $char): int
    {
        return Width::string($char);
    }
}

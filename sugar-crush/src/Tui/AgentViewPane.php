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
     * on each side, so a finished row is `$width + CHROME_WIDTH` cells
     * WHENEVER THE ROW BODY FITS `$width`. State the domain rather than call
     * it an invariant, even though {@see render()} now makes the body fit by
     * construction: the domain is the thing that is actually true of the
     * arithmetic, and the construction is a separate claim that can be broken
     * by a future edit without this comment noticing.
     *
     * What used to break it, and what the clamp in `render()` exists for, was
     * `$opBudget = max(5, $width - Width::string($name) - 60)`. That floor of
     * 5 is unconditional, so `leftSection` plus `rightSection` could exceed
     * `$width`; the body no longer fitted the box `Style::width()` was sizing,
     * and where the cut fell inside a 2-cell cluster the row came back wider
     * than the box's own border. Measured with a 3-cell name and a skin-toned
     * thumb or a flag in the operation, that was 6 of the 10 widths E54 stated
     * the `+4` over (20, 28, 30, 40, 43, 44, 58, 60, 80, 98) -- the first six
     * -- and over a 20..140 sweep every width from 20 to 45 inclusive (excess
     * +2 through 44, +1 at 45). It was
     * byte-for-byte the same at 087a3179, so it was this pane's pre-existing
     * floor policy and not the chrome arithmetic; it is recorded as E64.
     *
     * The floor itself is untouched, deliberately: `$opBudget` is still the
     * same estimate, so every width where it already fitted renders exactly as
     * before. What changed is that the composed label is clamped to the cells
     * actually left after the metrics, and the metrics degrade (usage first,
     * then elapsed) rather than being clipped mid-token.
     *
     * `clipWidth()` in the shell renderer remains the backstop, for the case
     * {@see contentWidth()} documents: a terminal narrower than
     * `$minimum + CHROME_WIDTH` still gets rows wider than itself, because the
     * floor there is the CALLER's and nothing in this class can see it.
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
        //
        // Kept, unread, as the written-down form of an estimate the loop below
        // still charges for: this same ~26 is the bulk of the `60` in
        // `$opBudget`, which is why that number is what it is. Nothing reads
        // it because the loop measures `Width::string($rightSection)` for real
        // now -- an estimate cannot be the thing a budget is enforced against
        // (E64) -- but deleting it would take the derivation of the 60 with
        // it, and the 60 is deliberately unchanged.
        $fixedRight = 26;

        $rows = [];
        $count = min(count($agents), $maxRows);

        for ($i = 0; $i < $count; $i++) {
            $agent = $agents[$i];
            $isSelected = ($i === $selectedIndex);
            $statusColor = self::statusColor(self::stripEscapes($agent->status), $theme);

            // Colour dot + name + status badge.
            //
            // The three data-derived fields are taken through
            // {@see stripEscapes()} FIRST, before anything measures or cuts
            // them. See the clamp below for why: this row's truncator is
            // cluster-aware but not escape-aware, so an escape reaching it is
            // an escape it may cut in half.
            $dot    = Style::new()->foreground($statusColor)->render("\u{25CF}");
            $name   = self::stripEscapes($agent->name);
            $status = self::stripEscapes($agent->status);

            // Right-side (elapsed + usage).
            $rightSection = $agent->elapsedDisplay() . '  ' . $agent->usageDisplay();

            // Cells the IDENTITY costs, operation excluded:
            //   dot(1) + ' '(1) + name + ' ['(2) + status + ']  '(3).
            $identityWidth = 7 + Width::string($name) + Width::string($status);

            // Below `identity + metrics` columns the row cannot show both, and
            // the metrics are what gives: a row that cannot say WHICH agent it
            // is has nothing left to say, while elapsed-without-usage is still
            // a whole reading. Degrade in two steps rather than cutting the
            // metrics mid-number -- `1,234 tok | $0.00` clipped to fit reads as
            // a smaller cost, not as a truncated one.
            if ($identityWidth + Width::string($rightSection) > $width) {
                $rightSection = $agent->elapsedDisplay();

                if ($identityWidth + Width::string($rightSection) > $width) {
                    $rightSection = '';
                }
            }

            $rightWidth = Width::string($rightSection);

            // Truncate operation to fill remaining space.
            // name(~12) + dot(2) + sep(2) + status_bracket(~14) + sep(2) + right(~26)
            //
            // $width is a CELL budget, so the name has to be charged against it
            // in cells. strlen() charged BYTES, which meant an agent named with
            // any non-ASCII character silently bought less operation text than
            // an identically-wide ASCII name -- "abc" got 17 columns while
            // "abc" with an acute accent on the a got 16, for the same three
            // cells on screen.
            //
            // This is an ESTIMATE and always was: the terms in the comment
            // directly above add to 58, rounded to 60, and the name is charged
            // twice over -- once as the ~12 in that 60 and again as the real
            // Width::string($name) -- which is what makes the estimate
            // conservative rather than tight. The floor of 5 is a wish that a
            // narrow pane still shows some operation text. Neither is measured
            // against what this particular row actually has left, which is what
            // the clamp below is for; the estimate is kept exactly as-is so
            // that every width where it already fitted renders byte-for-byte as
            // before.
            $opBudget = max(5, $width - Width::string($name) - 60);
            $operation = self::truncate(self::stripEscapes($agent->operation), $opBudget);

            // Left section: dot + name + status + operation, clamped to the
            // cells actually left after the metrics.
            //
            // The clamp is the fix for E64. `$opBudget`'s floor of 5 is
            // unconditional, so on a narrow pane the composed left section plus
            // the right section could add up to more than `$width` -- 17 cells
            // of identity plus a 5-cell operation plus 24 cells of metrics does
            // not fit 20 columns, and no amount of padding arithmetic below
            // claws that back. `Style::width()` then had to deal with a body
            // longer than the box it was sizing, and where the cut fell inside
            // a 2-cell cluster the finished row came back WIDER than the box's
            // own border: `render(.., 20, ..)` returned a 24-cell frame around
            // a 26-cell row. Only the shell renderer's `clipWidth()` kept that
            // off the screen.
            //
            // Clamping the label -- everything after the styled dot -- to
            // `$width - $rightWidth - 1` makes the body fit the box, and the
            // wrap that produced the over-run never happens. "Fit" here means
            // fit BY `Width::string`, which is not quite the same as fitting
            // the renderer: `Style::render()` expands a tab to four spaces
            // AFTER this clamp has measured it as zero, so an operation
            // carrying a TAB still outgrows the box. That divergence is a
            // width-authority defect rather than this pane's, and is recorded
            // separately as E69.
            //
            // The dot is excluded from the clamp deliberately, and the label
            // is escape-free deliberately. `truncate()` groups CLUSTERS, not
            // escape sequences: an ESC byte measures 0 cells, so it joins the
            // unit before it, while the `[`, the parameter digits and the
            // final `m` are each ordinary 1-cell units the loop is free to cut
            // between. Feed it a string carrying SGR and it will happily
            // return `\e[32mabc\e` or `...abc\e[0` -- a severed reset leaks
            // its colour into the REST OF THE FRAME, not just this row. Before
            // the clamp, `$name` and `$agent->status` were interpolated
            // untruncated and an escape inside them survived intact; measured
            // over widths 1..140 with a name of `\e[32mabc\e[0m`, clamping
            // the composed label severed at 7 widths against the old code's 0,
            // and with the same escapes in the status, 13 against 0.
            //
            // So the precondition is ENFORCED rather than assumed: $name,
            // $status and $operation are escape-free copies (see
            // {@see stripEscapes()}) and nothing that reaches `truncate()`
            // carries an escape at all. It is not enough to require it of the
            // data -- `$name` is `$agent->name` verbatim, straight off the
            // Agent registry and out of imported foreign presets, and nothing
            // anywhere validates it.
            $label = self::truncate(
                " {$name} [{$status}]  {$operation}",
                max(0, $width - $rightWidth - 1),
            );

            $leftSection = $dot . $label;

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
            // padRight() is a no-op once the section already meets the width.
            // That is NOT the same as "this cannot widen a row past its
            // budget", which is what this comment used to claim: padRight
            // cannot widen the row, but it cannot narrow it either, and until
            // the clamp above existed nothing did (E64).
            $leftPadded = Width::padRight($leftSection, $width - $rightWidth - 1);

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
     * Strip every ESC byte, and the sequence it introduces, out of $text.
     *
     * The row's truncator is cluster-aware and NOT escape-aware, so anything
     * it may cut has to be escape-free before it gets there; see the clamp in
     * {@see render()} for the measured cost of skipping this. Applied to the
     * three fields that come from data -- name, status, operation -- and not
     * to the styled dot, which this class composes itself and never cuts.
     *
     * Removing rather than preserving, for two reasons. A row is a fixed-width
     * cell of someone else's text and colouring it is this pane's decision,
     * not the registry's; and an ANSI-preserving truncator would have to be
     * trusted at exactly the widths that matter, which
     * {@see Width::truncateAnsi()} is not: measured over 20,000 random
     * cluster-heavy strings at budgets 1..8 it came back WIDER than the budget
     * it was given 3,425 times, worst +8 -- `truncateAnsi(U+1F44D U+1F3FD
     * . 'xy', 3)` returns 5 cells. Handing the clamp to it would trade a
     * severed escape for an over-run, which is the defect the clamp exists to
     * close.
     *
     * Three alternatives: a CSI (`ESC [`, parameter bytes 0x30..0x3f,
     * intermediate bytes 0x20..0x2f, final byte 0x40..0x7e), an OSC (`ESC ]`
     * up to BEL or ST), and then any ESC left over. The last one drops the ESC
     * BYTE only and keeps what followed it, which is why `ESC ( B` comes back
     * as `(B`: two visible cells, which is also what `Width::string()` scores
     * the original.
     *
     * On escape-FREE input this is the identity -- measured, 0 of 20,000
     * random strings over an alphabet of ASCII, accents, emoji, ZWJ, tabs and
     * bare brackets came back changed -- so nothing any existing geometry
     * figure was measured over moves at all.
     *
     * It is NOT width-neutral on escape-bearing input, and does not need to
     * be. `Width::string()` skips a CSI by scanning to the first byte in
     * 0x40..0x7e whatever lies between, so a MALFORMED `ESC [` swallows the
     * visible text after it; the grammar above stops at the first byte that is
     * not a legal parameter or intermediate, and leaves that text on screen,
     * which is what a real terminal does with it. Over 20,000 random
     * escape-bearing strings the two disagree on width in 5,398. That
     * disagreement is inert here because {@see render()} measures the STRIPPED
     * text everywhere -- `$identityWidth`, `$opBudget`, the clamp and the
     * right-pad all read fields that have already been through this function,
     * and the raw field is never measured again after it.
     */
    private static function stripEscapes(string $text): string
    {
        return preg_replace(
            '/\x1b\[[\x30-\x3f]*[\x20-\x2f]*[\x40-\x7e]?|\x1b\][^\x07\x1b]*(?:\x07|\x1b\x5c)?|\x1b/',
            '',
            $text,
        ) ?? $text;
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
     * Hand-rolled rather than delegated because {@see Width}'s splitter is
     * PRIVATE. That is the whole of the reason as of round 40 (2026-08-22),
     * and it is worth saying plainly because the reason used to be a
     * different one that is no longer true.
     *
     * WHAT CHANGED. This paragraph previously argued a VERSION case: that
     * `grapheme_str_split()` is PHP 8.4+ while the tree targets 8.3, that
     * `grapheme_extract()` ships with intl on 8.3 but "intl is not a declared
     * dependency of sugar-crush or of candy-core", and that reaching for it
     * would therefore split one way on a build that has intl and another on a
     * build that does not. Every clause of that is now stale: `Width` no
     * longer calls `grapheme_str_split()` on ANY version (it walks ICU via
     * `grapheme_extract()` on both, which is what closed E68), and
     * `candy-core` now declares `ext-intl` in its manifest, so sugar-crush
     * requires intl transitively whether or not its own manifest names it.
     *
     * This still runs on `preg_split('//u')` and so still needs no extension,
     * which is now a property rather than a justification.
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
     * ONE of the two EMOJI rules still moves no total; the other now does.
     * MEASURED on PHP 8.3.6 with intl, before and after round 40's E68 fix
     * (`Width::string()` of the whole against the sum of its codepoints):
     *
     *   shape        | grouped before -> after | summed (unchanged)
     *   flag (RI)    |        2 -> 2           |        2
     *   thumb+tone   |        4 -> 2           |        4
     *   family (ZWJ) |        2 -> 2           |        6
     *   Hangul L+V+T |        4 -> 2           |        4
     *
     * So rule 3 (flags) still changes only what may be SPLIT. Rule 4 (skin
     * tone) now closes a 2-cell gap that did not exist when this docblock was
     * written -- `Width` scored a toned thumb 4 before the fix and scores it 2
     * now. Rule 1 is unchanged and is still why this function exists: the
     * family emoji is 2 grouped against 6 summed.
     *
     * THE STALE CLAIM THIS REPLACES, named so nobody re-derives it from a
     * cached memory of the file: this said "neither of the two EMOJI rules
     * moves a total", that a flag scores "1+1" and a toned thumb "2+2" the
     * same grouped or ungrouped, and that on an 8.4 build the flag would
     * measure 1 against 2 and the thumb 2 against 4. There is no longer ANY
     * 8.3/8.4 divergence to state -- that was E68, and it is fixed.
     *
     * NEW AND UNCLOSED, in the safe direction: Hangul L+V+T is 2 grouped and
     * 4 summed, and rules 1-4 do not group it (see the UAX #29 note below), so
     * this splitter now OVER-counts a Hangul syllable by 2 cells. Over-counting
     * under-fills a row; it cannot over-run one.
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
     * jamo, an Indic conjunct) is still split at a codepoint boundary.
     *
     * ⚠️ THIS USED TO COST NOTHING ON 8.3 AND NOW COSTS THE SAME ON BOTH
     * VERSIONS. The paragraph here previously said `Width` splits with
     * `mb_str_split()` on 8.3, one codepoint per cluster, so "the whole-string
     * measure IS the per-codepoint sum for anything without a ZWJ in it —
     * measured, L+V+T is 4 either way", and that only a PHP 8.4 build would
     * score the syllable 2 against a sum of 4. Round 40's E68 fix made `Width`
     * walk ICU on EVERY version, so the 8.4 number is now the only number:
     * MEASURED on PHP 8.3.6, L+V+T is **2 whole against 4 summed here**, where
     * before the fix it was 4 either way.
     *
     * That residual gap runs in the safe direction, and that part is unchanged:
     * the per-unit sum is the LARGER -- measured post-fix, a jamo-spelled
     * Hangul syllable is 4 summed here against 2 whole, a Devanagari conjunct 4
     * against 1 -- so the loop spends its budget early and cuts sooner than it
     * had to. It can drop a character that would have fit; it does not
     * over-run. What changed is that this is now a real gap on the build this
     * tree actually runs, rather than a hypothetical one about a build it does
     * not.
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

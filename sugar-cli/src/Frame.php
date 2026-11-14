<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Full-screen application chrome: a double-line box that fills the terminal
 * exactly, with a centred title bar, top/bottom dividers, and a status bar.
 *
 *   ╔═══════════════ title ═══════════════╗   top border + title
 *   ╠═════════════════════════════════════╣   divider
 *   ║ body line 1                         ║
 *   ║ body line 2                         ║   content area
 *   ╠═════════════════════════════════════╣   divider
 *   ║ status bar                          ║
 *   ╚═════════════════════════════════════╝   bottom border + status
 *
 * The body is normalised to EXACTLY the available height — shorter content is
 * blank-padded, taller content is hard-truncated — so the whole frame is
 * always a constant `$rows` lines. This is load-bearing for a TEA program
 * whose frame-diff renderer (sugar-core) owns the screen: it paints once and
 * then emits minimal per-line diffs, so a frame that changed its line count,
 * ran a line past the terminal width (forcing a wrap), or emitted a mid-frame
 * screen clear would desync the renderer's one-line-per-row model and corrupt
 * remote (ssh/tmux) sessions. Frame therefore:
 *   - keeps a constant total line count (= `$rows`);
 *   - measures padding by display CELLS (ANSI-width aware) so no line is wider
 *     than the terminal;
 *   - emits NO `\x1b[2J` / screen clear — pure content for the diff renderer.
 *
 * The title, status and body arrive pre-rendered by the host app — sugar-style
 * output whose SGR
 * sequences ARE the requested styling. They are still treated as untrusted at
 * ingress: every such line runs through the sanitizer that keeps exactly the
 * CSI-SGR subset and discards all other escape/control bytes, so no caller can
 * forge a screen clear, cursor move, OSC clipboard write, or extra row through
 * a Frame string. That guarantee is scoped to string ingress: the border Style
 * itself is rendered as given, and a sugar-style Style may legitimately carry
 * an OSC-8 hyperlink envelope — hand withBorderStyle() only a style whose
 * bytes you want on the terminal. Tightening the border path too (stripping
 * OSC envelopes from the border Style at ingress) would change how existing
 * apps render and is an owner call, not a silent fix.
 */
final class Frame
{
    use Mutable;

    /**
     * Non-content lines the frame always emits: top border + title + the two
     * dividers + status + bottom border. The content rows sit *between* the two
     * dividers, so this is 6, not 7 — an off-by-one here leaves the frame one
     * row short of the terminal and the bottom row blank.
     */
    private const OVERHEAD = 6;

    private function __construct(
        private readonly string $title,
        private readonly string $status,
        private readonly Style $borderStyle,
    ) {}

    /**
     * The seed border colour: slate rgb 100,116,139. A plain string const is
     * the closest a fluent `Style::new()->foreground(...)` seed can come to a
     * constant expression — the assembled Style would not be `const`-able.
     */
    public const DEFAULT_BORDER_COLOR = '#64748b';

    /** Default root — a slate box border (rgb 100,116,139 = #64748b). */
    public static function new(): self
    {
        return new self('', '', Style::new()->foreground(Color::hex(self::DEFAULT_BORDER_COLOR)));
    }

    /** The (pre-rendered) title-bar string, centred on the top row. */
    public function withTitle(string $title): self
    {
        return $this->mutate(['title' => Internal\SafeText::styledLine($title)]);
    }

    /**
     * Theme-aware title rendering — applies $style to $text and sets
     * the result as the title bar. This is a convenience over
     * {@see withTitle()} for callers that already have a Style
     * rather than a pre-rendered string.
     */
    public function withTitleText(string $text, Style $style): self
    {
        return $this->withTitle($style->render($text));
    }

    /** The (pre-rendered) status-bar string, left-aligned on the bottom row. */
    public function withStatus(string $status): self
    {
        return $this->mutate(['status' => Internal\SafeText::styledLine($status)]);
    }

    /** Override the box-drawing border colour (defaults to slate). */
    public function withBorderStyle(Style $style): self
    {
        return $this->mutate(['borderStyle' => $style]);
    }

    /**
     * Adopt the theme's muted style for the border.
     *
     * WHY: the slate seed bypasses profile downgrade (README discloses it),
     * which is right for a branded splash but wrong for themed UI — this
     * routes the chrome through `Theme::$muted` so `NO_COLOR`, piped STDOUT
     * and `Theme::plain()` all reach the border like every other run. Omit
     * the argument to honour {@see Theme::detect()}.
     */
    public function withTheme(?Theme $theme = null): self
    {
        return $this->mutate(['borderStyle' => ($theme ?? Theme::detect())->muted]);
    }

    /** Drop the border styling entirely (identity `Style::new()`). */
    public function withPlainBorder(): self
    {
        return $this->mutate(['borderStyle' => Style::new()]);
    }

    /**
     * Render the framed body at exactly $cols × $rows cells.
     *
     * The returned string is always exactly $rows lines and every line is
     * exactly max($cols, 4) display cells wide. Widths below 4 cannot host
     * the two border caps plus at least one content cell without overflowing
     * (a 1-col frame would emit a 2-cell rule, forcing a wrap that desyncs
     * the diff renderer), so the width is clamped up to a 4-cell floor. That
     * floor is still wider than a 1-3 column terminal, so those rows DO wrap
     * (terminals always autowrap — nothing can avoid it while staying one
     * line); the clamp just keeps the wrap linear and predictable instead of
     * each row wrapping a different amount, and the line COUNT only breaks
     * for sub-floor widths, where no honest contract is possible anyway.
     *
     * @throws \InvalidArgumentException when $rows < {@see OVERHEAD}: there is
     *         no room for the border/title/divider/status chrome, so the frame
     *         cannot honour its "exactly $rows lines" contract (it would emit
     *         all OVERHEAD chrome lines regardless, overflowing the terminal
     *         and desyncing the diff renderer). Callers must guarantee the
     *         terminal is at least OVERHEAD rows tall before framing. The
     *         rows floor throws while the cols floor clamps because an
     *         over-tall frame scrolls the alt-screen irrecoverably, whereas a
     *         too-narrow one still paints every row — the asymmetry is
     *         deliberate.
     */
    public function render(string $body, int $cols, int $rows): string
    {
        if ($rows < self::OVERHEAD) {
            throw new \InvalidArgumentException(
                'Frame::render() needs at least ' . self::OVERHEAD
                . ' rows for the border/title/divider/status chrome; got ' . $rows . '.'
            );
        }

        // Fail-safe floor (see render() docblock): below 4 cells the two border
        // caps alone leave no room for even a 1-cell content column, and the
        // rule/title lines would overflow $cols and force a terminal wrap.
        // Clamping up keeps every emitted line exactly max($cols, 4) wide.
        $cols = max(4, $cols);
        $inner = max(0, $cols - 2);
        // Finding 049: a title-less frame folds its title slot away — the
        // chrome is OVERHEAD - 1 rows and the freed row goes to content.
        // The rows floor (throw) still speaks the full OVERHEAD contract:
        // callers who size the terminal once must not re-derive it per title.
        $hasTitle      = $this->title !== '';
        $overhead      = $hasTitle ? self::OVERHEAD : self::OVERHEAD - 1;
        $contentHeight = max(0, $rows - $overhead);

        // Normalise the body to EXACTLY $contentHeight rows: hard-truncate the
        // overflow (a frame taller than the terminal scrolls the alt-screen and
        // permanently desyncs the renderer) and blank-pad anything shorter.
        // Each row is then sanitized for the terminal (not for the row model —
        // the explode above already consumed the body's newlines as layout):
        // SGR survives, every other escape/control byte is dropped.
        $contentLines = explode("\n", $body, $contentHeight + 1);
        $droppedRows  = count($contentLines) > $contentHeight;
        if ($droppedRows) {
            // Bounded split (finding 012): with a limit the final element
            // carries every remaining line, so `count() > $contentHeight`
            // stays true here exactly as it did with the full split — the
            // slice drops the whole unmaterialized remainder, byte-identical.
            $contentLines = array_slice($contentLines, 0, $contentHeight);
        }
        for ($i = count($contentLines); $i < $contentHeight; $i++) {
            $contentLines[] = '';
        }
        foreach ($contentLines as $i => $line) {
            $contentLines[$i] = Internal\SafeText::styledLine($line);
        }
        if ($droppedRows && $contentHeight > 0) {
            // Finding 048: dropped rows leave a silent signal — the last
            // visible row gains the house ellipsis so the reader knows the
            // body continued. Row count stays exactly $contentHeight.
            $last = $contentHeight - 1;
            $contentLines[$last] =
                Width::truncateAnsi($contentLines[$last], max(0, $inner - 1)) . '…';
        }
        foreach ($contentLines as $i => $line) {
            $contentLines[$i] = self::closeOpenRun($line);
        }

        $bar = $this->borderStyle->render('║');

        $lines = [];
        $lines[] = $this->rule('╔', '╗', $inner);
        if ($hasTitle) {
            $lines[] = $bar . self::padCenter(self::closeOpenRun($this->title), $inner) . $bar;
        }
        $lines[] = $this->rule('╠', '╣', $inner);
        foreach ($contentLines as $line) {
            $lines[] = $bar . self::padRight($line, $inner) . $bar;
        }
        $lines[] = $this->rule('╠', '╣', $inner);
        $lines[] = $bar . self::padRight(self::closeOpenRun($this->status), $inner) . $bar;
        $lines[] = $this->rule('╚', '╝', $inner);

        return implode("\n", $lines);
    }

    /** A coloured horizontal rule: a left cap, $inner ═, and a right cap. */
    private function rule(string $left, string $right, int $inner): string
    {
        return $this->borderStyle->render($left . str_repeat('═', $inner) . $right);
    }

    /**
     * Pad a string to $width cells on the right with spaces.
     *
     * Measures by display CELLS (not codepoints): wide CJK chars, combining
     * marks and emoji each diverge from mb_strlen, and a wrong measurement
     * either misplaces the right border or makes the line wider than the
     * terminal so it wraps — corrupting the frame-diff renderer's
     * one-line-per-row model. Over-long content is truncated with an ellipsis.
     */
    private static function padRight(string $s, int $width): string
    {
        [$s, $len] = self::truncateWithEllipsis($s, $width);

        if ($len < $width) {
            return $s . str_repeat(' ', $width - $len);
        }

        return $s;
    }

    /**
     * Centre a string within $width, padding equally on both sides.
     *
     * As in {@see padRight}, over-long content is truncated to fit with an
     * ellipsis and the result is then padded to fill exactly $width.
     */
    private static function padCenter(string $s, int $width): string
    {
        [$s, $len] = self::truncateWithEllipsis($s, $width);

        $pad = max(0, $width - $len);
        $left = (int) floor($pad / 2);
        $right = $pad - $left;

        return str_repeat(' ', $left) . $s . str_repeat(' ', $right);
    }

    /**
     * Defense against unclosed SGR runs (finding 052): a row whose last SGR
     * introducer never met a reset would paint the padding and both border
     * caps with its colors. When the final SGR sequence is not a reset, one
     * is appended — a byte-level no-op on closed runs, so the chrome layout
     * is untouched. This is a positional check, not open/close parity:
     * nested-but-closed runs like `\x1b[1m…\x1b[31m…\x1b[0m` (odd openers,
     * already safe) pass through, while anything ending mid-run gets closed.
     */
    private static function closeOpenRun(string $s): string
    {
        if ($s === '' || !preg_match_all('/\x1b\[([0-9;]*)m/', $s, $matches, PREG_SET_ORDER)) {
            return $s;  // no SGR at all — nothing to close
        }
        $last = end($matches)[1];
        // Only the canonical full resets (\x1b[m, \x1b[0m) count as closed;
        // anything else — including exotic `31;0` orders or truecolor
        // sequences ending in a literal 0 channel — gets the redundant reset,
        // which costs 4 bytes and can never misrender.
        if ($last === '' || $last === '0') {
            return $s;
        }
        return $s . Ansi::reset();
    }

    /**
     * Truncate a string to at most $width cells, appending a single-cell
     * ellipsis (…). Width::truncateAnsi preserves ANSI sequences so
     * colours/sgr survive the cut; a final reset is appended to prevent
     * bleed past the frame boundary.
     *
     * Re-measures after truncation: truncate can stop short of $width (a
     * wide glyph won't be split), so this returns the measured length too.
     *
     * @return array{string, int} [$truncated, $measuredWidth]
     */
    private static function truncateWithEllipsis(string $s, int $width): array
    {
        $len = Width::string($s);

        if ($len > $width) {
            $s = Width::truncateAnsi($s, max(0, $width - 1)) . '…' . Ansi::reset();
            $len = Width::string($s);
        }

        return [$s, $len];
    }
}

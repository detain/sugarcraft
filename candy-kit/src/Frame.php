<?php

declare(strict_types=1);

namespace SugarCraft\Kit;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;

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
 * whose frame-diff renderer (candy-core) owns the screen: it paints once and
 * then emits minimal per-line diffs, so a frame that changed its line count,
 * ran a line past the terminal width (forcing a wrap), or emitted a mid-frame
 * screen clear would desync the renderer's one-line-per-row model and corrupt
 * remote (ssh/tmux) sessions. Frame therefore:
 *   - keeps a constant total line count (= `$rows`);
 *   - measures padding by display CELLS (ANSI-width aware) so no line is wider
 *     than the terminal;
 *   - emits NO `\x1b[2J` / screen clear — pure content for the diff renderer.
 *
 * The title and status strings are passed in pre-rendered (the host app builds
 * them — they are app-specific). Frame only draws and colours the box and lays
 * those bars into it.
 */
final class Frame
{
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

    /** Default root — a slate box border (rgb 100,116,139 = #64748b). */
    public static function new(): self
    {
        return new self('', '', Style::new()->foreground(Color::hex('#64748b')));
    }

    /** The (pre-rendered) title-bar string, centred on the top row. */
    public function withTitle(string $title): self
    {
        return new self($title, $this->status, $this->borderStyle);
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
        return new self($this->title, $status, $this->borderStyle);
    }

    /** Override the box-drawing border colour (defaults to slate). */
    public function withBorderStyle(Style $style): self
    {
        return new self($this->title, $this->status, $style);
    }

    /**
     * Render the framed body at exactly $cols × $rows cells.
     *
     * The returned string is always exactly $rows lines (for $rows >= the frame
     * overhead) and every line is exactly $cols display cells wide.
     */
    public function render(string $body, int $cols, int $rows): string
    {
        $inner = max(0, $cols - 2);
        $contentHeight = max(0, $rows - self::OVERHEAD);

        // Normalise the body to EXACTLY $contentHeight rows: hard-truncate the
        // overflow (a frame taller than the terminal scrolls the alt-screen and
        // permanently desyncs the renderer) and blank-pad anything shorter.
        $contentLines = explode("\n", $body);
        if (count($contentLines) > $contentHeight) {
            $contentLines = array_slice($contentLines, 0, $contentHeight);
        }
        for ($i = count($contentLines); $i < $contentHeight; $i++) {
            $contentLines[] = '';
        }

        $bar = $this->borderStyle->render('║');

        $lines = [];
        $lines[] = $this->rule('╔', '╗', $inner);
        $lines[] = $bar . self::padCenter($this->title, $inner) . $bar;
        $lines[] = $this->rule('╠', '╣', $inner);
        foreach ($contentLines as $line) {
            $lines[] = $bar . self::padRight($line, $inner) . $bar;
        }
        $lines[] = $this->rule('╠', '╣', $inner);
        $lines[] = $bar . self::padRight($this->status, $inner) . $bar;
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

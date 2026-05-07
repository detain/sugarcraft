<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Table;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;

/**
 * Selectable, scrollable data table.
 *
 * Distinct from {@see \SugarCraft\Sprinkles\Table\Table}, which is a static
 * styled renderer. This component holds a moving selection cursor,
 * scrolls vertically when the row count exceeds {@see $height}, and
 * draws an underlined header.
 *
 * Column widths are computed from header + cell widths and capped at
 * {@see $width} (when > 0) by truncating individual cells.
 */
final class Table implements Model
{
    /**
     * Row index passed to a {@see styleFunc()} callback when rendering
     * the header row. Mirrors lipgloss / candy-sprinkles `HEADER_ROW`.
     */
    public const HEADER_ROW = -1;

    private function __construct(
        /** @var list<string> */ public readonly array $headers,
        /** @var list<list<string>> */ public readonly array $rows,
        public readonly int $cursor,
        public readonly int $offset,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $focused,
        /** @var list<int> per-column explicit widths (0 = auto). Aligned to $headers index. */
        public readonly array $colWidths = [],
        public readonly ?Styles $styles = null,
        /** @var ?\Closure(int $row, int $col): \SugarCraft\Sprinkles\Style */
        public readonly ?\Closure $styleFunc = null,
    ) {}

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function new(array $headers = [], array $rows = [], int $width = 0, int $height = 10): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('table width/height must be >= 0');
        }
        return new self(
            headers: array_values($headers),
            rows:    array_values(array_map('array_values', $rows)),
            cursor:  0,
            offset:  0,
            width:   $width,
            height:  $height,
            focused: false,
            colWidths: [],
        );
    }

    /**
     * Replace headers + per-column explicit widths in one call. Each
     * `Column` carries a title and an optional fixed width; passing
     * `width=0` lets the column auto-size. Mirrors Bubbles' `WithColumns`.
     *
     * @param list<Column> $columns
     */
    public function setColumns(array $columns): self
    {
        $titles = [];
        $widths = [];
        foreach ($columns as $col) {
            if (!$col instanceof Column) {
                throw new \InvalidArgumentException('setColumns expects Column instances');
            }
            $titles[] = $col->title;
            $widths[] = $col->width;
        }
        return $this->mutate(headers: $titles, colWidths: $widths);
    }

    /** @return list<Column> */
    public function columns(): array
    {
        $out = [];
        foreach ($this->headers as $i => $title) {
            $out[] = new Column($title, $this->colWidths[$i] ?? 0);
        }
        return $out;
    }

    /** Bubble-Tea Init — returns the bootstrap Cmd (cursor blink, first tick, etc.) or null. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg || !$this->focused) {
            return [$this, null];
        }
        return match (true) {
            $msg->type === KeyType::Up
                || ($msg->type === KeyType::Char && $msg->rune === 'k')
                => [$this->moveCursor($this->cursor - 1), null],
            $msg->type === KeyType::Down
                || ($msg->type === KeyType::Char && $msg->rune === 'j')
                => [$this->moveCursor($this->cursor + 1), null],
            $msg->type === KeyType::Home
                || ($msg->type === KeyType::Char && $msg->rune === 'g')
                => [$this->moveCursor(0), null],
            $msg->type === KeyType::End
                || ($msg->type === KeyType::Char && $msg->rune === 'G')
                => [$this->moveCursor(PHP_INT_MAX), null],
            $msg->type === KeyType::PageUp
                => [$this->moveCursor($this->cursor - max(1, $this->height)), null],
            $msg->type === KeyType::PageDown
                => [$this->moveCursor($this->cursor + max(1, $this->height)), null],
            default => [$this, null],
        };
    }

    /** Render the component as a multi-line ANSI string. */
    public function view(): string
    {
        $cols = $this->columnWidths();
        if ($cols === []) {
            return '';
        }

        $lines = [];
        if ($this->headers !== []) {
            $headerRow = $this->renderRow($this->headers, $cols, self::HEADER_ROW);
            $lines[] = $this->styles !== null
                ? $this->styles->header->render($headerRow)
                : Ansi::sgr(Ansi::UNDERLINE) . $headerRow . Ansi::reset();
        }

        $top    = max(0, $this->offset);
        $window = array_slice($this->rows, $top, $this->height);
        foreach ($window as $i => $row) {
            $idx = $top + $i;
            $line = $this->renderRow($row, $cols, $idx);
            $isSelected = $idx === $this->cursor && $this->focused;
            if ($this->styles !== null) {
                $line = $isSelected
                    ? $this->styles->selected->render($line)
                    : $this->styles->cell->render($line);
            } elseif ($isSelected) {
                $line = Ansi::sgr(Ansi::REVERSE) . $line . Ansi::reset();
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /** Read-only accessor for the rows. Mirrors Bubbles' `Rows()`. */
    public function rowsList(): array { return $this->rows; }

    /** Read-only accessor for the headers. */
    public function headersList(): array { return $this->headers; }

    /** Cursor position (0-indexed). Mirrors Bubbles' `Cursor()`. */
    public function cursor(): int { return $this->cursor; }

    /** Move the cursor to a specific row, clamped. */
    public function setCursor(int $row): self { return $this->moveCursor($row); }

    /**
     * Apply the supplied {@see Styles} to header / cell / selected
     * rendering. Pass null to fall back to the default reverse-video
     * highlight + underlined header. Mirrors Bubbles' `SetStyles`.
     */
    public function withStyles(?Styles $styles): self
    {
        return $this->mutate(styles: $styles, stylesSet: true);
    }

    public function getStyles(): ?Styles { return $this->styles; }

    /**
     * Per-cell styling callback. The closure receives `(int $row, int $col)`
     * and returns the {@see \SugarCraft\Sprinkles\Style} to apply to that
     * cell. The header row is reported as `$row === Table::HEADER_ROW`.
     *
     * The styleFunc runs *per cell*, before the row-level Styles
     * (header / cell / selected) — so you can do striped rows or
     * conditional highlighting per column without losing the
     * selected-row inversion.
     *
     * Pass null to clear the override.
     *
     * Mirrors charmbracelet/bubbles #246 (long-requested upstream
     * feature) and lipgloss / candy-sprinkles `Table::styleFunc()`.
     *
     * @param ?\Closure(int $row, int $col): \SugarCraft\Sprinkles\Style $fn
     */
    public function styleFunc(?\Closure $fn): self
    {
        return $this->mutate(styleFunc: $fn, styleFuncSet: true);
    }

    public function getStyleFunc(): ?\Closure { return $this->styleFunc; }

    /** @return list<string> */
    public function selectedRow(): array
    {
        return $this->rows[$this->cursor] ?? [];
    }

    public function index(): int
    {
        return $this->cursor;
    }

    /**
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        return [$this->mutate(focused: true), null];
    }

    /** Release focus; companion to { focus()}. */
    public function blur(): self
    {
        return $this->mutate(focused: false);
    }

    /** @param list<list<string>> $rows */
    public function setRows(array $rows): self
    {
        return $this->mutate(rows: array_values(array_map('array_values', $rows)))->reclamp();
    }

    /** @param list<string> $headers */
    public function setHeaders(array $headers): self
    {
        return $this->mutate(headers: array_values($headers));
    }

    public function setSize(int $width, int $height): self
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('table width/height must be >= 0');
        }
        return $this->mutate(width: $width, height: $height)->reclamp();
    }

    /** Move the cursor to row 0. Mirrors Bubbles' `GotoTop`. */
    public function gotoTop(): self    { return $this->moveCursor(0); }
    /** Move the cursor to the last row. Mirrors `GotoBottom`. */
    public function gotoBottom(): self { return $this->moveCursor(PHP_INT_MAX); }
    /** Move cursor up `$n` rows. Default 1. */
    public function moveUp(int $n = 1): self   { return $this->moveCursor($this->cursor - max(1, $n)); }
    /** Move cursor down `$n` rows. */
    public function moveDown(int $n = 1): self { return $this->moveCursor($this->cursor + max(1, $n)); }

    // ---- internals ---------------------------------------------------

    /** @return list<int> */
    private function columnWidths(): array
    {
        $cols = max(
            count($this->headers),
            ...array_map('count', $this->rows ?: [[]]),
        );
        if ($cols === 0) {
            return [];
        }
        $widths = array_fill(0, $cols, 0);
        foreach (array_merge([$this->headers], $this->rows) as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], Width::string($cell));
            }
        }
        // Per-column explicit width overrides auto-sizing.
        foreach ($this->colWidths as $i => $w) {
            if ($w > 0) {
                $widths[$i] = $w;
            }
        }
        // If a total width is constrained, shrink columns round-robin
        // (right-to-left) so every line of output fits the budget. The
        // earlier "always trim the rightmost column" rule could still
        // overflow once that column hit zero.
        if ($this->width > 0) {
            $gutter = $cols - 1;
            $budget = max(0, $this->width - $gutter);
            $total  = array_sum($widths);
            while ($total > $budget) {
                $shrunk = false;
                for ($i = $cols - 1; $i >= 0; $i--) {
                    if ($widths[$i] > 0) {
                        $widths[$i]--;
                        $total--;
                        $shrunk = true;
                        if ($total <= $budget) {
                            break;
                        }
                    }
                }
                if (!$shrunk) {
                    // Every column already at zero — nothing more to do.
                    break;
                }
            }
        }
        return $widths;
    }

    /**
     * @param list<string> $row
     * @param list<int>    $widths
     */
    private function renderRow(array $row, array $widths, int $rowIndex = self::HEADER_ROW): string
    {
        $cells = [];
        foreach ($widths as $i => $w) {
            $cell = $row[$i] ?? '';
            $cell = Width::truncate($cell, $w);
            $pad  = $w - Width::string($cell);
            $padded = $cell . str_repeat(' ', max(0, $pad));
            // Apply per-cell styleFunc when set. Runs before any row-level
            // Styles, so striped rows / conditional highlighting compose
            // with the selected-row inversion downstream.
            if ($this->styleFunc !== null) {
                $style = ($this->styleFunc)($rowIndex, $i);
                $padded = $style->render($padded);
            }
            $cells[] = $padded;
        }
        return implode(' ', $cells);
    }

    private function moveCursor(int $idx): self
    {
        $count = count($this->rows);
        if ($count === 0) {
            return $this->mutate(cursor: 0, offset: 0);
        }
        $cursor = max(0, min($count - 1, $idx));
        $offset = $this->offset;
        if ($cursor < $offset) {
            $offset = $cursor;
        }
        if ($this->height > 0 && $cursor >= $offset + $this->height) {
            $offset = $cursor - $this->height + 1;
        }
        return $this->mutate(cursor: $cursor, offset: max(0, $offset));
    }

    private function reclamp(): self
    {
        return $this->moveCursor($this->cursor);
    }

    private function mutate(
        ?array $headers = null,
        ?array $rows = null,
        ?int $cursor = null,
        ?int $offset = null,
        ?int $width = null,
        ?int $height = null,
        ?bool $focused = null,
        ?array $colWidths = null,
        ?Styles $styles = null,
        bool $stylesSet = false,
        ?\Closure $styleFunc = null,
        bool $styleFuncSet = false,
    ): self {
        return new self(
            headers:   $headers   ?? $this->headers,
            rows:      $rows      ?? $this->rows,
            cursor:    $cursor    ?? $this->cursor,
            offset:    $offset    ?? $this->offset,
            width:     $width     ?? $this->width,
            height:    $height    ?? $this->height,
            focused:   $focused   ?? $this->focused,
            colWidths: $colWidths ?? $this->colWidths,
            styles:    $stylesSet ? $styles : $this->styles,
            styleFunc: $styleFuncSet ? $styleFunc : $this->styleFunc,
        );
    }
}

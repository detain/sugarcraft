<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Input;

use SugarCraft\Core\Util\Width;

/**
 * Inline single-line editor that draws its caret as an UNDERLINE on the
 * cluster under it — no real terminal cursor is moved or shown.
 *
 * Mirrors btop `Draw::TextEdit` (src/btop_draw.cpp:179-277): the proc filter
 * bar and the options-menu value editor. It is deliberately distinct from the
 * forms-loop {@see \SugarCraft\Forms\TextInput\TextInput}: that one is a full
 * TEA Model with blink, focus, key-map and validation; this is a pure value
 * object a renderer can splice into any row, which is what btop's overlays need
 * (several editors can be visible while only the hardware cursor is hidden).
 *
 * The caret is counted in GRAPHEME CLUSTERS (btop counts codepoints, `upos`),
 * so a combining sequence or ZWJ emoji moves and deletes as one unit; the
 * {@see view()} budget is counted in display CELLS via {@see Width}, so CJK
 * and emoji clusters cost two columns — btop's `ulen()` without `wide` would
 * overrun a 24-cell field by one column per wide glyph.
 */
final class TextEdit
{
    /** SGR underline on — btop `Fx::ul`. */
    public const UL = "\x1b[4m";

    /** SGR underline off — btop `Fx::uul`. */
    public const UUL = "\x1b[24m";

    private function __construct(
        public readonly string $text,
        public readonly int $caret,
        public readonly bool $numeric,
    ) {
    }

    /**
     * @param ?int $caret cluster index; null puts it at the end (btop's
     *                    constructor sets `pos = text.size()`). Clamped.
     * @param bool $numeric btop `TextEdit(text, numeric)`; see {@see withNumeric()}
     *                      for what happens to non-digits already in $text.
     */
    public static function new(string $text = '', ?int $caret = null, bool $numeric = false): self
    {
        $len = \count(self::clusters($text));
        $t = new self($text, self::clamp($caret ?? $len, $len), false);
        return $numeric ? $t->withNumeric() : $t;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** Caret position as a grapheme-cluster index in [0, length()]. */
    public function caret(): int
    {
        return $this->caret;
    }

    public function numeric(): bool
    {
        return $this->numeric;
    }

    /** Number of grapheme clusters in the text. */
    public function length(): int
    {
        return \count(self::clusters($this->text));
    }

    /**
     * Digits-only mode (btop signal / renice / int option fields).
     *
     * Turning it on strips every non-ASCII-digit cluster already present and
     * keeps the caret on the same surviving digit. btop does not filter its
     * constructor text, but there the seed is always a validated int config
     * value; stripping here keeps the invariant "numeric ⇒ text is digits"
     * true for every caller, so a consumer can parse text() without a guard.
     */
    public function withNumeric(bool $on = true): self
    {
        if (!$on) {
            return $this->mutate(numeric: false);
        }
        $text = '';
        $caret = 0;
        foreach (self::clusters($this->text) as $i => $g) {
            if (ctype_digit($g)) {
                $text .= $g;
                if ($i < $this->caret) {
                    $caret++;
                }
            }
        }
        return new self($text, $caret, true);
    }

    /** Move the caret; out-of-range positions clamp to [0, length()]. */
    public function withCaret(int $pos): self
    {
        return $this->mutate(caret: self::clamp($pos, $this->length()));
    }

    /** Mirrors btop TextEdit::command("left"). */
    public function left(): self
    {
        return $this->withCaret($this->caret - 1);
    }

    /** Mirrors btop TextEdit::command("right"). */
    public function right(): self
    {
        return $this->withCaret($this->caret + 1);
    }

    /** Mirrors btop TextEdit::command("home"). */
    public function home(): self
    {
        return $this->withCaret(0);
    }

    /** Mirrors btop TextEdit::command("end"). */
    public function end(): self
    {
        return $this->withCaret($this->length());
    }

    /**
     * Insert at the caret and advance past the inserted text.
     *
     * In numeric mode anything but ASCII digits is rejected whole and the
     * same instance comes back (btop `isint(key)` → `return false`). The new
     * caret is re-derived by segmenting `prefix . $chars` rather than adding
     * the inserted cluster count, because a combining mark typed after a base
     * letter merges INTO the preceding cluster.
     */
    public function insert(string $chars): self
    {
        if ($chars === '') {
            return $this;
        }
        if ($this->numeric && !ctype_digit($chars)) {
            return $this;
        }
        $c = self::clusters($this->text);
        $head = implode('', \array_slice($c, 0, $this->caret)) . $chars;
        $tail = implode('', \array_slice($c, $this->caret));
        $text = $head . $tail;
        return $this->mutate(
            text: $text,
            caret: self::clamp(\count(self::clusters($head)), \count(self::clusters($text))),
        );
    }

    /** Delete the cluster BEFORE the caret. Mirrors btop command("backspace"). */
    public function backspace(): self
    {
        if ($this->caret === 0) {
            return $this;
        }
        $c = self::clusters($this->text);
        array_splice($c, $this->caret - 1, 1);
        return $this->mutate(text: implode('', $c), caret: $this->caret - 1);
    }

    /** Delete the cluster UNDER the caret. Mirrors btop command("delete"). */
    public function delete(): self
    {
        $c = self::clusters($this->text);
        if ($this->caret >= \count($c)) {
            return $this;
        }
        array_splice($c, $this->caret, 1);
        return $this->mutate(text: implode('', $c));
    }

    /**
     * Empty the field. btop's `clear()` leaves `pos`/`upos` pointing past the
     * end of the now-empty string; resetting the caret here avoids that.
     */
    public function clear(): self
    {
        return $this->mutate(text: '', caret: 0);
    }

    /**
     * Render inline within $limit display cells, windowing around the caret.
     *
     * Mirrors btop `Draw::TextEdit::operator()(limit)`. The cluster under the
     * caret is wrapped in {@see UL}…{@see UUL}; with the caret past the end an
     * underlined space is appended. When the text does not fit, a window is
     * carved with btop's half-budget law (`half = round(limit / 2)`):
     *
     *  - fewer than `half` cells from the caret to the end → show the whole
     *    tail and as much of the head as fits;
     *  - at most `half` cells before the caret → show the whole head and as
     *    much of the tail as fits;
     *  - otherwise the `half` cells before the caret, then the tail.
     *
     * Deviation: btop's `limit` excludes the trailing caret cell, so an
     * end-caret render is `limit + 1` columns wide. Here the caret cell is
     * charged against the budget, so output never exceeds $limit — except
     * when a single wide cluster under the caret is itself wider than the
     * remaining room (limit < 2), where the caret must stay visible.
     *
     * @param int $limit cell budget; <= 0 means unlimited (btop's default 0).
     */
    public function view(int $limit = 0): string
    {
        if ($this->text === '') {
            return self::UL . ' ' . self::UUL;
        }
        $c = self::clusters($this->text);
        $n = \count($c);
        $w = array_map(static fn(string $g): int => Width::string($g), $c);
        $atEnd = $this->caret === $n;
        $total = array_sum($w) + ($atEnd ? 1 : 0);

        $from = 0;
        $to = $n;
        if ($limit > 0 && $total > $limit) {
            $caret = $this->caret;
            $half = (int) round($limit / 2);
            $headW = array_sum(\array_slice($w, 0, $caret));
            $tailW = $total - $headW;

            if ($atEnd || $tailW < $half) {
                $to = $n;
                $from = self::fitBackward($w, $caret, $limit - $tailW);
            } elseif ($headW <= $half) {
                $from = 0;
                $to = self::fitForward($w, $caret, $limit - $headW);
            } else {
                $from = self::fitBackward($w, $caret, $half);
                $used = array_sum(\array_slice($w, $from, $caret - $from));
                $to = self::fitForward($w, $caret, $limit - $used);
            }
            // The caret cluster is the one thing the window may not drop.
            // Forcing it in can overrun the budget when it is wide and the
            // room left was a single cell, so give the overrun back from the
            // head (only a lone wide caret cluster with $limit < 2 survives).
            if (!$atEnd && $to <= $caret) {
                $to = $caret + 1;
                $shown = array_sum(\array_slice($w, $from, $to - $from));
                while ($shown > $limit && $from < $caret) {
                    $shown -= $w[$from];
                    $from++;
                }
            }
        }

        $before = implode('', \array_slice($c, $from, $this->caret - $from));
        if ($atEnd) {
            return $before . self::UL . ' ' . self::UUL;
        }
        $after = implode('', \array_slice($c, $this->caret + 1, $to - $this->caret - 1));
        return $before . self::UL . $c[$this->caret] . self::UUL . $after;
    }

    /**
     * Earliest index $from such that clusters [$from, $end) fit in $budget cells.
     *
     * @param list<int> $w
     */
    private static function fitBackward(array $w, int $end, int $budget): int
    {
        $from = $end;
        while ($from > 0 && $w[$from - 1] <= $budget) {
            $budget -= $w[$from - 1];
            $from--;
        }
        return $from;
    }

    /**
     * Exclusive end index such that clusters [$start, $to) fit in $budget cells.
     *
     * @param list<int> $w
     */
    private static function fitForward(array $w, int $start, int $budget): int
    {
        $to = $start;
        $n = \count($w);
        while ($to < $n && $w[$to] <= $budget) {
            $budget -= $w[$to];
            $to++;
        }
        return $to;
    }

    /**
     * Split on grapheme-cluster boundaries with the same splitter
     * {@see Width} measures by, so caret moves and cell budgets agree.
     *
     * @return list<string>
     */
    private static function clusters(string $s): array
    {
        $out = [];
        $len = \strlen($s);
        for ($i = 0; $i < $len;) {
            $g = Width::nextCluster($s, $i);
            $out[] = $g;
            $i += \strlen($g);
        }
        return $out;
    }

    private static function clamp(int $pos, int $len): int
    {
        return max(0, min($pos, $len));
    }

    private function mutate(?string $text = null, ?int $caret = null, ?bool $numeric = null): self
    {
        return new self(
            $text ?? $this->text,
            $caret ?? $this->caret,
            $numeric ?? $this->numeric,
        );
    }
}

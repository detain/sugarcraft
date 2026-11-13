<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Table;

use SugarCraft\Flexbox\Support\Sgr;

/**
 * Column definition for a Table.
 *
 * Defines a single column: title, width, alignment, per-column ANSI style
 * (applied to every data cell of that column at render — the SGR parameter
 * string, e.g. '31' or '1;38;5;196', wrapped as `CSI <style> m`), formatter,
 * and optional sort.
 *
 * Instances are immutable: every `with*` and the sort-state mutators return a
 * NEW Column (house style — see sugar-style/src/Style.php); `new()` is the
 * entry point (`make()` is its BC alias).
 */
final class Column
{
    /** The alignment vocabulary padHeader()/padded() actually implement. */
    private const ALIGNMENTS = ['left', 'right', 'center'];

    /** Sanitized column title, rendered into the header line. */
    public readonly string $title;

    /** @var callable(string $value, int $rowIndex): string|null */
    private $formatter;

    /** Sort direction: +1 = asc, -1 = desc, 0 = none */
    private int $sortDir = 0;
    private int $sortPriority = 0;

    private function __construct(
        string $title,
        public readonly int $width,
        public readonly string $align = 'left',
        public readonly string $ansiStyle = '',
        ?callable $formatter = null,
    ) {
        // Width is a cell budget consumed by str_repeat/Width padding: a
        // negative value produced negative-count repeats (ValueError at
        // render, far from the cause). 0 = a hidden column, legal.
        if ($width < 0) {
            throw new \InvalidArgumentException(
                "Column width must be >= 0 cells; got {$width}."
            );
        }
        // Titles are rendered raw into the header line: sanitize at ingress,
        // same trust boundary the data cells already honour. Header colours
        // arrive through Table::withHeaderStyle(), never through the title.
        $this->title = \SugarCraft\Core\Util\Sanitize::untrusted($title);
        $this->formatter = $formatter;
    }

    /**
     * Internal constructor that also preserves sort state.
     * Used by with* methods to create a new instance with modified properties.
     */
    private static function fromState(
        string $title,
        int $width,
        string $align,
        string $ansiStyle,
        ?callable $formatter,
        int $sortDir,
        int $sortPriority,
    ): self {
        $col = new self($title, $width, $align, $ansiStyle, $formatter);
        // Access private properties directly since we're in the same class.
        $col->sortDir = $sortDir;
        $col->sortPriority = $sortPriority;
        return $col;
    }

    public static function new(string $title, int $width): self
    {
        return new self($title, $width);
    }

    /**
     * BC alias of {@see self::new()} — kept for callers written against
     * the original API.
     */
    public static function make(string $title, int $width): self
    {
        return self::new($title, $width);
    }

    /**
     * @param string $align one of 'left', 'right', 'center' — the padding
     *                      vocabulary the renderer implements. Other
     *                      spellings used to fall through to 'left'
     *                      silently; they now throw.
     */
    public function withAlign(string $align): self
    {
        if (!\in_array($align, self::ALIGNMENTS, true)) {
            $vocab = \implode("', '", self::ALIGNMENTS);
            throw new \InvalidArgumentException(
                "Column align must be one of '{$vocab}'; got '{$align}'."
            );
        }
        return self::fromState($this->title, $this->width, $align, $this->ansiStyle, $this->formatter, $this->sortDir, $this->sortPriority);
    }

    /**
     * Set the SGR style applied to every data cell of this column at render
     * (the cursor style is painted OVER this one on a cursor row — see
     * Table::buildLines() — so the selected row stays visually dominant).
     *
     * @param string $ansiStyle bare SGR parameter string: codes 0..255
     *     joined by single ';' ('' clears the style). Everything else —
     *     an ESC, a letter, an out-of-range or zero-padded code, a
     *     doubled or trailing ';', a value above 255 — throws
     *     InvalidArgumentException at this door (Sgr::normalize), which
     *     also canonicalizes the colon sub-param form (38:5:N, 38:2:R:G:B)
     *     to ';' grammar before storing, so the knob cannot be repurposed
     *     as an escape channel and the frame parser never sees a colon.
     */
    public function withStyle(string $ansiStyle): self
    {
        $style = Sgr::normalize($ansiStyle, 'Column style');
        return self::fromState($this->title, $this->width, $this->align, $style, $this->formatter, $this->sortDir, $this->sortPriority);
    }


    /**
     * Attach a cell formatter: `fn(string $storedValue, int $rowIndex)`
     * answering string or null (null keeps the raw value). The answer
     * is checked at format time and a non-string return refuses with
     * this column's title (FBX-35): an array would have silently
     * became 'Array' under the old cast and a plain object died a
     * stack-trace away from the offending formatter.
     */
    public function withFormatter(callable $fn): self
    {
        $clone = clone $this;
        $clone->formatter = $fn;
        return $clone;
    }

    /**
     * Author this column as a sort key. The direction is NORMALIZED to
     * the header-arrow contract: > 0 renders as +1 (ascending), < 0 as
     * -1 (descending), 0 clears the marker.
     *
     * This declaration is the ONLY writer of secondary sort state — a
     * click-driven Table::withSort() owns the primary key and never
     * authors or erases these (FBX-37). On a primary tie the settle
     * walks every declaration in priority order: 1 is the FIRST
     * tiebreaker, and priority 0 means 'authored but unprioritised' —
     * consulted after all numbered ones, in column order. Each
     * secondary leg compares with its own direction, and its arrow
     * shows in the header beside the active column's.
     */
    public function sorted(int $direction = 1, int $priority = 0): self
    {
        $clone = clone $this;
        $clone->sortDir = $direction > 0 ? 1 : ($direction < 0 ? -1 : 0);
        $clone->sortPriority = $priority;
        return $clone;
    }

    public function unsorted(): self
    {
        $clone = clone $this;
        $clone->sortDir = 0;
        $clone->sortPriority = 0;
        return $clone;
    }

    public function format(string $value, int $rowIndex): string
    {
        $result = ($this->formatter !== null)
            ? ($this->formatter)($value, $rowIndex)
            : $value;

        if ($result === null) {
            $result = $value;
        } elseif (!\is_string($result)) {
            // Parse, don't coerce: the contract answers string-or-null,
            // and every other shape is the CALLER'S bug. The old (string)
            // cast turned arrays into the literal 'Array' with nothing
            // but a log warning, and killed __toString-less objects deep
            // inside render() — far from the formatter that lied (FBX-35).
            $type = \is_object($result) ? \get_class($result) : \gettype($result);
            throw new \InvalidArgumentException(
                "Formatter for column '{$this->title}' must return a string or null; {$type} given."
            );
        }

        // Sanitize data-origin content. Width clamping is done in padded().
        return $this->sanitize($result);
    }

    /**
     * Neutralize data-origin cell content before it reaches the terminal.
     *
     * Delegates to the canonical {@see \SugarCraft\Core\Util\Sanitize::untrusted()}
     * — `Ansi::strip()` over the whole ECMA-48 family in BOTH 7-bit and 8-bit
     * form (CSI/OSC/SGR + DCS/SOS/PM/APC payloads + lone C1 bytes, fail-closed
     * on an unterminated sequence) followed by a C0-minus-{\t,\n,\r} + DEL sweep,
     * valid UTF-8 (e.g. CJK `東京`) preserved.
     *
     * A full strip — INCLUDING \x1b[...m SGR — is correct here, not
     * over-aggressive, because the library adds its OWN styling DOWNSTREAM of
     * this method: {@see Table::buildLines()} wraps the `padded()` output (which
     * already ran through here) in `applyStyle()`, and `padded()` truncates/pads
     * by ANSI-aware `Width::`. So a hostile or stale escape living in the raw
     * value must never reach the terminal, while the cell's real colour is
     * applied afterward and is untouched. The pre-hardening hand-rolled regexes
     * this replaces were \x1b-only: blind to 8-bit C1 (`\x9b` CSI → cursor-move /
     * sixel / title-set without ever using \x1b) and they leaked unterminated
     * DCS/APC payload text.
     */
    private function sanitize(string $s): string
    {
        // Control bytes must not survive into a cell: an embedded newline
        // or tab forges extra physical lines/columns on the terminal that
        // the cell grid never sees, desynchronising full-frame from delta
        // paint for the rest of the session. Line breaking is the
        // container's job; a cell contributes one visual line only.
        return \str_replace(
            ["\r\n", "\n", "\t", "\r"],
            [' ', ' ', ' ', ''],
            \SugarCraft\Core\Util\Sanitize::untrusted($s),
        );
    }

    public function padded(string $value, int $rowIndex): string
    {
        $v = $this->format($value, $rowIndex);

        // Clamp to display width using ANSI-aware truncation (no mid-grapheme cuts).
        $v = \SugarCraft\Core\Util\Width::truncateAnsi($v, $this->width);

        // Pad using Width methods for correct visual alignment.
        return match ($this->align) {
            'right'  => \SugarCraft\Core\Util\Width::padLeft($v, $this->width),
            'center' => \SugarCraft\Core\Util\Width::padCenter($v, $this->width),
            default  => \SugarCraft\Core\Util\Width::padRight($v, $this->width),
        };
    }

    public function sortDir(): int  { return $this->sortDir; }
    public function sortPriority(): int { return $this->sortPriority; }
}

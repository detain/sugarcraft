<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Table;

/**
 * Column definition for a Table.
 *
 * Defines a single column: title, width, alignment, formatter, and optional sort.
 */
final class Column
{
    /** @var callable(string $value, int $rowIndex): string|null */
    private $formatter;

    /** Sort direction: +1 = asc, -1 = desc, 0 = none */
    private int $sortDir = 0;
    private int $sortPriority = 0;

    private function __construct(
        public readonly string $title,
        public readonly int $width,
        public readonly string $align = 'left',
        public readonly string $ansiStyle = '',
        ?callable $formatter = null,
    ) {
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

    public static function make(string $title, int $width): self
    {
        return new self($title, $width);
    }

    public function withAlign(string $align): self
    {
        return self::fromState($this->title, $this->width, $align, $this->ansiStyle, $this->formatter, $this->sortDir, $this->sortPriority);
    }

    public function withStyle(string $ansiStyle): self
    {
        self::assertSgrParams($ansiStyle);
        return self::fromState($this->title, $this->width, $this->align, $ansiStyle, $this->formatter, $this->sortDir, $this->sortPriority);
    }

    /**
     * Reject any style that is not a bare SGR parameter string.
     *
     * ansiStyle is an ANSI style intended to be interpolated raw into a
     * `CSI <style> m` sequence, so a caller-supplied value containing an ESC,
     * an OSC/DCS introducer, or arbitrary letters could terminate the SGR early
     * and inject attacker-controlled terminal control sequences. Constrain the
     * input to digits and ';' at the setter (empty string = "no style" is
     * still permitted).
     */
    private static function assertSgrParams(string $style): void
    {
        if (\preg_match('/^[0-9;]*$/', $style) !== 1) {
            throw new \InvalidArgumentException(
                'Style must be a bare SGR parameter string matching /^[0-9;]*$/ (digits and ";" only).'
            );
        }
    }

    public function withFormatter(callable $fn): self
    {
        $clone = clone $this;
        $clone->formatter = $fn;
        return $clone;
    }

    public function sorted(int $direction = 1, int $priority = 0): self
    {
        $clone = clone $this;
        $clone->sortDir = $direction;
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
        }

        // Sanitize data-origin content. Width clamping is done in padded().
        return $this->sanitize((string) $result);
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
     * DCS/APC payload text. See docs/research/ansi-tmux-ansicode-audit.md #9.
     */
    private function sanitize(string $s): string
    {
        return \SugarCraft\Core\Util\Sanitize::untrusted($s);
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

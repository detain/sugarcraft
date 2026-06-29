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
        return self::fromState($this->title, $this->width, $this->align, $ansiStyle, $this->formatter, $this->sortDir, $this->sortPriority);
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
     * Strip dangerous control characters from content destined for the terminal.
     *
     * Removes C0 controls (0x00-0x08, 0x0B-0x1F), C1 escape (0x7F),
     * and OSC/DCS sequences (0x80-0x9F, bare ESC introducers) that could
     * corrupt terminal state or enable injection attacks. Library-emitted
     * SGR sequences (\x1b[...m) are preserved as they are added downstream
     * of sanitize via applyStyle.
     */
    private function sanitize(string $s): string
    {
        // Remove OSC sequences (ESC ] ... BEL or ESC \).
        $s = \preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $s);
        // Remove DCS sequences (ESC P ... ESC \).
        $s = \preg_replace('/\x1bP[^\x1b]*(?:\x1b\\\\)/', '', $s);
        // Remove bare ESC introducers not followed by [ (not CSI).
        $s = \preg_replace('/\x1b(?!\[)/', '', $s);
        // Remove C0 controls except HT (0x09) and LF (0x0A).
        $s = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        // Remove C1 controls (0x7F, 0x80-0x9F).
        $s = \preg_replace('/[\x7F\x80-\x9F]/', '', $s);
        return $s;
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

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
    public readonly string $title;
    public readonly int $width;
    public string $align;       // 'left' | 'center' | 'right' — mutated via withAlign() on clones
    public string $ansiStyle;   // ANSI style for header cells — mutated via withStyle() on clones

    /** @var callable(string $value, int $rowIndex): string|null */
    private $formatter;

    /** Sort direction: +1 = asc, -1 = desc, 0 = none */
    private int $sortDir = 0;
    private int $sortPriority = 0;

    private function __construct(
        string $title,
        int $width,
        string $align = 'left',
        string $ansiStyle = '',
        ?callable $formatter = null,
    ) {
        $this->title     = $title;
        $this->width     = $width;
        $this->align     = $align;
        $this->ansiStyle = $ansiStyle;
        $this->formatter = $formatter;
    }

    public static function make(string $title, int $width): self
    {
        return new self($title, $width);
    }

    public function withAlign(string $align): self
    {
        $clone = clone $this;
        $clone->align = $align;
        return $clone;
    }

    public function withStyle(string $ansiStyle): self
    {
        $clone = clone $this;
        $clone->ansiStyle = $ansiStyle;
        return $clone;
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
        $clone->sortDir     = $direction;
        $clone->sortPriority = $priority;
        return $clone;
    }

    public function unsorted(): self
    {
        $clone = clone $this;
        $clone->sortDir     = 0;
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

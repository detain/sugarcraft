<?php

declare(strict_types=1);

namespace SugarCraft\Table;

use SugarCraft\Core\Util\{Ansi, Width};

/**
 * A table column with key, title, width, and optional style.
 *
 * Port of Evertras/bubble-table Column.
 *
 * @see https://github.com/Evertras/bubble-table
 */
final class Column
{
    public readonly string $key;        // unique identifier
    public readonly string $title;      // display header
    public readonly int $width;         // total cell width

    /** 0 = fixed, >0 = flexible width share */
    public readonly int $flexibleWidth;

    /** Hard cap for horizontal scrolling. 0 = no max. */
    public readonly int $maxWidth;

    /** Whether this column participates in filtering. */
    public readonly bool $filterable;

    /** Left-align instead of right-align. */
    public readonly bool $alignLeft;

    /** Column-level ANSI style. */
    public readonly string $style;

    /** Column width specification (Fixed/Percent/Dynamic/Content). */
    public readonly ColumnWidth $columnWidth;

    /** Percentage value (0-100) when columnWidth is Percent. */
    public readonly float $percentValue;

    /** Text wrapping mode. */
    public readonly WrapMode $wrapMode;

    private function __construct(
        string $key,
        string $title,
        int $width,
        int $flexibleWidth = 0,
        int $maxWidth = 0,
        bool $filterable = false,
        bool $alignLeft = false,
        string $style = '',
        ColumnWidth $columnWidth = null,
        float $percentValue = 0.0,
        WrapMode $wrapMode = null,
    ) {
        $this->key           = $key;
        $this->title         = $title;
        $this->width         = $width;
        $this->flexibleWidth = $flexibleWidth;
        $this->maxWidth      = $maxWidth;
        $this->filterable    = $filterable;
        $this->alignLeft     = $alignLeft;
        $this->style         = $style;
        $this->columnWidth   = $columnWidth ?? ColumnWidth::Fixed;
        $this->percentValue  = $percentValue;
        $this->wrapMode      = $wrapMode ?? WrapMode::None;
    }

    public static function new(string $key, string $title, int $width): self
    {
        return new self($key, $title, $width);
    }

    public function withFlexibleWidth(int $share): self
    {
        return new self($this->key, $this->title, $this->width, $share, $this->maxWidth, $this->filterable, $this->alignLeft, $this->style, $this->columnWidth, $this->percentValue, $this->wrapMode);
    }

    public function withMaxWidth(int $max): self
    {
        return new self($this->key, $this->title, $this->width, $this->flexibleWidth, $max, $this->filterable, $this->alignLeft, $this->style, $this->columnWidth, $this->percentValue, $this->wrapMode);
    }

    public function withFilterable(bool $v = true): self
    {
        return new self($this->key, $this->title, $this->width, $this->flexibleWidth, $this->maxWidth, $v, $this->alignLeft, $this->style, $this->columnWidth, $this->percentValue, $this->wrapMode);
    }

    public function withAlignLeft(bool $v = true): self
    {
        return new self($this->key, $this->title, $this->width, $this->flexibleWidth, $this->maxWidth, $this->filterable, $v, $this->style, $this->columnWidth, $this->percentValue, $this->wrapMode);
    }

    public function withStyle(string $ansiStyle): self
    {
        return new self($this->key, $this->title, $this->width, $this->flexibleWidth, $this->maxWidth, $this->filterable, $this->alignLeft, $ansiStyle, $this->columnWidth, $this->percentValue, $this->wrapMode);
    }

    public function withColumnWidth(ColumnWidth $columnWidth, float $percentValue = 0.0): self
    {
        if ($columnWidth === ColumnWidth::Percent && ($percentValue < 0.0 || $percentValue > 100.0)) {
            throw new \InvalidArgumentException('Percent value must be between 0.0 and 100.0');
        }
        return new self($this->key, $this->title, $this->width, $this->flexibleWidth, $this->maxWidth, $this->filterable, $this->alignLeft, $this->style, $columnWidth, $percentValue, $this->wrapMode);
    }

    public function withWrapMode(WrapMode $wrapMode): self
    {
        return new self($this->key, $this->title, $this->width, $this->flexibleWidth, $this->maxWidth, $this->filterable, $this->alignLeft, $this->style, $this->columnWidth, $this->percentValue, $wrapMode);
    }

    /**
     * Build the header cell content, padded to $totalWidth.
     *
     * @return string
     */
    public function renderHeader(int $totalWidth = 0): string
    {
        $w = $totalWidth > 0 ? $totalWidth : $this->width;
        // Sanitize title; pad() handles display-width truncation via Width helpers
        $title = Sanitize::value($this->title, false);
        return $this->pad($title, $w, $this->alignLeft);
    }

    /**
     * Render a cell value as one or more lines, applying wrapping based on WrapMode.
     *
     * @param mixed $value
     * @return list<string> One or more lines
     */
    public function renderCell(mixed $value, int $width = 0): array
    {
        $w = $width > 0 ? $width : $this->width;
        $str = \is_object($value) && method_exists($value, '__toString') ? (string) $value : (\is_scalar($value) ? (string) $value : '');
        // Sanitize before wrapping (multiline context preserves \n for explode callers)
        $str = Sanitize::value($str, true);

        $lines = $this->wrapText($str, $w);
        $result = [];
        $lineCount = \count($lines);
        $lastIdx = $lineCount - 1;
        foreach ($lines as $idx => $line) {
            // For character wrap, don't pad the last line if wrapping actually happened
            // (i.e., there were multiple lines and the last one is shorter by design)
            $wrappingOccurred = $lineCount > 1;
            $isLastLine = $idx === $lastIdx;
            $skipPad = $this->wrapMode === WrapMode::Character && $wrappingOccurred && $isLastLine;
            $padded = $skipPad ? $line : $this->pad($line, $w, $this->alignLeft);
            if ($this->style !== '') {
                $padded = $this->ansi($padded, $this->style);
            }
            $result[] = $padded;
        }
        return $result;
    }

    /**
     * Wrap text according to WrapMode, returning one or more lines.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        return match ($this->wrapMode) {
            WrapMode::None => $this->wrapNone($text, $width),
            WrapMode::WordWrap => $this->wrapWord($text, $width),
            WrapMode::Character => $this->wrapCharacter($text, $width),
        };
    }

    /**
     * No wrapping — truncate to column width.
     *
     * @return list<string>
     */
    private function wrapNone(string $text, int $width): array
    {
        if ($width <= 0) {
            return [''];
        }
        if (Width::of($text) <= $width) {
            return [$text];
        }
        return [Width::truncate($text, $width)];
    }

    /**
     * Wrap at word boundaries.
     *
     * @return list<string>
     */
    private function wrapWord(string $text, int $width): array
    {
        if ($width <= 0) {
            return [''];
        }
        if (Width::of($text) <= $width) {
            return [$text];
        }

        // Width::wrap returns the text with \n between lines
        $wrapped = Width::wrap($text, $width);
        return $wrapped === '' ? [''] : \explode("\n", $wrapped);
    }

    /**
     * Wrap at character boundaries.
     * Last chunk may be shorter than width — no padding applied.
     *
     * @return list<string>
     */
    private function wrapCharacter(string $text, int $width): array
    {
        if ($width <= 0) {
            return [''];
        }
        if (Width::of($text) <= $width) {
            return [$text];
        }

        $clusters = \function_exists('grapheme_str_split')
            ? \grapheme_str_split($text)
            : (\function_exists('mb_str_split')
                ? \mb_str_split($text, 1, 'UTF-8')
                : (\preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        $lines = [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($clusters as $cluster) {
            $clusterWidth = Width::of($cluster);
            if ($currentWidth + $clusterWidth > $width) {
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                }
                $currentLine = $cluster;
                $currentWidth = $clusterWidth;
            } else {
                $currentLine .= $cluster;
                $currentWidth += $clusterWidth;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function pad(string $text, int $width, bool $leftAlign): string
    {
        // Truncate to display width first, then pad to fill the target width
        $text = Width::truncate($text, $width);
        return $leftAlign
            ? Width::padRight($text, $width)
            : Width::padLeft($text, $width);
    }

    private function ansi(string $text, string $codes): string
    {
        if ($codes === '') return $text;
        return Ansi::CSI . $codes . 'm' . $text . Ansi::reset();
    }
}

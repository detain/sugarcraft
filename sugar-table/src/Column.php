<?php

declare(strict_types=1);

namespace SugarCraft\Table;

use SugarCraft\Core\Util\Width;

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

    /** Column width specification (Fixed/Percent/Dynamic/Content/Flex). */
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
        ?ColumnWidth $columnWidth = null,
        float $percentValue = 0.0,
        ?WrapMode $wrapMode = null,
    ) {
        // Column contract is width >= 0 (audit ST-09): a negative seed used to
        // sail through the immutable builder and detonate deep inside the
        // renderer as an OutOfBoundsException — QA's probe now fails loud at
        // the boundary. The ctor is the choke point every with*() clone
        // passes through, so the guard covers all entry.
        if ($width < 0) {
            throw new \InvalidArgumentException("Column '{$key}' width must be >= 0, got {$width}");
        }

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
        return new self(key: $key, title: $title, width: $width);
    }

    public function withFlexibleWidth(int $share): self
    {
        return new self(key: $this->key, title: $this->title, width: $this->width, flexibleWidth: $share, maxWidth: $this->maxWidth, filterable: $this->filterable, alignLeft: $this->alignLeft, style: $this->style, columnWidth: $this->columnWidth, percentValue: $this->percentValue, wrapMode: $this->wrapMode);
    }

    /**
     * Alias of withFlexibleWidth() under the upstream flex-width name, so
     * ports from Go read 1:1. Mirrors Evertras/bubble-table.NewFlexColumn's
     * flexFactor / the upstream FlexWidth naming.
     *
     * Like its primary, this sets the flex WEIGHT only — the column must
     * still declare `ColumnWidth::Flex` (or `Dynamic`) via withWidthMode();
     * a weight on a Fixed/Percent/Content column is measured but never
     * honoured, exactly like a stale percent outside Percent mode.
     */
    public function withFlexWidth(int $share): self
    {
        return $this->withFlexibleWidth($share);
    }

    public function withMaxWidth(int $max): self
    {
        return new self(key: $this->key, title: $this->title, width: $this->width, flexibleWidth: $this->flexibleWidth, maxWidth: $max, filterable: $this->filterable, alignLeft: $this->alignLeft, style: $this->style, columnWidth: $this->columnWidth, percentValue: $this->percentValue, wrapMode: $this->wrapMode);
    }

    public function withFilterable(bool $v = true): self
    {
        return new self(key: $this->key, title: $this->title, width: $this->width, flexibleWidth: $this->flexibleWidth, maxWidth: $this->maxWidth, filterable: $v, alignLeft: $this->alignLeft, style: $this->style, columnWidth: $this->columnWidth, percentValue: $this->percentValue, wrapMode: $this->wrapMode);
    }

    public function withAlignLeft(bool $v = true): self
    {
        return new self(key: $this->key, title: $this->title, width: $this->width, flexibleWidth: $this->flexibleWidth, maxWidth: $this->maxWidth, filterable: $this->filterable, alignLeft: $v, style: $this->style, columnWidth: $this->columnWidth, percentValue: $this->percentValue, wrapMode: $this->wrapMode);
    }

    public function withStyle(string $ansiStyle): self
    {
        return new self(key: $this->key, title: $this->title, width: $this->width, flexibleWidth: $this->flexibleWidth, maxWidth: $this->maxWidth, filterable: $this->filterable, alignLeft: $this->alignLeft, style: $ansiStyle, columnWidth: $this->columnWidth, percentValue: $this->percentValue, wrapMode: $this->wrapMode);
    }

    public function withColumnWidth(ColumnWidth $columnWidth, float $percentValue = 0.0): self
    {
        // NAN slips past a bare range check (it compares false against both
        // ends), so non-finite inputs are fronted explicitly: a NAN percent
        // used to floor() into nonsense widths at solve time (audit #10).
        // INF was already caught by the range legs and stays caught.
        if ($columnWidth === ColumnWidth::Percent && (!\is_finite($percentValue) || $percentValue < 0.0 || $percentValue > 100.0)) {
            throw new \InvalidArgumentException('Percent value must be between 0.0 and 100.0');
        }

        return new self(key: $this->key, title: $this->title, width: $this->width, flexibleWidth: $this->flexibleWidth, maxWidth: $this->maxWidth, filterable: $this->filterable, alignLeft: $this->alignLeft, style: $this->style, columnWidth: $columnWidth, percentValue: $columnWidth === ColumnWidth::Percent ? $percentValue : 0.0, wrapMode: $this->wrapMode);
    }

    /**
     * The single spelling for (re)declaring a column's width mechanism
     * (audit ST-27, findings 027+069): mode plus, for Percent only, the
     * percentage. A non-Percent mode discards any stale percent — the
     * ghost value that used to survive some withers and die in others;
     * the resolver gates percent reads on the mode, so what is stored for
     * other modes could never be honoured, only forgotten silently.
     *
     * Percent demands an explicit number: a 0% column was never a
     * meaningful declaration, and announcing it at the boundary beats a
     * zero-width slot discovered at render (contrast withColumnWidth()'s
     * legacy default of 0.0, kept byte-compatible for existing callers).
     */
    public function withWidthMode(ColumnWidth $mode, ?float $percent = null): self
    {
        if ($mode === ColumnWidth::Percent && $percent === null) {
            throw new \InvalidArgumentException('Percent width mode requires an explicit percent value');
        }

        return $this->withColumnWidth($mode, $percent ?? 0.0);
    }

    public function withWrapMode(WrapMode $wrapMode): self
    {
        return new self(key: $this->key, title: $this->title, width: $this->width, flexibleWidth: $this->flexibleWidth, maxWidth: $this->maxWidth, filterable: $this->filterable, alignLeft: $this->alignLeft, style: $this->style, columnWidth: $this->columnWidth, percentValue: $this->percentValue, wrapMode: $wrapMode);
    }

    /**
     * Build the header cell content, padded to $totalWidth.
     *
     * Part of the Table render pipeline — the canvas walk calls it per
     * header cell; host code should drive output through Table::View(),
     * not compose header strings by hand (audit ST-29).
     *
     * @internal
     * @return string
     */
    public function renderHeader(?int $totalWidth = null, bool $forMarkedFrames = false): string
    {
        $w = ($totalWidth !== null && $totalWidth > 0) ? $totalWidth : $this->width;
        // Sanitize title; pad() handles display-width truncation via Width
        // helpers. $forMarkedFrames routes the ST-47 strictening threaded by
        // Table for hosts compositing the frame into zone/image arenas.
        $title = Sanitize::value($this->title, false, $forMarkedFrames);
        return $this->pad($title, $w, $this->alignLeft);
    }

    /**
     * Render a cell value as one or more PLAIN-TEXT lines, applying wrapping
     * based on WrapMode.
     *
     * Styling contract: the column's ANSI style is never embedded into the
     * returned text. It travels as style metadata — Table composes
     * base < column < row < cell into a buffer Cell style at write time.
     * Embedding raw SGR bytes here would let fillCellContent measure and
     * write the escape characters as literal cells, destroying the content
     * (audit finding #2).
     *
     * @internal Part of the Table render pipeline (audit ST-29); hosts supply
     *           data and styles, View() drives this.
     * @param mixed $value
     * @return list<string> One or more plain-text lines, padded to $w
     */
    public function renderCell(mixed $value, ?int $width = null, bool $forMarkedFrames = false): array
    {
        $w = ($width !== null && $width > 0) ? $width : $this->width;
        $str = \is_object($value) && method_exists($value, '__toString') ? (string) $value : (\is_scalar($value) ? (string) $value : '');
        // Sanitize before wrapping; \n is PRESERVED here because renderCell
        // itself is the newline authority: each "\n" segment is a hard line
        // break, wrapped independently. Letting a raw \n survive into the
        // returned "line" strings would leak it into a buffer cell, where
        // toAnsi() paints a real newline and desyncs the whole frame
        // (re-audit finding on the fix wave, same family as audit #2).
        $str = Sanitize::value($str, true, $forMarkedFrames);

        $segments = \explode("\n", $str);
        $lines = [];
        foreach ($segments as $segment) {
            foreach ($this->wrapText($segment, $w) as $line) {
                $lines[] = $line;
            }
        }
        $result = [];
        $lineCount = \count($lines);
        $lastIdx = $lineCount - 1;
        foreach ($lines as $idx => $line) {
            // Audit ST-17: a wrapped line never leaves this method over-wide
            // and never over-wide silently — an atomic cluster (or an
            // unbreakable word) that exceeds the column is announced with
            // the shared clip marker. The pad() backstop below used to cut
            // such a line without a trace, and the skipPad last-line path
            // let it through to desync the frame.
            if (Width::of($line) > $w) {
                $line = self::clipToWidth($line, $w);
            }
            // For character wrap, don't pad the last line if wrapping actually happened
            // (i.e., there were multiple lines and the last one is shorter by design)
            $wrappingOccurred = $lineCount > 1;
            $isLastLine = $idx === $lastIdx;
            $skipPad = $this->wrapMode === WrapMode::Character && $wrappingOccurred && $isLastLine;
            $result[] = $skipPad ? $line : $this->pad($line, $w, $this->alignLeft);
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
     * No wrapping — truncate to column width with an ellipsis marker.
     *
     * The cut is announced with '…' (U+2026, 1 cell) so truncated content is
     * never silent, matching the maxWidth-cap truncation the table applies to
     * over-wide columns (audit finding #7).
     *
     * @return list<string>
     */
    private function wrapNone(string $text, int $width): array
    {
        return [self::clipToWidth($text, $width)];
    }

    /**
     * Clamp one line of text to a display-width budget, announcing the cut
     * with '…' (U+2026, 1 cell) so truncated content is never silent.
     *
     * Single source of truth for the clip rule: Column's no-wrap path AND
     * Table's single-line rows (WrapMode governs multiline rows only —
     * clamping wrapped content to one line IS a truncation and must be
     * marked) both route through here (audit findings #6/#7/#8). Hosts do
     * not need to call it: View() clips everything you feed it (audit
     * ST-29 note).
     */
    public static function clipToWidth(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (Width::of($text) <= $width) {
            return $text;
        }
        if ($width === 1) {
            return '…';
        }
        return Width::truncate($text, $width - 1) . '…';
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
    /**
     * Split text into clusters for character-level wrapping and width fitting.
     *
     * Single implementation shared by Column's character wrap and Table's
     * width-fit pass so the two cannot drift apart (ST-12). grapheme_str_split
     * exists only on PHP 8.4+/ext-intl; the @ is load-bearing there (it warns
     * and returns false on malformed UTF-8, and we deliberately fall through
     * to a codepoint splitter rather than surface a warning under failOnWarning).
     * Mirrors sugar-core Util\Width::graphemes().
     *
     * @return list<string>
     */
    public static function splitClusters(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (\function_exists('grapheme_str_split')) {
            $clusters = @\grapheme_str_split($text);
            if (\is_array($clusters)) {
                return $clusters;
            }
        }
        if (\function_exists('mb_str_split')) {
            $clusters = \mb_str_split($text, 1, 'UTF-8');
            if (\is_array($clusters)) {
                return $clusters;
            }
        }
        return \preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function wrapCharacter(string $text, int $width): array
    {
        if ($width <= 0) {
            return [''];
        }
        if (Width::of($text) <= $width) {
            return [$text];
        }

        $clusters = self::splitClusters($text);

        $lines = [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($clusters as $cluster) {
            $clusterWidth = Width::of($cluster);
            if ($clusterWidth > $width) {
                // Audit ST-17: an atomic cluster wider than the column (a CJK
                // glyph in a 1-cell slot) no longer survives as-is — graphemes
                // cannot decompose, so the fit arm is the announcing clip: the
                // old code flushed the line and parked the over-budget cluster
                // on it alone, emitting a line wider than the column.
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                    $currentLine = '';
                    $currentWidth = 0;
                }
                $lines[] = self::clipToWidth($cluster, $width);
                continue;
            }
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
        // Clip to display width first — announcing the cut with '…' (audit
        // ST-17: the silent Width::truncate backstop here was how a CJK
        // header in a narrow column vanished into blank padding; callers
        // keep a silent backstop only for text that can no longer arrive
        // over-wide), then pad to fill the target width.
        $text = self::clipToWidth($text, $width);
        return $leftAlign
            ? Width::padRight($text, $width)
            : Width::padLeft($text, $width);
    }
}

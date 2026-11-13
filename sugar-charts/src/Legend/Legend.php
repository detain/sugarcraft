<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Legend;

use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Support\Rune;
use SugarCraft\Charts\Support\UntrustedText;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Renders a chart legend with colored indicators and series labels.
 *
 * The legend displays each series as a colored indicator (block character)
 * followed by its label. It supports four positions: top, bottom, left,
 * and right relative to the chart area.
 *
 * ```php
 * $legend = Legend::new([
 *     ['label' => 'Series A', 'color' => 'red'],
 *     ['label' => 'Series B', 'color' => 'blue'],
 * ])->withPosition(Position::Bottom);
 *
 * echo $legend->view();
 * ```
 */
final class Legend
{
    /**
     * Indicator glyph as stored after the {@see \SugarCraft\Charts\Support\Rune}
     * ingress door (sugar-charts-013): declared and assigned in the
     * constructor body so only the guarded single-cell value can be stored.
     */
    public readonly string $indicatorChar;
    /** @param list<array{label: string, color: string}> $items */
    private function __construct(
        public readonly array $items,
        public readonly Position $position,
        string $indicatorChar,
        public readonly bool $showBorder,
    ) {
        $this->indicatorChar = Rune::cell($indicatorChar, 'legend indicator');
    }

    /**
     * @param list<array{label: string, color: string}> $items
     */
    public static function new(array $items = []): self
    {
        // sugar-charts-097: default Bottom + no border keeps a bare
        // Legend inside the fixed-width pane; the sidecar overshoot is
        // opt-in via withPosition(Position::Left|Right) / withShowBorder(true).
        return new self(UntrustedText::guardItems($items), Position::Bottom, '█', false);
    }

    /**
     * @param list<array{label: string, color: string}> $items
     */
    public function withItems(array $items): self
    {
        return new self(UntrustedText::guardItems($items), $this->position, $this->indicatorChar, $this->showBorder);
    }

    public function withPosition(Position $position): self
    {
        return new self($this->items, $position, $this->indicatorChar, $this->showBorder);
    }

    public function withIndicatorChar(string $char): self
    {
        return new self($this->items, $this->position, UntrustedText::guard($char), $this->showBorder);
    }

    public function withShowBorder(bool $show): self
    {
        return new self($this->items, $this->position, $this->indicatorChar, $show);
    }

    // Short-form aliases.
    /** @param list<array{label: string, color: string}> $items */
    public function items(array $items): self       { return $this->withItems($items); }
    /** Short-form alias for {@see withPosition()}. */
    public function position(Position $position): self { return $this->withPosition($position); }
    /** Short-form alias for {@see withIndicatorChar()}. */
    public function indicatorChar(string $char): self { return $this->withIndicatorChar($char); }
    /** Short-form alias for {@see withShowBorder()}. */
    public function showBorder(bool $show): self    { return $this->withShowBorder($show); }

    public function view(): string
    {
        if ($this->items === []) {
            return '';
        }

        return match ($this->position) {
            Position::Top    => $this->renderTop(),
            Position::Bottom => $this->renderBottom(),
            Position::Left   => $this->renderLeft(),
            Position::Right  => $this->renderRight(),
        };
    }

    public function __toString(): string
    {
        return $this->view();
    }

    private function renderTop(): string
    {
        $parts = [];
        foreach ($this->items as $item) {
            $parts[] = $this->coloredIndicator($item['color']) . ' ' . $item['label'];
        }
        $line = implode('  ', $parts);
        if ($this->showBorder) {
            // borders sized by DISPLAY width — the line carries
            // SGR escapes whose bytes mb_strlen would count. The
            // corners occupy one cell at each end, so the dash run
            // spans the line width minus two and the whole frame is
            // column-aligned with its content row.
            $w = max(0, Width::string($line) - 2);
            return '┌' . str_repeat('─', $w) . '┐' . "\n" . $line . "\n" . '└' . str_repeat('─', $w) . '┘';
        }
        return $line;
    }

    private function renderBottom(): string
    {
        $parts = [];
        foreach ($this->items as $item) {
            $parts[] = $this->coloredIndicator($item['color']) . ' ' . $item['label'];
        }
        $line = implode('  ', $parts);
        if ($this->showBorder) {
            // CH-35 (sugar-charts-014): the bottom position must mirror
            // renderTop's frame math — previously this emitted the TOP
            // corner pair ('┌…┐') at full line width, hanging two
            // columns past the content and lying about which edge was
            // drawn. Same -2 gutter rule as the top frame.
            $w = max(0, Width::string($line) - 2);
            return $line . "\n" . '└' . str_repeat('─', $w) . '┘';
        }
        return $line;
    }

    private function renderLeft(): string
    {
        if ($this->showBorder) {
            return $this->renderSideBox(false);
        }
        $lines = [];
        foreach ($this->items as $item) {
            $lines[] = $this->coloredIndicator($item['color']) . ' ' . $item['label'];
        }
        return implode("\n", $lines);
    }

    private function renderRight(): string
    {
        if ($this->showBorder) {
            return $this->renderSideBox(true);
        }
        $lines = [];
        foreach ($this->items as $item) {
            $lines[] = $this->coloredIndicator($item['color']) . ' ' . $item['label'];
        }
        return implode("\n", $lines);
    }

    /**
     * Bordered box for the Left/Right positions: every content row is
     * padded to the widest one so the closing border lands in a single
     * column, and the box opens with ┌┐ / closes with └┘ corners (the
     * earlier ├┤ tee spelling drew two "middle" joints with no frame
     * above or below them, and the shorter rows' borders came home
     * ragged).
     */
    private function renderSideBox(bool $padInside): string
    {
        $lines = [];
        foreach ($this->items as $item) {
            $content = $this->coloredIndicator($item['color']) . ' ' . $item['label'];
            // The Right gutter pads each row with one space on both sides
            // of the content; Left draws flush.
            $lines[] = $padInside ? ' ' . $content . ' ' : $content;
        }
        $width = max(array_map(fn(string $l): int => Width::string($l), $lines));
        // The dash run spans exactly the padded interior between the two
        // side bars, so corners land over the row ends and the whole box
        // is rectangular (an earlier +2 overhang sat two cells outside
        // every content row).
        $border = fn(string $cornerA, string $cornerB): string =>
            $cornerA . str_repeat('─', $width) . $cornerB;
        $framed = [];
        $framed[] = $border('┌', '┐');
        foreach ($lines as $line) {
            $framed[] = '│' . Width::padRight($line, $width) . '│';
        }
        $framed[] = $border('└', '┘');
        return implode("\n", $framed);
    }

    /**
     * Named-color → SGR prefix table. A compile-time class constant (not a
     * method-local static) because the values are fixed ANSI CSI sequences,
     * identical to Ansi::fg16(n)/Ansi::sgr(39).
     */
    private const COLOR_MAP = [
        'red'     => Ansi::CSI . '31m',
        'green'   => Ansi::CSI . '32m',
        'yellow'  => Ansi::CSI . '33m',
        'blue'    => Ansi::CSI . '34m',
        'magenta' => Ansi::CSI . '35m',
        'cyan'    => Ansi::CSI . '36m',
        'white'   => Ansi::CSI . '37m',
        'default' => Ansi::CSI . '39m',
    ];

    /**
     * The swatch color contract as a Style: named colors from the
     * {@see self::COLOR_MAP} table emit their exact ANSI slot, 3/6-digit
     * hex (with or without `#`) goes truecolor, and anything else —
     * unknown names like 'orange', typos — resolves to null so the
     * consumer renders unstyled, EXACTLY like the swatch itself stays
     * default-foreground (sugar-charts-023: a stroke honoring legend
     * colors must be byte-identical to the promise the swatch makes,
     * in both directions). coloredIndicator keeps its own raw wrapping
     * (`3Xm…39m`) for pinned-byte reasons; the COLOR SGR prefixes of the
     * two surfaces match by construction (LegendSwatchContractTest).
     */
    public static function styleForSwatchColor(string $css): ?Style
    {
        $code = self::COLOR_MAP[$css] ?? null;
        if ($code !== null) {
            if ($code === Ansi::CSI . '39m') {
                return null;
            }
            if (preg_match('/(\d+)m$/', $code, $m) === 1) {
                return Style::new()->foreground(Color::ansi((int) $m[1] - 30));
            }
        }
        if (preg_match('/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $css) === 1) {
            return Style::new()->foreground(Color::hex(ltrim($css, '#')));
        }

        return null;
    }

    /**
     * Whether this render pass must emit no color at all.
     *
     * The legend's raw-CSI and hex paths bypass {@see Style}, so they need
     * their own honouring of the de-facto standard: CLICOLOR_FORCE /
     * FORCE_COLOR (any non-empty, non-"0" value) win outright, otherwise
     * NO_COLOR set to ANY non-empty value — including "0" — disabless
     * (no-color.org; `getenv()` returns FALSE, not '', when unset, so
     * comparing against '' alone would read "unset" as "disable"), and
     * CLICOLOR=0 disables by BSD convention.
     */
    private static function colorSuppressed(): bool
    {
        foreach (['CLICOLOR_FORCE', 'FORCE_COLOR'] as $forceVar) {
            $force = getenv($forceVar);
            if ($force !== false && $force !== '' && $force !== '0') {
                return false;
            }
        }
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return true;
        }

        return getenv('CLICOLOR') === '0';
    }

    /**
     * Wrap the indicator with ANSI color codes: named colors hit the
     * COLOR_MAP, 3- and 6-digit hex hits the truecolor path, and
     * anything else (typos, unknown names) renders in the terminal's
     * default foreground. The silent fallback is deliberate: a legend
     * swatch is decorative chrome, and a guessed color would lie harder
     * than the default one.
     */
    private function coloredIndicator(string $color): string
    {
        if (self::colorSuppressed()) {
            return $this->indicatorChar;
        }

        if (isset(self::COLOR_MAP[$color])) {
            $code = self::COLOR_MAP[$color];
            return $code . $this->indicatorChar . Ansi::sgr(39);
        }

        // shorthand 3-digit hex is a valid CSS color — Color::hex
        // expands 'rgb' → 'rrggbb' itself, so only the gate needed widening.
        if (preg_match('/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color)) {
            $hex = ltrim($color, '#');
            return Style::new()
                ->foreground(Color::hex($hex))
                ->render($this->indicatorChar);
        }

        return Ansi::sgr(39) . $this->indicatorChar . Ansi::sgr(39);
    }
}

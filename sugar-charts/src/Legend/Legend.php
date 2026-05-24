<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Legend;

use SugarCraft\Charts\Chart\Position;
use SugarCraft\Core\Util\Ansi;

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
    /** @param list<array{label: string, color: string}> $items */
    private function __construct(
        public readonly array $items,
        public readonly Position $position,
        public readonly string $indicatorChar,
        public readonly bool $showBorder,
    ) {
    }

    /**
     * @param list<array{label: string, color: string}> $items
     */
    public static function new(array $items = []): self
    {
        return new self($items, Position::Right, '█', true);
    }

    /**
     * @param list<array{label: string, color: string}> $items
     */
    public function withItems(array $items): self
    {
        return new self($items, $this->position, $this->indicatorChar, $this->showBorder);
    }

    public function withPosition(Position $position): self
    {
        return new self($this->items, $position, $this->indicatorChar, $this->showBorder);
    }

    public function withIndicatorChar(string $char): self
    {
        return new self($this->items, $this->position, $char, $this->showBorder);
    }

    public function withShowBorder(bool $show): self
    {
        return new self($this->items, $this->position, $this->indicatorChar, $show);
    }

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
            return '┌' . str_repeat('─', mb_strlen($line, 'UTF-8')) . '┐' . "\n" . $line . "\n" . '└' . str_repeat('─', mb_strlen($line, 'UTF-8')) . '┘';
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
            return $line . "\n" . '┌' . str_repeat('─', mb_strlen($line, 'UTF-8')) . '┐';
        }
        return $line;
    }

    private function renderLeft(): string
    {
        if ($this->showBorder) {
            $lines = [];
            foreach ($this->items as $item) {
                $lines[] = '│' . $this->coloredIndicator($item['color']) . ' ' . $item['label'] . '│';
            }
            $width = max(array_map(fn($l) => mb_strlen($l, 'UTF-8'), $lines));
            $border = '├' . str_repeat('─', $width) . '┤';
            array_unshift($lines, $border);
            $lines[] = $border;
            return implode("\n", $lines);
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
            $lines = [];
            foreach ($this->items as $item) {
                $lines[] = '│ ' . $this->coloredIndicator($item['color']) . ' ' . $item['label'] . ' │';
            }
            $width = max(array_map(fn($l) => mb_strlen($l, 'UTF-8'), $lines));
            $border = '├' . str_repeat('─', $width) . '┤';
            array_unshift($lines, $border);
            $lines[] = $border;
            return implode("\n", $lines);
        }
        $lines = [];
        foreach ($this->items as $item) {
            $lines[] = $this->coloredIndicator($item['color']) . ' ' . $item['label'];
        }
        return implode("\n", $lines);
    }

    /**
     * Wrap a block character with ANSI color codes.
     */
    private function coloredIndicator(string $color): string
    {
        static $colorMap = [
            'red'    => Ansi::fg16(31),   // red foreground
            'green'  => Ansi::fg16(32),   // green foreground
            'yellow' => Ansi::fg16(33),   // yellow foreground
            'blue'   => Ansi::fg16(34),   // blue foreground
            'magenta'=> Ansi::fg16(35),   // magenta foreground
            'cyan'   => Ansi::fg16(36),   // cyan foreground
            'white'  => Ansi::fg16(37),   // white foreground
            'default'=> Ansi::sgr(39),    // default foreground
        ];

        $code = $colorMap[$color] ?? Ansi::sgr(39);
        return $code . $this->indicatorChar . Ansi::sgr(39);
    }
}

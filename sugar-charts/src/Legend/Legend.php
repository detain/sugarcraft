<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Legend;

use SugarCraft\Charts\Chart\Position;

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
        $colorMap = [
            'red'    => "\x1b[31m",
            'green'  => "\x1b[32m",
            'yellow' => "\x1b[33m",
            'blue'   => "\x1b[34m",
            'magenta'=> "\x1b[35m",
            'cyan'   => "\x1b[36m",
            'white'  => "\x1b[37m",
            'default'=> "\x1b[39m",
        ];

        $code = $colorMap[$color] ?? "\x1b[39m";
        return $code . $this->indicatorChar . "\x1b[39m";
    }
}

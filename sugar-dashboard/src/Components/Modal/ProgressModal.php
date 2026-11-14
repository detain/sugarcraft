<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Modal;

use SugarCraft\Dashboard\Foundation\Item;

/**
 * A modal dialog that shows a progress bar.
 */
final class ProgressModal implements Item
{
    public function __construct(
        private readonly int $current,
        private readonly int $total,
        private readonly ?string $label = null,
    ) {}

    public static function new(int $current, int $total, ?string $label = null): self
    {
        return new self(current: $current, total: $total, label: $label);
    }

    public function render(): string
    {
        $percentage = $this->total > 0 ? (int) (($this->current / $this->total) * 100) : 0;
        $barWidth = 30;
        $filled = (int) ($barWidth * $this->current / max(1, $this->total));
        // Finding sugar-dashboard-182 crash leg (D-66): current > total (or
        // a negative current) made a str_repeat count negative - ValueError.
        // The bar holds its width honestly; the percentage above still
        // reports the raw ratio.
        $filled = max(0, min($barWidth, $filled));
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $barWidth - $filled) . ']';

        $result = $bar . ' ' . $percentage . '%';
        if ($this->label !== null) {
            $result .= ' ' . $this->label;
        }
        return $result;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Modal;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\SizedItem;

/**
 * A modal dialog that shows a progress bar.
 */
final class ProgressModal implements Item, SizedItem
{
    public function __construct(
        private readonly int $current,
        private readonly int $total,
        private readonly ?string $label = null,
    ) {}

    private ?int $width = null;
    private ?int $height = null;

    /**
     * Finding sugar-dashboard-182 (D-70 design leg): the modal answers the
     * SizedItem channel; the painted row is clipped to the allocation. The
     * crash leg (negative bar paint) landed already via D-66; there is no
     * with* surface on this class - factories stay the plain builders.
     */
    public function setSize(int $width, int $height): static
    {
        $clone = clone $this;
        if ($width > 0) {
            $clone->width = $width;
        }
        if ($height > 0) {
            $clone->height = $height;
        }
        return $clone;
    }

    /**
     * @return array{0:int,1:int}
     */
    public function innerSize(): array
    {
        return [$this->width ?? Width::string($this->paintLine()), $this->height ?? 1];
    }

    private function paintLine(): string
    {
        return $this->paintLineBody();
    }

    public static function new(int $current, int $total, ?string $label = null): self
    {
        return new self(current: $current, total: $total, label: $label);
    }

    public function render(): string
    {
        $result = $this->paintLine();
        if ($this->width !== null) {
            $result = Width::truncateAnsi($result, $this->width);
        }
        return $result;
    }

    private function paintLineBody(): string
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

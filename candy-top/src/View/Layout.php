<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * The geometry {@see FrameBuilder::layout()} computes — btop's calcSizes
 * outputs (`Cpu::x/y/width/height`, `b_x/b_y/b_width/b_height`,
 * `Mem::divider`, `Net::b_*`, `Proc::select_max`) as one immutable value.
 *
 * Every rectangle is absolute and 0-based. A box that is not shown is null.
 * Gpu boxes (btop PR #1730/#1881) are keyed by their `gpuN` box name in
 * `boxes` like any other box, with their grid geometry in `gpuBoxes`.
 */
final class Layout
{
    /** btop's draw order (`Config::current_boxes` walk in Runner::_runner; PR #1873 draws ctr before proc). */
    public const BOXES = ['cpu', 'mem', 'net', 'ctr', 'proc'];

    /**
     * @param array<string, Rect>   $boxes    shown boxes keyed by name (gpu boxes included)
     * @param array<string, GpuBox> $gpuBoxes shown gpu boxes keyed by name, slot order
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $boxes,
        public readonly ?Rect $cpuCores = null,
        public readonly int $coreColumns = 0,
        public readonly int $coreColumnSize = 0,
        public readonly int $memWidth = 0,
        public readonly int $disksWidth = 0,
        public readonly ?int $memDivider = null,
        public readonly ?Rect $netStats = null,
        public readonly int $procSelectMax = 0,
        public readonly bool $cpuBottom = false,
        public readonly bool $memBelowNet = false,
        public readonly bool $procLeft = false,
        public readonly bool $showDisks = false,
        public readonly array $gpuBoxes = [],
        public readonly int $gpuColumns = 1,
        public readonly int $gpuHeight = 0,
    ) {
    }

    /** The laid-out gpu box named `$name` (`gpu3`), null when not shown. */
    public function gpuBox(string $name): ?GpuBox
    {
        return $this->gpuBoxes[$name] ?? null;
    }

    public function box(string $name): ?Rect
    {
        return $this->boxes[$name] ?? null;
    }

    public function shown(string $name): bool
    {
        return isset($this->boxes[$name]);
    }

    /**
     * Shown boxes in draw order — btop's Runner: cpu, the gpu boxes (slot
     * order), mem, net, ctr, proc.
     *
     * @return array<string, Rect>
     */
    public function ordered(): array
    {
        $out = [];
        foreach (self::BOXES as $name) {
            if (isset($this->boxes[$name])) {
                $out[$name] = $this->boxes[$name];
            }
            if ($name === 'cpu') {
                foreach ($this->gpuBoxes as $gpu => $box) {
                    $out[$gpu] = $box->rect;
                }
            }
        }

        return $out;
    }
}

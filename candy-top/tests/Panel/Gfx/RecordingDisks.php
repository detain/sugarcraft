<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gfx;

use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Panel\Mem\DisksSection;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;

/** DisksSection double: samples while show_disks, paints `DISKS<n>` at its area's origin + 2. */
final class RecordingDisks implements DisksSection
{
    /** @var list<Rect> */
    public static array $areas = [];

    public function __construct(
        public readonly Source $source,
        public readonly ?object $last = null,
    ) {
    }

    public function source(PanelContext $context): ?Source
    {
        return $context->config->bool('show_disks') ? $this->source : null;
    }

    public function withSample(object $snapshot, Source $next, PanelContext $context, ?MemorySnapshot $memory = null): self
    {
        return new self($next, $snapshot);
    }

    public function paint(Region $box, PanelFrame $frame, Rect $area): void
    {
        self::$areas[] = $area;
        $box->put($area->x + 2, 1, 'DISKS' . ($this->last->value ?? '?'));
    }
}

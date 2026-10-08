<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gfx;

use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Cpu\BatteryBadge;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Region;

/** BatteryBadge double: samples a ScriptedSource and paints `BAT<n>` on the top border. */
final class RecordingBadge implements BatteryBadge
{
    public function __construct(
        public readonly Source $source,
        public readonly ?object $last = null,
        public readonly bool $enabled = true,
    ) {
    }

    public function source(PanelContext $context): ?Source
    {
        return $this->enabled && $context->config->bool('show_battery') ? $this->source : null;
    }

    public function withSample(object $snapshot, Source $next, PanelContext $context): self
    {
        return new self($next, $snapshot, $this->enabled);
    }

    public function present(Config $config): bool
    {
        return $this->last !== null && $config->bool('show_battery');
    }

    public function paint(Region $box, PanelFrame $frame): void
    {
        if ($this->last !== null) {
            $box->put($box->width() - 12, 0, 'BAT' . ($this->last->value ?? '?'));
        }
    }
}

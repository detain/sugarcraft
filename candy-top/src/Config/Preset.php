<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

/**
 * A layout preset: the ordered boxes to show, each with placement and graph
 * symbol.
 *
 * Mirrors aristocratos/btop Config::preset_list entries (`p`/`P` cycle them).
 */
final class Preset
{
    /** @param list<PresetBox> $boxes */
    public function __construct(
        public readonly array $boxes,
    ) {
    }

    /**
     * Box names in preset order, the value btop writes to `shown_boxes` when
     * the preset is applied.
     *
     * @return list<string>
     */
    public function boxNames(): array
    {
        return array_map(static fn (PresetBox $b): string => $b->box, $this->boxes);
    }

    /** The preset back in btop's comma-joined `box:P:G,...` spelling. */
    public function toString(): string
    {
        return implode(',', array_map(static fn (PresetBox $b): string => $b->toString(), $this->boxes));
    }
}

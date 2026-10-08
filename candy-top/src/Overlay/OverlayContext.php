<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;

/**
 * What an {@see Overlay} reads on every update() and paint(): the App's
 * CURRENT config, terminal size and theme. Built fresh per call — never
 * cache it (a resize, a tty_mode flip or a theme swap would go stale).
 *
 * The options menu also reads the runtime lists btop's optionsList points
 * at — `$choices` (cpu_graph_upper/lower = Cpu::available_fields,
 * cpu_sensor = available_sensors, selected_battery = available_batteries,
 * from {@see \SugarCraft\Top\Panel\OptionChoices} panels), `$gpu` (a GPU
 * answered: the gpu tab shows) and `$themes` (Theme::themes, scanned at
 * startup and on `ctrl+r`; null = the builtin Default and TTY only).
 */
final class OverlayContext
{
    /**
     * @param array<string, list<string>> $choices
     */
    public function __construct(
        public readonly Config $config,
        public readonly int $cols,
        public readonly int $rows,
        public readonly Ink $ink,
        public readonly array $choices = [],
        public readonly bool $gpu = false,
        public readonly ?ThemeRegistry $themes = null,
    ) {
    }

    /** btop createBox's corners: rounded unless tty mode or rounded_corners off. */
    public function border(): Border
    {
        return FrameBuilder::border($this->config);
    }

    public function tty(): bool
    {
        return $this->config->ttyMode();
    }
}

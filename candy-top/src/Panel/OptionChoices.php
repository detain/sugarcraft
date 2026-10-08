<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

/**
 * A panel that knows runtime values an option may take — btop's
 * collector-filled option lists the options menu cycles through
 * (`Cpu::available_fields`, `Cpu::available_sensors`,
 * `Config::available_batteries`), plus whether a GPU answered (btop's
 * gpu options tab). The App merges every visible-or-not panel's answer
 * into the {@see \SugarCraft\Top\Overlay\OverlayContext}.
 */
interface OptionChoices
{
    /**
     * Option name => the values it may cycle through, in btop's order
     * ("Auto" first where btop seeds it).
     *
     * @return array<string, list<string>>
     */
    public function optionChoices(): array;

    /** GPUs detected so far (0 hides the options menu's gpu tab). */
    public function detectedGpus(): int;
}

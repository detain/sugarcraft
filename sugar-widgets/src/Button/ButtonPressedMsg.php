<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Button;

use SugarCraft\Core\Msg;
use SugarCraft\Widgets\Lang;

/**
 * Emitted (as a Cmd result) when a {@see Button} is activated by Enter,
 * Space or a mouse click. The host matches it in its own `update()`.
 *
 * Antecedent: tview `Form.AddButton` selected callback. SugarCraft
 * original — upstream charmbracelet/bubbles has no Button.
 */
final class ButtonPressedMsg implements Msg
{
    public function __construct(
        public readonly string $label,
        public readonly string $zoneId,
    ) {}

    /** Screen-reader / status-line text for the activation (localised). */
    public function announcement(): string
    {
        return Lang::t('button.pressed', ['label' => $this->label]);
    }
}

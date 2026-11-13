<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Button;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\MsgZoneInBounds;
use SugarCraft\Style\Style;

/**
 * A one-line push button: `[ Label ]`.
 *
 * Activation (Enter or Space while focused, or a click on its mouse zone)
 * makes `update()` return a Cmd that yields a {@see ButtonPressedMsg}; a
 * disabled button is inert to all three. Immutable — every `with*()`
 * returns a new instance.
 *
 * Mouse: attach a sugar-mouse {@see Manager} via {@see withZoneManager()},
 * `scan()` the frame, and route `MsgZoneInBounds` to `update()`. A
 * disabled button is not marked, so it never registers a zone.
 *
 * Antecedent: tview `Button` / `Form.AddButton`. A SugarCraft original —
 * upstream charmbracelet/bubbles has no Button component.
 */
final class Button implements Model
{
    private function __construct(
        public readonly string $label,
        public readonly bool $focused,
        public readonly bool $disabled,
        public readonly Style $style,
        public readonly Style $focusedStyle,
        public readonly Style $disabledStyle,
        public readonly ?Manager $zoneManager,
        public readonly string $zoneId,
    ) {}

    public static function new(string $label): self
    {
        return new self(
            label: self::clean($label),
            focused: false,
            disabled: false,
            style: Style::new(),
            focusedStyle: Style::new()->reverse(),
            disabledStyle: Style::new()->dim(),
            zoneManager: null,
            zoneId: 'button',
        );
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($this->disabled) {
            return [$this, null];
        }
        if ($msg instanceof MsgZoneInBounds) {
            return str_ends_with($msg->zone->id, $this->zoneId)
                ? [$this, $this->pressed()]
                : [$this, null];
        }
        if ($msg instanceof KeyMsg && $this->focused
            && ($msg->type === KeyType::Enter || $msg->type === KeyType::Space)) {
            return [$this, $this->pressed()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $text = '[ ' . $this->label . ' ]';
        $style = $this->disabled ? $this->disabledStyle : ($this->focused ? $this->focusedStyle : $this->style);
        $out = $style->render($text);
        if ($this->zoneManager !== null && !$this->disabled) {
            $out = $this->zoneManager->mark($this->zoneId, $out);
        }
        return $out;
    }

    public function __toString(): string
    {
        return $this->view();
    }

    public function withLabel(string $label): self
    {
        return $this->mutate(label: self::clean($label));
    }

    public function withFocused(bool $focused = true): self
    {
        return $this->mutate(focused: $focused);
    }

    public function focus(): self
    {
        return $this->withFocused(true);
    }

    public function blur(): self
    {
        return $this->withFocused(false);
    }

    public function withDisabled(bool $disabled = true): self
    {
        return $this->mutate(disabled: $disabled);
    }

    /** Per-state styles; null keeps the current one. */
    public function withStyles(?Style $normal = null, ?Style $focused = null, ?Style $disabled = null): self
    {
        return $this->mutate(style: $normal, focusedStyle: $focused, disabledStyle: $disabled);
    }

    public function withZoneManager(?Manager $manager): self
    {
        return $this->mutate(zoneManager: $manager, zoneManagerSet: true);
    }

    /** Zone id minted for the click target; lets one host tell buttons apart. */
    public function withZoneId(string $id): self
    {
        return $this->mutate(zoneId: $id);
    }

    private function pressed(): \Closure
    {
        return Cmd::send(new ButtonPressedMsg($this->label, $this->zoneId));
    }

    /** Frames may carry mouse zones: strip control bytes and forged sentinels. */
    private static function clean(string $label): string
    {
        return Sanitize::untrustedForMarkedFrames(Sanitize::controlChars($label));
    }

    private function mutate(
        ?string $label = null,
        ?bool $focused = null,
        ?bool $disabled = null,
        ?Style $style = null,
        ?Style $focusedStyle = null,
        ?Style $disabledStyle = null,
        ?Manager $zoneManager = null,
        bool $zoneManagerSet = false,
        ?string $zoneId = null,
    ): self {
        return new self(
            label: $label ?? $this->label,
            focused: $focused ?? $this->focused,
            disabled: $disabled ?? $this->disabled,
            style: $style ?? $this->style,
            focusedStyle: $focusedStyle ?? $this->focusedStyle,
            disabledStyle: $disabledStyle ?? $this->disabledStyle,
            zoneManager: $zoneManagerSet ? $zoneManager : $this->zoneManager,
            zoneId: $zoneId ?? $this->zoneId,
        );
    }
}

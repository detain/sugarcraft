<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Menu;

/**
 * One row of one menu: a leaf (command or toggle), a submenu header owning
 * children, or a separator. Immutable in the house shape — a toggled check
 * rides {@see withChecked()} and returns a new node, so an open menu never
 * mutates the tree a caller handed it.
 *
 * The id is the caller's vocabulary (`cmd:<name>`, `pane:<v>`, `sub:<key>`)
 * and the ONLY stable identity the kit passes back through
 * {@see MenuStep::$chosen}; the kit itself never interprets it. Labels arrive
 * pre-translated from the caller — the kit mints no user strings, so it needs
 * no lang keys.
 *
 * `zone` is the marked-twin escape hatch: when set it replaces the derived
 * `menuitem:<dot-path>` click zone for this row (callers with a stable id
 * such as `cmd:compact` keep their mouse mappings across row moves). The
 * value must satisfy the Mark zone id charset — ASCII letters, digits and
 * `._:-` — or {@see \SugarCraft\Mouse\Mark::zone()} rejects it at paint
 * time (fail-closed by construction, so command paths are spelled without
 * their leading slash).
 */
final class MenuNode
{
    /**
     * @param list<self> $children
     */
    private function __construct(
        public readonly MenuNodeKind $kind,
        public readonly string $id,
        public readonly string $label,
        public readonly string $icon,
        public readonly bool $checked,
        public readonly bool $enabled,
        public readonly array $children,
        public readonly ?string $zone,
    ) {
    }

    public static function command(string $id, string $label, string $icon = '', bool $enabled = true, ?string $zone = null): self
    {
        return new self(MenuNodeKind::Command, $id, $label, $icon, false, $enabled, [], $zone);
    }

    public static function toggle(string $id, string $label, bool $checked = false, string $icon = '', bool $enabled = true, ?string $zone = null): self
    {
        return new self(MenuNodeKind::Toggle, $id, $label, $icon, $checked, $enabled, [], $zone);
    }

    /**
     * @param list<self> $children
     */
    public static function submenu(string $id, string $label, array $children = [], string $icon = '', bool $enabled = true, ?string $zone = null): self
    {
        return new self(MenuNodeKind::Submenu, $id, $label, $icon, false, $enabled, $children, $zone);
    }

    public static function separator(): self
    {
        return new self(MenuNodeKind::Separator, '', '', '', false, true, [], null);
    }

    public function withChecked(bool $checked): self
    {
        return new self($this->kind, $this->id, $this->label, $this->icon, $checked, $this->enabled, $this->children, $this->zone);
    }

    /** May the cursor rest on this row and may Enter choose it? Separators and disabled rows: no. */
    public function selectable(): bool
    {
        return $this->kind !== MenuNodeKind::Separator && $this->enabled;
    }
}

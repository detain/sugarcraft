<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tabs;

use SugarCraft\Bits\Key\Binding;
use SugarCraft\Bits\Key\Help;
use SugarCraft\Bits\Key\KeyMap;

/**
 * Key bindings for the {@see Tabs} component.
 *
 * Default bindings:
 * - `Tab` → next tab (wraps)
 * - `Shift+Tab` → previous tab (wraps)
 * - `1`–`9` → jump directly to tab N (1-indexed)
 */
final class TabsKeyMap implements KeyMap
{
    /** @param list<Binding> */
    private readonly array $bindings;

    public function __construct(
        public readonly Binding $nextTab,
        public readonly Binding $prevTab,
        /** @var list<Binding> */
        public readonly array $jumpBindings,
    ) {
        $this->bindings = [$nextTab, $prevTab, ...$jumpBindings];
    }

    /**
     * Default keymap: Tab / Shift+Tab for navigation, 1-9 for direct jump.
     */
    public static function default(): self
    {
        return new self(
            nextTab: Binding::new(
                keys: ['tab'],
                help: new Help('tab', 'next tab'),
            ),
            prevTab: Binding::new(
                keys: ['shift+tab'],
                help: new Help('shift+tab', 'prev tab'),
            ),
            jumpBindings: self::jumpBindings(),
        );
    }

    /** Keymap with wrap disabled: Tab/Shift+Tab clamp at edges instead of wrapping. */
    public static function noWrap(): self
    {
        return new self(
            nextTab: Binding::new(
                keys: ['tab'],
                help: new Help('tab', 'next tab'),
            ),
            prevTab: Binding::new(
                keys: ['shift+tab'],
                help: new Help('shift+tab', 'prev tab'),
            ),
            jumpBindings: self::jumpBindings(),
        );
    }

    /** @return list<Binding> Jump-to-tab bindings for keys 1 through 9. */
    private static function jumpBindings(): array
    {
        $bindings = [];
        for ($i = 1; $i <= 9; $i++) {
            $bindings[] = Binding::new(
                keys: [(string) $i],
                help: new Help((string) $i, "tab {$i}"),
            );
        }
        return $bindings;
    }

    /** @return list<Binding> */
    public function shortHelp(): array
    {
        return [$this->nextTab, $this->prevTab];
    }

    /** @return list<list<Binding>> */
    public function fullHelp(): array
    {
        $jumpCols = array_chunk($this->jumpBindings, 4);
        return [$this->shortHelp(), ...$jumpCols];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tabs;

use SugarCraft\Widgets\Key\Binding;
use SugarCraft\Widgets\Key\Help;
use SugarCraft\Widgets\Key\KeyMap;
use SugarCraft\Widgets\Lang;

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
    public static function new(): self
    {
        return new self(
            nextTab: Binding::new(
                keys: ['tab'],
                help: new Help('tab', Lang::t('tabs.next_tab')),
            ),
            prevTab: Binding::new(
                keys: ['shift+tab'],
                help: new Help('shift+tab', Lang::t('tabs.prev_tab')),
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
                help: new Help((string) $i, Lang::t('tabs.jump_to', ['index' => $i])),
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

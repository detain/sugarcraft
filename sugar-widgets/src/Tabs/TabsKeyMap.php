<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tabs;

use SugarCraft\Widgets\Key\Binding;
use SugarCraft\Widgets\Key\Help;
use SugarCraft\Widgets\Key\KeyMap;
use SugarCraft\Widgets\Lang;

/**
 * Key bindings for the {@see Tabs} component. Implements
 * {@see \SugarCraft\Widgets\Key\KeyMap} — the help-collection contract,
 * not the sugar-forms submit/abort chords class of the same name.
 *
 * Default bindings:
 * - `Tab` → next tab (wraps)
 * - `Shift+Tab` → previous tab (wraps)
 * - `1`–`9` → jump directly to tab N (1-indexed)
 * - `Ctrl+W` → close the active tab (only on a closable bar, see
 *   {@see Tabs::withClosable()})
 */
final class TabsKeyMap implements KeyMap
{
    /** @param list<Binding> */
    private readonly array $bindings;

    /** Closes the active tab on a closable bar. */
    public readonly Binding $closeTab;

    /**
     * `$closeTab` is optional so maps built positionally before the close
     * binding existed keep working; null means the default `ctrl+w`.
     */
    public function __construct(
        public readonly Binding $nextTab,
        public readonly Binding $prevTab,
        /** @var list<Binding> */
        public readonly array $jumpBindings,
        ?Binding $closeTab = null,
    ) {
        $this->closeTab = $closeTab ?? self::defaultCloseTab();
        $this->bindings = [$nextTab, $prevTab, ...$jumpBindings, $this->closeTab];
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
            closeTab: self::defaultCloseTab(),
        );
    }

    private static function defaultCloseTab(): Binding
    {
        return Binding::new(
            keys: ['ctrl+w'],
            help: new Help('ctrl+w', Lang::t('tabs.close_tab')),
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

    /**
     * Navigation and jump columns. The `closeTab` binding is left out
     * because the map cannot see whether its bar is closable, and a plain
     * bar ignores `ctrl+w`; a host rendering help for a closable bar
     * appends `[$keyMap->closeTab]` itself.
     *
     * @return list<list<Binding>>
     */
    public function fullHelp(): array
    {
        $jumpCols = array_chunk($this->jumpBindings, 4);
        return [$this->shortHelp(), ...$jumpCols];
    }
}

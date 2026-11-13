<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Key;

/**
 * Implement this on your application's keymap so the Help component can
 * render it.
 *
 * The name is this lib's help-collection contract. It is NOT the
 * sugar-forms submit/abort chords class also called KeyMap (that one
 * lives in `SugarCraft\Forms\...`), and the Tabs component's
 * implementation is {@see \SugarCraft\Widgets\Tabs\TabsKeyMap}.
 *
 * - {@see shortHelp()} returns the bindings shown on the inline help row.
 * - {@see fullHelp()} returns columns of bindings shown when the help
 *   pane is expanded; each inner array is one column.
 */
interface KeyMap
{
    /** @return list<Binding> */
    public function shortHelp(): array;

    /** @return list<list<Binding>> */
    public function fullHelp(): array;
}

<?php

declare(strict_types=1);

namespace CandyCore\Bits\Key;

/**
 * Implement this on your application's keymap so the Help component can
 * render it.
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

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Crush\Tui\Pane;

/**
 * Message to select a pane.
 */
final readonly class SelectPaneMsg implements Msg
{
    public function __construct(public Pane $pane) {}
}

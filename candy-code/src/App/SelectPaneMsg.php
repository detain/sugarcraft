<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Code\Tui\Pane;

/**
 * Message to select a pane.
 */
final readonly class SelectPaneMsg implements Msg
{
    public function __construct(public Pane $pane) {}
}

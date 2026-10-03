<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;

// The hint rides in the description rather than in $shortcut: $shortcut is only
// ever painted by the Ctrl+P palette (Renderer::renderPalette()), and this row
// carries no paletteAction, so a $shortcut here would be data no surface shows.
return BuiltInCommand::new(
    CommandSpec::new('keys', 'Show the keyboard shortcut reference (or press ?)', 'App'),
)->withHandler('handleKeysCommand', CommandArguments::None);

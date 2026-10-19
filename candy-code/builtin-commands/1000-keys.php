<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// The hint rides in the description rather than in $shortcut: $shortcut is only
// ever painted by the Ctrl+P palette (Renderer::renderPalette()), and no chord
// opens the key reference from the palette — `?` does it from the draft — so a
// $shortcut here would advertise a binding that does not exist.
return BuiltInCommand::new(
    CommandSpec::new('keys', Lang::t('cmd.keys.description'), Lang::t('cmd.category.app'), paletteAction: PaletteAction::ShowKeys),
)->withHandler('handleKeysCommand', CommandArguments::None);

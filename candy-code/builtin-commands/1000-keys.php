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
// TUI-4a (plan_comparison 6.14): `/keys reload` re-applies the `keybindings`
// setting mid-session. The row keeps routing the BARE name to the reference —
// the argument hint paints in the slash menu, and `withSubcommands()` narrows
// dispatch so '/keys foo' is still prose (the SlashDispatch corpus pins it).
return BuiltInCommand::new(
    CommandSpec::new(
        'keys',
        Lang::t('cmd.keys.description'),
        Lang::t('cmd.category.app'),
        argumentHint: Lang::t('cmd.keys.hint'),
        paletteAction: PaletteAction::ShowKeys,
    ),
)->withHandler('handleKeysCommand', CommandArguments::Parsed)->withSubcommands('reload');

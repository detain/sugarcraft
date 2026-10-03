<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 5.14h. No key binding: Ctrl+G, the usual chord for this, is
// `shell.group-input` here, so the command is the one door. Hands the terminal
// to $VISUAL/$EDITOR through candy-core's Cmd::exec; what the editor saves comes
// back as one PasteMsg into the emptied box and is NOT sent.
return BuiltInCommand::new(CommandSpec::new(
    'editor',
    'Compose the prompt in $VISUAL or $EDITOR',
    'App',
    argumentHint: '[text]',
))->withHandler('handleEditorCommand');

<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

// P-A4: a bare `/rename` opens the inline title editor; `--auto` drops the title
// and asks the title model for a new one. The palette row opens the editor.
return BuiltInCommand::new(CommandSpec::new(
    'rename',
    'Rename the current session',
    'Session',
    paletteAction: PaletteAction::RenameSession,
    paletteLabel: 'Rename session…',
    argumentHint: '[<name>|--auto]',
))->withHandler('handleRenameCommand');

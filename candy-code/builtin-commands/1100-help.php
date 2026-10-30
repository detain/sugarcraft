<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// `/help` used to be a second spelling of `/keys`. It now lists the COMMANDS
// instead, which is what `/help` means in every other CLI, and leaves the
// keyboard to `/keys` and `?`. Argument-less: `/help me name this variable` is
// a prompt, not a request for the command list.
return BuiltInCommand::new(
    CommandSpec::new('help', Lang::t('cmd.help.description'), Lang::t('cmd.category.app'), paletteAction: PaletteAction::ShowHelp),
)->withHandler('handleHelpCommand', CommandArguments::None)
    ->withHostCommand(\SugarCraft\Code\Host\Commands\HelpHostCommand::class);

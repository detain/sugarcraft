<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;
use SugarCraft\Crush\Lang;

// `/help` used to be a second spelling of `/keys`. It now lists the COMMANDS
// instead, which is what `/help` means in every other CLI, and leaves the
// keyboard to `/keys` and `?`. Argument-less: `/help me name this variable` is
// a prompt, not a request for the command list.
return BuiltInCommand::new(
    CommandSpec::new('help', Lang::t('cmd.help.description'), Lang::t('cmd.category.app')),
)->withHandler('handleHelpCommand', CommandArguments::None);

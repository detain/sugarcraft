<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;
use SugarCraft\Crush\Lang;

// Deliberately NOT `/new`: this wipes the transcript and keeps the session id,
// so the session file on disk keeps accumulating the same conversation's
// checkpoints. Chat::handleClearCommand() enumerates exactly what it does and
// does not touch.
return BuiltInCommand::new(
    CommandSpec::new('clear', Lang::t('cmd.clear.description'), Lang::t('cmd.category.session')),
)->withHandler('handleClearCommand', CommandArguments::None)
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\ClearCommand::class);

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;

// Steps forward over what /rewind or /undo set aside (item 3.A-2), until the
// next prompt is sent and the redo stack is discarded.
return BuiltInCommand::new(
    CommandSpec::new('redo', Lang::t('cmd.redo.description'), Lang::t('cmd.category.session')),
)->withHandler('handleRedoCommand', CommandArguments::None)
    ->withHostCommand(\SugarCraft\Code\Host\Commands\RedoCommand::class);

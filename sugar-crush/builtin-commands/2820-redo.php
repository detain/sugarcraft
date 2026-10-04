<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;

// Steps forward over what /rewind or /undo set aside (item 3.A-2), until the
// next prompt is sent and the redo stack is discarded.
return BuiltInCommand::new(
    CommandSpec::new('redo', 'Step forward again over what /rewind or /undo took back', 'Session'),
)->withHandler('handleRedoCommand', CommandArguments::None)
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\RedoCommand::class);

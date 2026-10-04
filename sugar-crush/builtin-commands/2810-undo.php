<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;

// `/rewind 1 --both` under the name every other agent uses (item 3.A-2). When
// auto-commit (3.G) lands it extends this handler rather than adding a second
// `/undo`: revert the last auto-commit when HEAD is ours, else this.
return BuiltInCommand::new(
    CommandSpec::new('undo', 'Take back the last turn: conversation and files', 'Session'),
)->withHandler('handleUndoCommand', CommandArguments::None);

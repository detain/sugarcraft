<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;

// One `/undo` for both halves: in a session that has auto-committed (step
// 3.G) it reverts the last commit, Aider's way and under Aider's refusals;
// otherwise it is `/rewind 1 --both` under the name every other agent uses
// (item 3.A-2).
return BuiltInCommand::new(
    CommandSpec::new('undo', 'Take back the last turn, or revert the last auto-commit', 'Session'),
)->withHandler('handleUndoCommand', CommandArguments::None);

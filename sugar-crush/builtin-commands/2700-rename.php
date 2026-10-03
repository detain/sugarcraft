<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(
    CommandSpec::new('rename', 'Rename the current session', 'Session', argumentHint: '<name>'),
)->withHandler('handleRenameCommand');

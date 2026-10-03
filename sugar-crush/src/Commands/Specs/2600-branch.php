<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(
    CommandSpec::new('branch', 'Fork the current session into a new branch', 'Session'),
)->withHandler('handleBranchCommand');

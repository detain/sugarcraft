<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(
    CommandSpec::new('memory', 'Add, list, search, edit, import, clear, or restore memory entries, and show their history', 'Memory'),
)->withHandler('handleMemoryCommand');

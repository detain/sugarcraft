<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

return BuiltInCommand::new(
    CommandSpec::new('memory', Lang::t('cmd.memory.description'), Lang::t('cmd.category.memory')),
)->withHandler('handleMemoryCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\MemoryCommand::class);

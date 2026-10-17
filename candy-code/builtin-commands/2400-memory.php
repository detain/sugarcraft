<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

return BuiltInCommand::new(
    CommandSpec::new('memory', Lang::t('cmd.memory.description'), Lang::t('cmd.category.memory')),
)->withHandler('handleMemoryCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\MemoryCommand::class);

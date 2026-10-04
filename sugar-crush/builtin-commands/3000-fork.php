<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(
    CommandSpec::new('fork', 'Clone this conversation into a background session', 'Session', argumentHint: '<prompt>'),
)->withHandler('handleForkCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\ForkCommand::class);

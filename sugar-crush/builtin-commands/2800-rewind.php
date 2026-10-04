<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(
    CommandSpec::new('rewind', 'Restore an earlier checkpoint: the conversation, the files, or both', 'Session', argumentHint: '[n] [--chat|--files|--both]'),
)->withHandler('handleRewindCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\RewindCommand::class);

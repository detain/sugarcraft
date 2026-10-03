<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(
    CommandSpec::new('rewind', 'Restore chat state from an earlier checkpoint', 'Session', argumentHint: '[n]'),
)->withHandler('handleRewindCommand');

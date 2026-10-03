<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// `/background` is the long form the old prefix chain reached; it stays a
// dispatching alias with no row of its own.
return BuiltInCommand::new(
    CommandSpec::new('bg', 'Run a task in a background session', 'Session', argumentHint: '<task>'),
)->withHandler('handleBackgroundCommand')->withAliases('background');

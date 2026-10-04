<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Read-only (item 3.A-2): shows what `/rewind [n] --files` would undo.
return BuiltInCommand::new(
    CommandSpec::new('diff', 'Show what changed in the files since a checkpoint', 'Session', argumentHint: '[n]'),
)->withHandler('handleDiffCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\DiffCommand::class);

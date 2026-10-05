<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Read-only (item 3.A-2): shows what `/rewind [n] --files` would undo.
return BuiltInCommand::new(
    CommandSpec::new('diff', Lang::t('cmd.diff.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.diff.hint')),
)->withHandler('handleDiffCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\DiffCommand::class);

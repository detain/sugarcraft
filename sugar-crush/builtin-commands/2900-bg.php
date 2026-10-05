<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// `/background` is the long form the old prefix chain reached; it stays a
// dispatching alias with no row of its own.
return BuiltInCommand::new(
    CommandSpec::new('bg', Lang::t('cmd.bg.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.bg.hint')),
)->withHandler('handleBackgroundCommand')->withAliases('background')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\BackgroundCommand::class);

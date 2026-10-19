<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// `/background` is the long form the old prefix chain reached; it stays a
// dispatching alias with no row of its own.
// VIS-4 ruling: stays slash-only — bare '/bg' answers usage rather than backgrounding something unintended; the task text is the command.
return BuiltInCommand::new(
    CommandSpec::new('bg', Lang::t('cmd.bg.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.bg.hint')),
)->withHandler('handleBackgroundCommand')->withAliases('background')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\BackgroundCommand::class);

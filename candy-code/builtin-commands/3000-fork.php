<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// VIS-4 ruling: stays slash-only — bare '/fork' has no prompt to run against the clone.
return BuiltInCommand::new(
    CommandSpec::new('fork', Lang::t('cmd.fork.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.fork.hint')),
)->withHandler('handleForkCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ForkCommand::class);

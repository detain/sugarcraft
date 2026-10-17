<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

return BuiltInCommand::new(
    CommandSpec::new('fork', Lang::t('cmd.fork.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.fork.hint')),
)->withHandler('handleForkCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ForkCommand::class);

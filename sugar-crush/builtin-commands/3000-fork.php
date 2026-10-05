<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

return BuiltInCommand::new(
    CommandSpec::new('fork', Lang::t('cmd.fork.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.fork.hint')),
)->withHandler('handleForkCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\ForkCommand::class);

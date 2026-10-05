<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

return BuiltInCommand::new(
    CommandSpec::new('rewind', Lang::t('cmd.rewind.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.rewind.hint')),
)->withHandler('handleRewindCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\RewindCommand::class);

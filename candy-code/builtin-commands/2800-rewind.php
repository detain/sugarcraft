<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

return BuiltInCommand::new(
    CommandSpec::new('rewind', Lang::t('cmd.rewind.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.rewind.hint')),
)->withHandler('handleRewindCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\RewindCommand::class);

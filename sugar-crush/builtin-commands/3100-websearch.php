<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

return BuiltInCommand::new(CommandSpec::new(
    'websearch',
    Lang::t('cmd.websearch.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.websearch.hint'),
))->withHandler('handleWebSearchCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\WebSearchHostCommand::class);

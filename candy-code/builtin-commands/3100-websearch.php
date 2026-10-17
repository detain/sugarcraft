<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

return BuiltInCommand::new(CommandSpec::new(
    'websearch',
    Lang::t('cmd.websearch.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.websearch.hint'),
))->withHandler('handleWebSearchCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\WebSearchHostCommand::class);

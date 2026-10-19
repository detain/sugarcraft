<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// VIS-4 ruling: stays slash-only — bare '/websearch' has no query to run and only answers with usage.
return BuiltInCommand::new(CommandSpec::new(
    'websearch',
    Lang::t('cmd.websearch.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.websearch.hint'),
))->withHandler('handleWebSearchCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\WebSearchHostCommand::class);

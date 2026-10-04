<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

return BuiltInCommand::new(CommandSpec::new(
    'websearch',
    'Search the web via SearXNG',
    'Tools',
    argumentHint: '<query> [--safesearch 0|1|2] [--time-range day|month|year]',
))->withHandler('handleWebSearchCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\WebSearchHostCommand::class);

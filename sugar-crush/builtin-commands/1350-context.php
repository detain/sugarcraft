<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 5.6. Read-only and local, in the `/notices` family: it measures what
// the next request will carry and calls no model. `/tokens` is the spelling
// other CLIs teach for the same panel, so it is an alias rather than a second
// row. Arguments are ignored — the report has no sub-views.
return BuiltInCommand::new(CommandSpec::new(
    'context',
    'Show what fills the context window: prompt layers, tools, history, cache',
    'App',
))->withHandler('handleContextCommand')->withAliases('tokens');

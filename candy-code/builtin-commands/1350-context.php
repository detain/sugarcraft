<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Roadmap 5.6. Read-only and local, in the `/notices` family: it measures what
// the next request will carry and calls no model. `/tokens` is the spelling
// other CLIs teach for the same panel, so it is an alias rather than a second
// row. Arguments are ignored — the report has no sub-views.
return BuiltInCommand::new(CommandSpec::new(
    'context',
    Lang::t('cmd.context.description'),
    Lang::t('cmd.category.app'),
))->withHandler('handleContextCommand')->withAliases('tokens')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ContextHostCommand::class);

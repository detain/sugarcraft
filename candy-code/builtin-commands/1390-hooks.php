<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Roadmap VIS-3a. Read-only and local, in the `/context` family: it prints the
// chain THIS launch registered — the question `docs/HOOKS.md` cannot answer —
// and runs nothing. Arguments are ignored; there are no sub-views. No palette
// row: like `/context`, the panel is a question the keyboard user types, not an
// action the palette promises.
return BuiltInCommand::new(CommandSpec::new(
    'hooks',
    Lang::t('cmd.hooks.description'),
    Lang::t('cmd.category.app'),
))->withHandler('handleHooksCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\HooksHostCommand::class);

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap VIS-3a. Read-only and local, in the `/context` family: it prints the
// chain THIS launch registered — the question `docs/HOOKS.md` cannot answer —
// and runs nothing. Arguments are ignored; there are no sub-views. VIS-4 gave
// it a palette row anyway — its bare form is a complete report, exactly the
// read-only browse surface (beside `/context`) the palette promises.
return BuiltInCommand::new(CommandSpec::new(
    'hooks',
    Lang::t('cmd.hooks.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::InspectHooks,
))->withHandler('handleHooksCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\HooksHostCommand::class);

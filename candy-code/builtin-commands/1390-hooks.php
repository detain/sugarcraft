<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap VIS-3a, log half C-EXT-6.b. Read-only and local, in the `/context`
// family: the bare form prints the chain THIS launch registered — the question
// `docs/HOOKS.md` cannot answer — and `/hooks log [n]` tails the hook-log file,
// which is where runs that happened in forked children are actually recorded.
// Runs nothing either way. VIS-4 gave it a palette row anyway — its bare form
// is a complete report, exactly the read-only browse surface (beside
// `/context`) the palette promises.
return BuiltInCommand::new(CommandSpec::new(
    'hooks',
    Lang::t('cmd.hooks.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.hooks.hint'),
    paletteAction: PaletteAction::InspectHooks,
))->withHandler('handleHooksCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\HooksHostCommand::class);

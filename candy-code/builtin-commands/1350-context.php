<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 5.6. Read-only and local, in the `/notices` family: it measures what
// the next request will carry and calls no model. `/tokens` is the spelling
// other CLIs teach for the same panel, so it is an alias rather than a second
// row. The bare form takes no arguments — the report has no sub-views — and
// GAP-PRM-15 added the one flag the host command parses: `--raw [path]`
// dumps the assembled prompt and the tool schemas to files (never stdout).
return BuiltInCommand::new(CommandSpec::new(
    'context',
    Lang::t('cmd.context.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.context.hint'),
    paletteAction: PaletteAction::ShowContext,
))->withHandler('handleContextCommand')->withAliases('tokens')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ContextHostCommand::class);

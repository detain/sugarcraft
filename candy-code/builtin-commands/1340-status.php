<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// OBS-2. The read-only restatement: every owning surface's fact, one screen.
// Arguments are IGNORED for `/notices`' stated reason — the report is already
// total. CC-UXD-04: the palette row runs the real verb — Enter dispatches
// /status and posts the report, the same destination the typed spelling
// reaches; a row that only drafted the command was a dead end.
return BuiltInCommand::new(CommandSpec::new(
    'status',
    Lang::t('cmd.status.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::ShowStatus,
))->withHandler('handleStatusCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\StatusHostCommand::class);

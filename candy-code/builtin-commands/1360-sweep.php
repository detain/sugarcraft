<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 3.B-2 (DCP `/dcp sweep`). Next to `/context`, which shows what this
// frees. It writes the session's context ledger, so it is not read-only.
return BuiltInCommand::new(CommandSpec::new(
    'sweep',
    Lang::t('cmd.sweep.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::RunSweep,
    argumentHint: Lang::t('cmd.sweep.hint'),
))->withHandler('handleSweepCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\SweepHostCommand::class);

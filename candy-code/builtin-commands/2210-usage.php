<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;
// Category 'App', not 'Session': the ledger this reads is the DATABASE's,
// spanning every session and every launch (SES-1); `/usage all` says so
// outright and even the bare scope survives /new. The slot rides right after
// /budget for the pairing that matters: /budget is this launch's tracker,
// /usage is the history outliving it.
// CC-UXD-04: like /status, the row runs the readout — Enter posts the page,
// the same answer the typed spelling gives.
return BuiltInCommand::new(CommandSpec::new(
    'usage',
    Lang::t('cmd.usage.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.usage.hint'),
    paletteAction: PaletteAction::ShowUsage,
))->withHandler('handleUsageCommand')->withHostCommand(\SugarCraft\Code\Host\Commands\UsageHostCommand::class);

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
// Category 'App', not 'Session': the ledger this reads is the DATABASE's,
// spanning every session and every launch (SES-1); `/usage all` says so
// outright and even the bare scope survives /new. The slot rides right after
// /budget for the pairing that matters: /budget is this launch's tracker,
// /usage is the history outliving it.
// No paletteAction: like /status, the page is a typed readout, not a panel the
// palette can pre-open (the PaletteAction enum is another lane's file).
return BuiltInCommand::new(CommandSpec::new(
    'usage',
    Lang::t('cmd.usage.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.usage.hint'),
))->withHandler('handleUsageCommand')->withHostCommand(\SugarCraft\Code\Host\Commands\UsageHostCommand::class);

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Category 'App', not 'Session': the cap and the tracker behind it are
// per-LAUNCH, carried by object identity through Chat::mutate() and untouched by
// /new, /clear or a session switch. Filing it under Session would advertise a
// scope it does not have.
return BuiltInCommand::new(CommandSpec::new(
    'budget',
    Lang::t('cmd.budget.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::ShowBudget,
    argumentHint: Lang::t('cmd.budget.hint'),
))->withHandler('handleBudgetCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\BudgetHostCommand::class);

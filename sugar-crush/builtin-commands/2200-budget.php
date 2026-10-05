<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Category 'App', not 'Session': the cap and the tracker behind it are
// per-LAUNCH, carried by object identity through Chat::mutate() and untouched by
// /new, /clear or a session switch. Filing it under Session would advertise a
// scope it does not have.
return BuiltInCommand::new(CommandSpec::new(
    'budget',
    Lang::t('cmd.budget.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.budget.hint'),
))->withHandler('handleBudgetCommand');

<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'branch',
    Lang::t('cmd.branch.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::BranchSession,
    paletteLabel: Lang::t('cmd.branch.label'),
))->withHandler('handleBranchCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\BranchCommand::class);

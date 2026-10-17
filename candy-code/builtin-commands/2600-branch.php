<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'branch',
    Lang::t('cmd.branch.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::BranchSession,
    paletteLabel: Lang::t('cmd.branch.label'),
))->withHandler('handleBranchCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\BranchCommand::class);

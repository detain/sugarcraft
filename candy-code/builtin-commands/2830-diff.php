<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Read-only (item 3.A-2): shows what `/rewind [n] --files` would undo.
return BuiltInCommand::new(
    CommandSpec::new('diff', Lang::t('cmd.diff.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.diff.hint'), paletteAction: PaletteAction::ShowDiff),
)->withHandler('handleDiffCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\DiffCommand::class);

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// P-A4, corrected by CC-UXD-03: deleting stays the picker's two-press `d`,
// which shows what goes with the session (its sub-agent children) before
// anything is removed — and BOTH doors now open that picker saying how: the
// palette arm and the typed `/session-delete` run the same
// `Chat::openSessionListToDelete()`. A typed spelling never removes anything;
// it opens the list with the delete hint, so the confirmation the palette
// inherited is the confirmation the keyboard gets.
return BuiltInCommand::new(CommandSpec::new(
    'session-delete',
    Lang::t('cmd.session-delete.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::DeleteSession,
    paletteLabel: Lang::t('cmd.session-delete.label'),
))->withHandler('openSessionListToDelete', CommandArguments::None);

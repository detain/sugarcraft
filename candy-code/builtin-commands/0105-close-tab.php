<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// P-TABS.f: palette-only — closing the foreground tab is dispatched by the
// explicit Chat match arms (idle and mid-turn alike), not by
// dispatchPaletteCommand (which skips rows the slash popup cannot run) and
// not by a slash spelling (there is no `/close-tab` — the deck's own
// Ctrl+Tab row and the App guard own the rest).
return BuiltInCommand::new(CommandSpec::new(
    'close-tab',
    Lang::t('cmd.close-tab.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::CloseTab,
    paletteLabel: Lang::t('cmd.close-tab.label'),
    slashVisible: false,
));

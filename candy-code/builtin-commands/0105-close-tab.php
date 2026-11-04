<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// P-TABS.f, corrected by CC-UXD-03: the row is BOTH surfaces now. The palette
// arm and the typed `/close-tab` run the same `Chat::closeDeckTab()` — the
// palette-specific claim that there is "no /close-tab" was a door hiding, not
// a door saving: the deck refusal line answers outside a tab deck exactly as
// the palette arm's does. The Ctrl+Tab row and the App guard still own the
// gesture; this is the keyboard-typed door.
return BuiltInCommand::new(CommandSpec::new(
    'close-tab',
    Lang::t('cmd.close-tab.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::CloseTab,
    paletteLabel: Lang::t('cmd.close-tab.label'),
))->withHandler('closeDeckTab', CommandArguments::None);

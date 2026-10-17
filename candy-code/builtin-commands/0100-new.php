<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// `/new [dir]`: a new session, in a directory picked in a folder picker that
// opens on this project root (Enter there is the plain new session), or
// straight in <dir>. Another directory restarts candy-code there — one root
// per process. See Chat::handleNewCommand(). The palette's "New session"
// stays the immediate same-root new session (Chat::handlePaletteNewSession());
// "New session…" (0101-new-picker.php) is the palette's way to the picker.
return BuiltInCommand::new(CommandSpec::new(
    'new',
    Lang::t('cmd.new.description'),
    Lang::t('cmd.category.session'),
    argumentHint: Lang::t('cmd.new.hint'),
    paletteAction: PaletteAction::NewSession,
    paletteLabel: Lang::t('cmd.new.label'),
))->withHandler('handleNewCommand');

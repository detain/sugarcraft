<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Deliberately NOT `/new`: this wipes the transcript and keeps the session id,
// so the session file on disk keeps accumulating the same conversation's
// checkpoints. Chat::handleClearCommand() enumerates exactly what it does and
// does not touch.
return BuiltInCommand::new(
    CommandSpec::new('clear', Lang::t('cmd.clear.description'), Lang::t('cmd.category.session'), paletteAction: PaletteAction::ClearTranscript),
)->withHandler('handleClearCommand', CommandArguments::None)
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ClearCommand::class);

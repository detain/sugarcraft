<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Roadmap 5.14b. The one command that also runs while a turn is in flight
// (Host\TurnController::midTurnRoute()): it only appends UI-only rows, so the
// agent never reads the question or the answer.
// VIS-4 ruling: stays slash-only — bare '/btw' has no question to ask the title model.
return BuiltInCommand::new(CommandSpec::new(
    'btw',
    Lang::t('cmd.btw.description'),
    Lang::t('cmd.category.session'),
    argumentHint: Lang::t('cmd.btw.hint'),
))->withHandler('handleBtwCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\BtwHostCommand::class);

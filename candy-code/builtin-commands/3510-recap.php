<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// C-TUI-3.g (GAP-UI-N4): Claude Code's away-recap, made on demand. The
// automatic arm fires the same host command on a focus regain past the
// threshold (`Chat::handleRunControlMessages()` → `AwayTracker`); typing
// /recap asks for it right now, which is also the door that spends
// knowingly past a refusal (the automatic path never asks at the cap).
// Takes no argument — it recaps the session as it stands, so a trailing
// draft is ignored rather than refused. The spec's hostCommand row is what
// makes the web's `command.exec` serve it.
return BuiltInCommand::new(CommandSpec::new(
    'recap',
    Lang::t('cmd.recap.description'),
    Lang::t('cmd.category.session'),
))->withHandler('handleRecapCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\RecapHostCommand::class);

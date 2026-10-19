<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 5.14e. A canned prompt rather than a handler: the agent studies the
// checkout and writes the file, gated like any other Write. Filed under Memory
// because AGENTS.md is the instruction-file half of what docs/MEMORY.md
// documents. A command that STARTS A TURN (as `/compress`, `/goal` and `/grind`
// do) — see Chat::handleInitCommand().
//
// WEB-2: InitHostCommand serves it headless as the same canned prompt, sent
// through the host's submit route — the AGENTS.md writing is the agent's,
// gated like any other Write, with no second implementation in the body.
return BuiltInCommand::new(CommandSpec::new(
    'init',
    Lang::t('cmd.init.description'),
    Lang::t('cmd.category.memory'),
    paletteAction: PaletteAction::InitializeProject,
    argumentHint: Lang::t('cmd.init.hint'),
))->withHandler('handleInitCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\InitHostCommand::class);

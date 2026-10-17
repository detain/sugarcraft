<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Roadmap 3.B-2 (DCP manual mode). The choice is the session's, kept on its
// context ledger; bare `/pruning` reports the mode in force and its source.
return BuiltInCommand::new(CommandSpec::new(
    'pruning',
    Lang::t('cmd.pruning.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.pruning.hint'),
))->withHandler('handlePruningCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\PruningHostCommand::class);

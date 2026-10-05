<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Roadmap 5.14c. A fresh session that starts from a state summary of this one
// (the W5 state block), forked so it is linked as a branch, and moved onto when
// the summary lands — see Chat::handleHandoffCommand(). The optional focus
// steers what the summary model dwells on.
return BuiltInCommand::new(CommandSpec::new(
    'handoff',
    Lang::t('cmd.handoff.description'),
    Lang::t('cmd.category.session'),
    argumentHint: Lang::t('cmd.handoff.hint'),
))->withHandler('handleHandoffCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\HandoffHostCommand::class);

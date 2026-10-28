<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Roadmap GAP-GIT-04 (plan §6.1 R13). A canned ship-it prompt rather than a
// scripted push: the prompt tells the agent to push the branch and open the
// pull request with the user's own `gh`, and the calls run in the turn
// through the Bash tool, where the session's permission mode gates them —
// the write is honest about being a write. `/pr [focus]` appends the focus
// line like `/init` does. A command that STARTS A TURN — see
// Chat::handlePrCommand(); it belongs to no read-only roster.
return BuiltInCommand::new(CommandSpec::new(
    'pr',
    Lang::t('cmd.pr.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.pr.hint'),
))->withHandler('handlePrCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\PrHostCommand::class);

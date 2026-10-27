<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 3.D-3. Starts a turn, as `/init` does, then keeps the session going:
// after every turn the title model judges the transcript and the agent is sent
// back to work until the goal is met or the follow-up budget is spent — see
// Chat::handleGoalCommand() and Goal\GoalJudge.
//
// WEB-2: GoalHostCommand serves status/set/clear whole (the state is the
// transcript marker) and sends the FIRST round through the host's submit
// route. W7a-3 wired the continuation loop on the served path too: the host
// runs the judge on its own turn settle through the shared GoalLoop table
// (SessionHost::scheduleGoalJudge()), so a served session keeps the agent at
// work until the goal is met, the budget is spent, or a stop fires — the
// same decision table the TUI's 3.D-3 loop reads, so the two cannot drift.
return BuiltInCommand::new(CommandSpec::new(
    'goal',
    Lang::t('cmd.goal.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::GoalStatus,
    argumentHint: Lang::t('cmd.goal.hint'),
))->withHandler('handleGoalCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\GoalHostCommand::class);

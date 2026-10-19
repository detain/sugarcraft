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
// route. The continuation loop still rides the TUI's turn settle, which a
// headless host does not run — the served row says so instead of letting the
// silence read as progress; wiring the judge loop server-side is a follow-up.
return BuiltInCommand::new(CommandSpec::new(
    'goal',
    Lang::t('cmd.goal.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::GoalStatus,
    argumentHint: Lang::t('cmd.goal.hint'),
))->withHandler('handleGoalCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\GoalHostCommand::class);

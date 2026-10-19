<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 3.D-3. Starts a turn, as `/init` does, then keeps the session going:
// after every turn the title model judges the transcript and the agent is sent
// back to work until the goal is met or the follow-up budget is spent — see
// Chat::handleGoalCommand() and Goal\GoalJudge. TUI-only: the loop rides the
// TUI's turn settle, which a headless host does not run.
return BuiltInCommand::new(CommandSpec::new(
    'goal',
    Lang::t('cmd.goal.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::GoalStatus,
    argumentHint: Lang::t('cmd.goal.hint'),
))->withHandler('handleGoalCommand');

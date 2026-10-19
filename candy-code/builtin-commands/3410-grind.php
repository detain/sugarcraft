<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 3.D-3. `/goal` with the long budget (Goal\GoalMode::GRIND_ROUNDS
// follow-up rounds instead of GOAL_ROUNDS); one handler serves both names —
// and so does one body: GrindHostCommand reads the mode off the draft and
// hands it to GoalHostCommand (WEB-2, with the same first-round-serves,
// continuation-loop-still-TUI note recorded there).
return BuiltInCommand::new(CommandSpec::new(
    'grind',
    Lang::t('cmd.grind.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::GrindStatus,
    argumentHint: Lang::t('cmd.grind.hint'),
))->withHandler('handleGoalCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\GrindHostCommand::class);

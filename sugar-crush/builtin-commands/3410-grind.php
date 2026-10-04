<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 3.D-3. `/goal` with the long budget (Goal\GoalMode::GRIND_ROUNDS
// follow-up rounds instead of GOAL_ROUNDS); one handler serves both names.
return BuiltInCommand::new(CommandSpec::new(
    'grind',
    'Like /goal, with a much longer budget of follow-up rounds',
    'Session',
    argumentHint: '[<condition>|clear]',
))->withHandler('handleGoalCommand');

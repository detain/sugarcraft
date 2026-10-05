<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 5.14d. A canned prompt, like `/init`: the agent drafts a project rule
// from the conversation and writes it into `.sugar-crush/rules/`, a policy
// surface the protect-files hook asks about on every write in every mode
// (step 0.8b), so the user approves the exact file. Beside `/rules` because it
// adds to what `/rules` and the project tier load. A command that STARTS A
// TURN — see Chat::handleNewRuleCommand().
return BuiltInCommand::new(CommandSpec::new(
    'newrule',
    'Have the agent draft a project rule from this conversation',
    'Rules',
    argumentHint: '[focus]',
))->withHandler('handleNewRuleCommand');

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 5.14d. A canned prompt, like `/init`: the agent drafts a project rule
// from the conversation and writes it into `.candy-code/rules/`, a policy
// surface the protect-files hook asks about on every write in every mode
// (step 0.8b), so the user approves the exact file. Beside `/rules` because it
// adds to what `/rules` and the project tier load. A command that STARTS A
// TURN — see Chat::handleNewRuleCommand().
return BuiltInCommand::new(CommandSpec::new(
    'newrule',
    Lang::t('cmd.newrule.description'),
    Lang::t('cmd.category.rules'),
    paletteAction: PaletteAction::DraftRule,
    argumentHint: Lang::t('cmd.newrule.hint'),
))->withHandler('handleNewRuleCommand');

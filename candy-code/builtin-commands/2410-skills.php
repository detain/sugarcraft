<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Two surfaces in one command (ISS-089): bare /skills lists the invocable
// skills the launch discovery loaded, and roadmap 5.4-3's propose mode lives
// on the explicit word. The dream pass may only DRAFT a skill (into
// ~/.candy-code/skills-proposed, with `memory.dreamProposeSkills` on); this
// command is the one place a draft becomes live, so promotion is always the
// user's own action — no tool or agent path reaches ProposedSkills::accept().
return BuiltInCommand::new(CommandSpec::new(
    'skills',
    Lang::t('cmd.skills.description'),
    Lang::t('cmd.category.memory'),
    argumentHint: Lang::t('cmd.skills.hint'),
))->withHandler('handleSkillsCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\SkillsHostCommand::class);

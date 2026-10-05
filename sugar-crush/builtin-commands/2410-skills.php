<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Roadmap 5.4-3's propose mode. The dream pass may only DRAFT a skill (into
// ~/.sugar-crush/skills-proposed, with `memory.dreamProposeSkills` on); this
// command is the one place a draft becomes live, so promotion is always the
// user's own action — no tool or agent path reaches ProposedSkills::accept().
return BuiltInCommand::new(CommandSpec::new(
    'skills',
    Lang::t('cmd.skills.description'),
    Lang::t('cmd.category.memory'),
    argumentHint: Lang::t('cmd.skills.hint'),
))->withHandler('handleSkillsCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\SkillsHostCommand::class);

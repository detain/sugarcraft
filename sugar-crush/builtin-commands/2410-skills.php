<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 5.4-3's propose mode. The dream pass may only DRAFT a skill (into
// ~/.sugar-crush/skills-proposed, with `memory.dreamProposeSkills` on); this
// command is the one place a draft becomes live, so promotion is always the
// user's own action — no tool or agent path reaches ProposedSkills::accept().
return BuiltInCommand::new(CommandSpec::new(
    'skills',
    'List the skill drafts the dream pass proposed, or accept or reject one',
    'Memory',
    argumentHint: '[proposed|accept <name> [--replace]|reject <name>]',
))->withHandler('handleSkillsCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\SkillsHostCommand::class);

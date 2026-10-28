<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// C-TOOL-3.d (plan_comparison.md §3): the background-shell read/stop surface.
// Slash-only like its `/schedules` sibling — bare `/shells` IS the list, so
// there is no unintended action a prefix-less name could take. Deliberately
// NOT an alias of `/tasks`: that command answers the agent-task line, a
// different table, and blending the two risks the wrong kill. READ-ONLY
// JUDGEMENT: list and output are admitted in a read-only window, stop is not
// (Chat::isReadOnlySafeCommand routes through ShellsCommand::isReadOnlyVerb).
return BuiltInCommand::new(
    CommandSpec::new('shells', Lang::t('cmd.shells.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.shells.hint')),
)->withHandler('handleShellsCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ShellsCommand::class);

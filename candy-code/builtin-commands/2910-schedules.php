<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// AUT-1 (plan_comparison.md §3): the schedules control surface. Slash-only
// like its `/bg` sibling — bare `/schedules` IS the list, so there is no
// unintended action a prefix-less name could take. READ-ONLY JUDGEMENT: the
// list verb is admitted in a read-only window, the mutating verbs are not
// (Chat::isReadOnlySafeCommand routes through SchedulesCommand::isReadOnlyVerb).
return BuiltInCommand::new(
    CommandSpec::new('schedules', Lang::t('cmd.schedules.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.schedules.hint')),
)->withHandler('handleSchedulesCommand')->withAliases('schedule')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\SchedulesCommand::class);

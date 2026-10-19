<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// AUT-1 (plan_comparison.md §3): an EPHEMERAL schedule — the row lives in this
// process's ScheduleRegistry only, its prompt rides Chat's queue, and it is
// gone at exit. Deliberately NOT read-only-safe: it programs future turns,
// which is a write against the session (the `/schedules` list arm shows the
// contrasting judgement).
return BuiltInCommand::new(
    CommandSpec::new('loop', Lang::t('cmd.loop.description'), Lang::t('cmd.category.session'), argumentHint: Lang::t('cmd.loop.hint')),
)->withHandler('handleLoopCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\LoopCommand::class);

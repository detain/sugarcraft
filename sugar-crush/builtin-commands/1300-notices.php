<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// E653's Shape B. `/permissions` reports the GATE; `/notices` reports the
// warnings the launch raised about it. The transcript rows those warnings seed
// are capped and clipped at the source (and the grant rows pair-packed into ≤2
// aggregates), so this is the full un-truncated record, gathered from the same
// stores stderr printed — never a second shelf. Arguments are IGNORED for
// `permissions`' stated reason: the report is already total.
return BuiltInCommand::new(CommandSpec::new(
    'notices',
    Lang::t('cmd.notices.description'),
    Lang::t('cmd.category.app'),
))->withHandler('handleNoticesCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\NoticesHostCommand::class);

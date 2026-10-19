<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// OBS-2. The read-only restatement: every owning surface's fact, one screen.
// Arguments are IGNORED for `/notices`' stated reason — the report is already
// total. No paletteAction: /status is a destination, not a mode the palette
// switches (the palette has no status pane to show), and inventing an action
// case for it would widen the palette surface for nothing.
return BuiltInCommand::new(CommandSpec::new(
    'status',
    Lang::t('cmd.status.description'),
    Lang::t('cmd.category.app'),
))->withHandler('handleStatusCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\StatusHostCommand::class);

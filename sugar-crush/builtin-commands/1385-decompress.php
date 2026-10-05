<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Roadmap 3.B-4 (DCP `/dcp decompress`). It writes the session's context
// ledger, so it is not read-only.
return BuiltInCommand::new(CommandSpec::new(
    'decompress',
    Lang::t('cmd.decompress.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.decompress.hint'),
))->withHandler('handleDecompressCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\DecompressHostCommand::class);

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Roadmap 3.B-4 (DCP `/dcp decompress`). It writes the session's context
// ledger, so it is not read-only.
return BuiltInCommand::new(CommandSpec::new(
    'decompress',
    Lang::t('cmd.decompress.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.decompress.hint'),
))->withHandler('handleDecompressCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\DecompressHostCommand::class);

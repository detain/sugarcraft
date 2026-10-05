<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Roadmap 3.B-4 (DCP `/dcp recompress`): /decompress undone.
return BuiltInCommand::new(CommandSpec::new(
    'recompress',
    Lang::t('cmd.recompress.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.recompress.hint'),
))->withHandler('handleRecompressCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\RecompressHostCommand::class);

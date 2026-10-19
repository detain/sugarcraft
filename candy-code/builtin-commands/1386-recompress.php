<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 3.B-4 (DCP `/dcp recompress`): /decompress undone.
return BuiltInCommand::new(CommandSpec::new(
    'recompress',
    Lang::t('cmd.recompress.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::RestoreSummary,
    argumentHint: Lang::t('cmd.recompress.hint'),
))->withHandler('handleRecompressCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\RecompressHostCommand::class);

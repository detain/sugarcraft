<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// The optional focus steers the model-written summary (roadmap 2.12): it is
// sent beside the exchanges and handed to PreCompact hooks as
// `custom_instructions`. `--self` (roadmap 3.B-4, Kilo's legacy form) has the
// turn's own model write the summary through its Compress call, previewed
// before it applies. See Chat::handleCompactCommand().
//
// WEB-2: the served fold lives in CompactHostCommand — the shared
// CompactionService engine on the host's own compactor, splicing a landed
// model summary and honoring the typed-HOLD refusal; `--self` is answered as
// the terminal-only preview it is.
return BuiltInCommand::new(CommandSpec::new(
    'compact',
    Lang::t('cmd.compact.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::CompactNow,
    argumentHint: Lang::t('cmd.compact.hint'),
))->withHandler('handleCompactCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\CompactHostCommand::class);

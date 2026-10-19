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
return BuiltInCommand::new(CommandSpec::new(
    'compact',
    Lang::t('cmd.compact.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::CompactNow,
    argumentHint: Lang::t('cmd.compact.hint'),
))->withHandler('handleCompactCommand');

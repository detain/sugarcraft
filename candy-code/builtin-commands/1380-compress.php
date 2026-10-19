<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Roadmap 3.B-4 (DCP `/dcp-compress`). Next to `/sweep` and `/pruning`: the
// other way to take context out of what the model is sent. It STARTS A TURN —
// the manual trigger the model's Compress tool is offered on, Compress being
// manual by default — so, like /init, it has no headless host command. See
// Chat::handleCompressCommand().
return BuiltInCommand::new(CommandSpec::new(
    'compress',
    Lang::t('cmd.compress.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::CompressNow,
    argumentHint: Lang::t('cmd.compress.hint'),
))->withHandler('handleCompressCommand');

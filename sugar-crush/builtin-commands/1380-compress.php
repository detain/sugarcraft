<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Roadmap 3.B-4 (DCP `/dcp-compress`). Next to `/sweep` and `/pruning`: the
// other way to take context out of what the model is sent. It STARTS A TURN —
// the manual trigger the model's Compress tool is offered on, Compress being
// manual by default — so, like /init, it has no headless host command. See
// Chat::handleCompressCommand().
return BuiltInCommand::new(CommandSpec::new(
    'compress',
    Lang::t('cmd.compress.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.compress.hint'),
))->withHandler('handleCompressCommand');

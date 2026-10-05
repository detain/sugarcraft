<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Roadmap 3.B-4 (DCP `/dcp-compress`). Next to `/sweep` and `/pruning`: the
// other way to take context out of what the model is sent. It STARTS A TURN —
// the manual trigger the model's Compress tool is offered on, Compress being
// manual by default — so, like /init, it has no headless host command. See
// Chat::handleCompressCommand().
return BuiltInCommand::new(CommandSpec::new(
    'compress',
    'Ask the model to compress a closed part of the conversation into a summary',
    'App',
    argumentHint: '[focus]',
))->withHandler('handleCompressCommand');

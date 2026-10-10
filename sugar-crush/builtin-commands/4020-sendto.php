<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// crush-media W4.4 — send a saved PNG back into the generate form. Flags
// (slot 4020, after /generate 4000 and /generate-form 4010):
//   /sendto output.png --to img2img
//   /sendto masked.png --to inpaint --mask mask.png
// Imports the file's parameters chunk (server png-info only when absent and
// configured), then opens the form on the draft. Reads local disk only.
return BuiltInCommand::new(CommandSpec::new(
    'sendto',
    Lang::t('cmd.sendto.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.sendto.hint'),
))->withHandler('handleSendToCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\SendToHostCommand::class);

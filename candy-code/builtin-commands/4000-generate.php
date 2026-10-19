<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// crush-media W2.3 — text-to-image from the console. Flags (slot 4000):
//   /generate "a cat" --steps 20 --size 1024x1024 --seed 42 --save
// Runs the same GenerateImage tool the model is gated through. No key chord
// (the command roster is the surface); the interactive form door is W3.1.
// VIS-4 ruling: stays slash-only — bare '/generate' has no prompt; the palette's twin for this is the generate-form row.
return BuiltInCommand::new(CommandSpec::new(
    'generate',
    Lang::t('cmd.generate.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.generate.hint'),
))->withHandler('handleGenerateCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\GenerateHostCommand::class);

<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// crush-media W5.7 — show or switch the media render mode in one command
// (slot 4040, after /play 4030):
//   /rendermode                 current word + both render ladders
//   /rendermode kitty           persist ui.imageRenderMode and re-derive now
// The write rides the workspace SettingsWriter door, never a side-channel
// config.json edit. The player keeps sugar-video's own ladder, cycled live
// with m inside it (W5.4 asymmetry, documented in the command's help).
return BuiltInCommand::new(CommandSpec::new(
    'rendermode',
    Lang::t('cmd.rendermode.description'),
    Lang::t('cmd.category.appearance'),
    argumentHint: Lang::t('cmd.rendermode.hint'),
))->withHandler('handleRenderModeCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\RenderModeHostCommand::class);

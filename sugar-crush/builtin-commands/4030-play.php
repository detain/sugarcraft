<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// crush-media W5.4 — open the full-screen video player on an artifact or a
// local clip (slot 4030, after /generate 4000, /generate-form 4010 and
// /sendto 4020):
//   /play output.mp4
//   /play video-1728514200-a1b2.mp4        (this session's saved artifact)
// Reads local disk only and spawns at most the accounted decoder child.
return BuiltInCommand::new(CommandSpec::new(
    'play',
    Lang::t('cmd.play.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.play.hint'),
))->withHandler('handlePlayCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\PlayHostCommand::class);

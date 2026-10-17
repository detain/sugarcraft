<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Same as Ctrl+C / the palette's Exit action, just reachable without a modifier
// key. Argument-less: `/exit now` is a prompt. `/quit` is the second spelling
// the prefix chain this dispatch replaced reached, kept so it stays reachable.
return BuiltInCommand::new(CommandSpec::new(
    'exit',
    Lang::t('cmd.exit.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::Exit,
    paletteLabel: Lang::t('cmd.exit.label'),
    shortcut: 'Ctrl+C',
))->withHandler('handleExitCommand', CommandArguments::None)->withAliases('quit');

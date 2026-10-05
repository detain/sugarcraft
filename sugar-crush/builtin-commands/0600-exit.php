<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Commands\Specs\CommandArguments;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

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

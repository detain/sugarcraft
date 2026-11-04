<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// C-CTX-4.e. One output style at a time: a markdown rulebook under
// ~/.candy-code/styles/ (or the project's .candy-code/styles/) selected by
// name, riding the prompt through the SAME user-rules splice as every other
// rule file - the fence already carries the "never overrides the base prompt"
// authority, so no new prompt section is needed. Beside /rules because it
// selects within that family, but where /rules flips any number of files, this
// keeps exactly ONE style active per session. CC-UXD-04: the palette row runs
// the verb — bare Enter IS the listing a mouse can browse; choosing still
// names a file, which stays a typed act. See OutputStyleHostCommand.
return BuiltInCommand::new(CommandSpec::new(
    'output-style',
    Lang::t('cmd.output-style.description'),
    Lang::t('cmd.category.rules'),
    argumentHint: Lang::t('cmd.output-style.hint'),
    paletteAction: PaletteAction::OutputStyles,
))->withHandler('handleOutputStyleCommand')->withHostCommand(\SugarCraft\Code\Host\Commands\OutputStyleHostCommand::class);

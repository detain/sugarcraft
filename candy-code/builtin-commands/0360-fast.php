<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Commands\Specs\CommandArguments;
use SugarCraft\Code\Lang;

// The premium service lane (C-PRV-3.b, sibling of the C-PRV-3.a `serviceTier`
// setting): bare reads the state of the pair, `on` arms the lane the pair
// shapes, `off` drops to the standard lane. Parsed args like /effort — the
// token is one word and the parser has already unquoted it. Deliberately no
// palette action: like the effort dial, the control belongs to the pair it
// speaks for, and a palette row that would half the roster into refusals is
// a trap with a nicer frame.
//
// The TUI handler persists the WORD through the user settings tier; the
// served command (FastHostCommand) overlays the session backend only — the
// settings write stays the terminal's, exactly the 0300-model doctrine.
return BuiltInCommand::new(CommandSpec::new(
    'fast',
    Lang::t('cmd.fast.description'),
    Lang::t('cmd.category.model'),
    argumentHint: Lang::t('cmd.fast.hint'),
))->withHandler('handleFastCommand', CommandArguments::Parsed)
    ->withHostCommand(\SugarCraft\Code\Host\Commands\FastHostCommand::class);

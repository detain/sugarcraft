<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;
// C-TUI-3.a (GAP-UI-16): keyboard copy for the transcript. Category 'Session':
// the text it selects is this conversation's own last reply, the same subject
// /export and /clear act on, and it sits on 1910 right after /editor (1900) —
// both are "get this text out of the box" verbs.
// CC-UXD-04: the palette row runs the verb — Enter copies the last reply
// right here, the act itself, no draft to type and no panel to pre-open
// (the old ladder rationale read the row as a placeholder; it is not).
// No host command ON PURPOSE: the copy writes to the terminal it runs in —
// an OSC 52 sequence and the local clipboard tool — so a serve head would
// answer for the wrong machine. SessionHost maps the missing body to
// CommandResult::clientOnly(), the same refusal /keys and /editor carry.
return BuiltInCommand::new(CommandSpec::new(
    'copy',
    Lang::t('cmd.copy.description'),
    Lang::t('cmd.category.session'),
    argumentHint: Lang::t('cmd.copy.hint'),
    paletteAction: PaletteAction::CopyLastReply,
))->withHandler('handleCopyCommand');

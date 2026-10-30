<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
// C-TUI-3.a (GAP-UI-16): keyboard copy for the transcript. Category 'Session':
// the text it selects is this conversation's own last reply, the same subject
// /export and /clear act on, and it sits on 1910 right after /editor (1900) —
// both are "get this text out of the box" verbs.
// No paletteAction: like the /usage and /insights rows next to the same
// ladder, a typed readout of the transcript is not a panel the palette can
// pre-open (and the PaletteAction enum is another step's file).
// No host command ON PURPOSE: the copy writes to the terminal it runs in —
// an OSC 52 sequence and the local clipboard tool — so a serve head would
// answer for the wrong machine. SessionHost maps the missing body to
// CommandResult::clientOnly(), the same refusal /keys and /editor carry.
return BuiltInCommand::new(CommandSpec::new(
    'copy',
    Lang::t('cmd.copy.description'),
    Lang::t('cmd.category.session'),
    argumentHint: Lang::t('cmd.copy.hint'),
))->withHandler('handleCopyCommand');

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
// Category 'App', not 'Session': the report reads the DATABASE's ledger and
// session roster — every session, every launch (C-SES-8.c) — and writes only
// an export file beside `/export`'s, never the open session. It sits on
// 2215, right after /usage (2210): /usage is the ledger grid, /insights is
// the same facts folded into a shelf file, and pairing them keeps the two
// spend surfaces discoverable from each other.
// No paletteAction: the /usage spec records the same judgement for the same
// shape — a typed readout of the durable store, not a panel the palette can
// pre-open (and the PaletteAction enum is another lane's file).
return BuiltInCommand::new(CommandSpec::new(
    'insights',
    Lang::t('cmd.insights.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.insights.hint'),
))->withHandler('handleInsightsCommand')->withHostCommand(\SugarCraft\Code\Host\Commands\InsightsHostCommand::class);

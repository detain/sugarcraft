<?php

declare(strict_types=1);

// W4-B (audit 2026-10-21): `/export [format] [file|--path <file>]` writes the
// transcript to a LOCAL file with its RAW bytes — the SEC-4 ruling's other
// half ("local export keeps raw bytes") and the discoverable save path the
// end-user reviews asked for. /share's family stays redacted because it is
// the publish shape; see ExportCommand for why the two verbs are two doors.
// The bare '/export' form writes the default file, so the palette row is
// meaningful. Reads only the transcript and writes a file: READ-ONLY on the
// session, so it works inside the locked window like /share.

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'export',
    Lang::t('cmd.export.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::ExportTranscript,
    paletteLabel: Lang::t('cmd.export.label'),
    argumentHint: Lang::t('cmd.export.hint'),
))->withHandler('handleExportCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ExportHostCommand::class);

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// The palette row LISTS: its dispatch is `mcp auth list`, and the interactive
// trust WRITE that a "toggle" would need was DECLINED at E689
// (src/Tui/McpPanel.php - it would invent a second persistence seam). E697
// corrected the label from "Toggle MCPs", which promised that declined write.
// The case name and its 'toggle_mcp' value stay for name/wire stability: no
// surface derives text from them (the palette reads this row's label).
//
// The bare "mcp auth …" form, which has no leading slash, is dispatched ahead of
// the parse by Chat::dispatchCommand() itself and reaches the same handler.
return BuiltInCommand::new(CommandSpec::new(
    'mcp',
    Lang::t('cmd.mcp.description'),
    Lang::t('cmd.category.mcp'),
    paletteAction: PaletteAction::ToggleMcp,
    paletteLabel: Lang::t('cmd.mcp.label'),
    argumentHint: Lang::t('cmd.mcp.hint'),
))->withHandler('handleMcpAuthCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\McpAuthHostCommand::class);

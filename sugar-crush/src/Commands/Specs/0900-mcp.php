<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

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
    'Manage MCP server auth (list/add/remove; login prints the CLI command)',
    'MCP',
    paletteAction: PaletteAction::ToggleMcp,
    paletteLabel: 'List MCP servers',
    argumentHint: '<list|add|remove|login> [server]',
))->withHandler('handleMcpAuthCommand');

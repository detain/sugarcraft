<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Crush\Cli\Bootstrap;

/**
 * E689: the operator-facing `/mcp` panel — what this project's `.mcp.json`
 * DECLARES and whether this launch is allowed to RUN it, rendered from
 * {@see Bootstrap::mcpServerInventory()} with every row derived from the
 * returned array.
 *
 * ZERO ROSTERS, because that is the whole E191 contract this panel inherits
 * from `sugarcrush mcp --json`: the class contains no server name, no command,
 * no URL, and no type string of its own — the only type-agnostic vocabulary it
 * prints is what the inventory hands it, and the four status words it can
 * print are exactly the `Bootstrap::MCP_*` constants the same discovery path
 * returns. The companion test writes a fixture `.mcp.json` with randomised
 * server names, calls the SAME live `mcpServerInventory()` the CLI lists from,
 * and asserts each returned row appears; a hard-coded panel would fail the
 * fixture on its first random name.
 *
 * DISPLAY-ONLY, stated as a decision rather than an omission: toggling trust
 * means WRITING the `trustedProjectMcp` grant through the existing config path
 * (`Bootstrap::writeUserConfig()` and the trust-key machinery already own it),
 * and wiring that into a transcript panel would invent a second persistence
 * seam under this lane. So the panel reports truthfully and names the opt-in;
 * `crush mcp --json` and this panel can never disagree, because there is one
 * discovery path above both. It also renders as transcript lines rather than a
 * modal overlay for the same lane-discipline reason: overlays compose through
 * `src/Renderer.php`, which another lane owns this round.
 */
final class McpPanel
{
    /** Comfortable transcript width; callers pass the live pane width. */
    public const DEFAULT_WIDTH = 80;

    /**
     * Render the inventory as transcript lines.
     *
     * @param array{status: string, path: string, servers: list<array{name: string, type: string, detail: string}>, error: string|null} $inventory exactly what {@see Bootstrap::mcpServerInventory()} returned
     */
    public static function render(array $inventory, int $paneWidth = self::DEFAULT_WIDTH): string
    {
        $width = max(40, $paneWidth);
        $status = (string) $inventory['status'];

        $out = "\n";
        $out .= self::line('  **MCP Project Config**', $width);
        $out .= self::line('  Path: ' . (string) $inventory['path'], $width);

        $out .= self::line('  Status: ' . match ($status) {
            Bootstrap::MCP_ABSENT => 'none — this project declares no .mcp.json.',
            Bootstrap::MCP_OUTSIDE_TREE => 'IGNORED — the config resolves outside the checkout tree.',
            Bootstrap::MCP_UNTRUSTED => 'present but NOT TRUSTED — servers stay hidden until this root is opted in.',
            Bootstrap::MCP_TRUSTED => 'trusted — the servers below would start on launch.',
            default => $status,
        }, $width);

        $error = $inventory['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $out .= self::line('  Config ' . $error . '.', $width);
        }

        $servers = $inventory['servers'] ?? [];
        if ($status === Bootstrap::MCP_TRUSTED) {
            if ($servers === []) {
                $out .= self::line('  Servers: none declared.', $width);
            } else {
                $out .= self::line('  Servers (' . count($servers) . '):', $width);
                foreach ($servers as $server) {
                    $row = '   - ' . (string) $server['name']
                        . ' [' . (string) $server['type'] . ']';
                    $detail = (string) ($server['detail'] ?? '');
                    if ($detail !== '') {
                        $row .= ' ' . $detail;
                    }
                    $out .= self::line($row, $width);
                }
            }
        } elseif ($status !== Bootstrap::MCP_ABSENT) {
            $out .= self::line('  Servers: not listed (discovery refused before parsing).', $width);
        }

        return $out;
    }

    /**
     * One transcript line, clipped to the pane so a pathological `detail`
     * cannot wrap the panel around itself; truncation keeps two visible
     * characters of ellipsis room.
     */
    private static function line(string $text, int $width): string
    {
        if (strlen($text) > $width) {
            $text = substr($text, 0, $width - 1) . '…';
        }

        return $text . "\n";
    }
}

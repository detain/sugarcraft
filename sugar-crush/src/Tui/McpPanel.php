<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Crush\Cli\Bootstrap;

use SugarCraft\Core\Util\Width;

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
     * E709: the docs/MCP.md subsection carrying the worked recipe. One const
     * so the panel hint, the auth-table hint, and the DocFigure arm that
     * proves the heading exists cannot drift apart from each other.
     */
    public const GUIDANCE_SECTION = 'Adding servers';

    /**
     * E709: first half of the empty-state hint — WHERE servers come from.
     * The operator pain this answers is real: a cold panel used to name only
     * the OAuth verb, which reads as "MCP means registering credentials",
     * while in fact a declared http remote with no auth starts on its own.
     */
    public const GUIDANCE_ADD = '  Add servers: declare them under "mcpServers" in the .mcp.json above.';

    /** E709: second half — the zero-auth truth and where the recipe lives. */
    public const GUIDANCE_RECIPE = '  No-auth http remotes just work — recipe: docs/MCP.md, "' . self::GUIDANCE_SECTION . '".';

    /**
     * Render the inventory as transcript lines.
     *
     * @param array{status: string, path: string, servers: list<array{name: string, type: string, detail: string}>, error: string|null} $inventory exactly what {@see Bootstrap::mcpServerInventory()} returned
     * @param array<string, array{transport: string, up: bool|null, tools: int}> $liveness what {@see Bootstrap::mcpLivenessSnapshot()} returned for the same root (`?? []` when it answered null). EMPTY MAP ⇒ the render is BYTE-IDENTICAL to the pre-E698 panel — every existing pin in `tests/Tui/McpPanelTest` reads the cold process, and a cold process must say exactly what it said before this feature existed.
     * @param bool|null $configChangedSinceLaunch {@see Bootstrap::mcpConfigChangedSinceLaunch()}; null suppresses the E703-α line (no digest to compare — silence, never a claim).
     */
    public static function render(
        array $inventory,
        int $paneWidth = self::DEFAULT_WIDTH,
        array $liveness = [],
        ?bool $configChangedSinceLaunch = null,
    ): string {
        $width = max(40, $paneWidth);
        $status = (string) $inventory['status'];
        // E698: the liveness block belongs to the TRUSTED tier only. On an
        // untrusted root the panel has already refused to enumerate the file's
        // servers, and a suffix or a `Live in this process` row would hand
        // back, through the side channel of what this process started, the
        // very roster the discovery gate withholds. Suppression is total even
        // if a non-empty map arrived — the caller cannot talk the panel into
        // a leak.
        $live = $status === Bootstrap::MCP_TRUSTED && $liveness !== [];

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
        // Initialised before the trusted branch so the undeclared sweep below
        // has a defined map even on the odd edge where the inventory holds an
        // error (servers empty) while this process started servers anyway.
        $declared = [];
        if ($status === Bootstrap::MCP_TRUSTED) {
            if ($servers === []) {
                $out .= self::line('  Servers: none declared.', $width);
            } else {
                $out .= self::line('  Servers (' . count($servers) . '):', $width);
                foreach ($servers as $server) {
                    $name = (string) $server['name'];
                    $declared[$name] = true;
                    $row = '   - ' . $name
                        . ' [' . (string) $server['type'] . ']';
                    $detail = (string) ($server['detail'] ?? '');
                    if ($detail !== '') {
                        $row .= ' ' . $detail;
                    }
                    if ($live) {
                        $row .= self::livenessSuffix($liveness[$name] ?? null);
                    }
                    $out .= self::line($row, $width);
                }
            }
        } elseif ($status !== Bootstrap::MCP_ABSENT) {
            $out .= self::line('  Servers: not listed (discovery refused before parsing).', $width);
        }

        // E709: teach the surface when there is nothing to teach ABOUT. The
        // pair prints on exactly two cold states — no config at all, and a
        // trusted config that declares nothing — because those are the frames
        // an operator reads as "this feature needs setup". It deliberately
        // does NOT print on the refusal states: an untrusted root's next
        // correct action is the trust opt-in the status line already names,
        // not a recipe. The rows use two-space indent, never the three-space
        // dash of a server row, so the panel's own "no server rows invented"
        // law keeps holding over them.
        if ($status === Bootstrap::MCP_ABSENT
            || ($status === Bootstrap::MCP_TRUSTED && $servers === [])) {
            $out .= self::line(self::GUIDANCE_ADD, $width);
            $out .= self::line(self::GUIDANCE_RECIPE, $width);
        }

        if ($live) {
            $started = 0;
            foreach ($servers as $server) {
                if (array_key_exists((string) $server['name'], $liveness)) {
                    $started++;
                }
            }
            $out .= self::line(
                '  Live in this process: ' . $started . ' of ' . count($servers)
                    . " declared (other sessions' servers are not visible here)",
                $width,
            );

            // Declared-but-absent already had its voice (the ` · not up`
            // suffix surfacing startServer()'s silent skip). This is the
            // mirror gap: a server this process started that the CURRENT
            // inventory no longer lists — a config edited or deleted after
            // the launch that froze it. Naming it is the only way its tools
            // remain attributable while it keeps answering calls.
            foreach ($liveness as $name => $row) {
                if (!isset($declared[(string) $name])) {
                    $out .= self::line(
                        '  Started but no longer declared: ' . (string) $name
                            . ' (' . (int) $row['tools'] . ' tools)',
                        $width,
                    );
                }
            }

            if ($configChangedSinceLaunch === true) {
                $out .= self::line(
                    '  Config: changed since launch — restart sugar-crush to apply (reload is not implemented)',
                    $width,
                );
            }
        }

        return $out;
    }

    /**
     * The per-server liveness suffix (E698), plain text — no SGR, so the
     * panel keeps its NoRawAnsi-in-transcript standing; the middle dot is
     * U+00B7, a character, not a colour.
     *
     * The vocabulary is four states for servers plus one fallback:
     *  - ` · up N tools` — a stdio child still in `proc_get_status`'s running;
     *  - ` · exited N tools` — it was up at launch and the child is gone; the
     *    tools count shown is the cache it still advertises, which is exactly
     *    the half-truth the operator needs to see together with the death;
     *  - ` · ready N tools` / ` · ready (in-process) N tools` — the two
     *    transports with no process to lose;
     *  - ` · not up` — declared, and NOT in this process's started map: the
     *    silent skip of {@see \SugarCraft\Crush\MCP\McpClient::startServer()}
     *    given a voice. No tool count: a server that never started has no
     *    cache, and inventing `0 tools` would read as "answered with an empty
     *    list".
     *  - ` · state unknown` — the `up: null` of a transport the snapshot
     *    could not classify; unknown stays unknown.
     */
    private static function livenessSuffix(?array $row): string
    {
        if ($row === null) {
            return ' · not up';
        }

        $up = $row['up'];
        if ($up === null) {
            return ' · state unknown';
        }

        $label = match ([$row['transport'], $up]) {
            ['stdio', true] => 'up',
            // E699: a spawned child that is running reads exactly like the
            // stdio one — deliberately NO new label, the suffix vocabulary
            // is pinned by the docs and the panel adds a transport, not a word.
            ['claude-mcp', true] => 'up',
            ['http', true] => 'ready',
            ['git', true] => 'ready (in-process)',
            default => 'exited',
        };

        return ' · ' . $label . ' ' . (int) $row['tools'] . ' tools';
    }

    /**
     * One transcript line, clipped to the pane by DISPLAY WIDTH so a
     * pathological `detail` cannot wrap the panel around itself. Through
     * {@see Width::truncateMiddle()} — the primitive TranscriptTable already
     * depends on — because both byte-slicing (a `substr()` cut through a
     * UTF-8 server name or path emits mojibake) and naive
     * `mb_substr()` (a CJK cluster is one codepoint but TWO cells, so an
     * mb_substr clip overruns the pane) are wrong here. Middle-truncating
     * with the ellipsis is deliberate for the path/detail rows, where both
     * ends carry meaning.
     */
    private static function line(string $text, int $width): string
    {
        if (Width::string($text) > $width) {
            $text = Width::truncateMiddle($text, $width);
        }

        return $text . "\n";
    }
}

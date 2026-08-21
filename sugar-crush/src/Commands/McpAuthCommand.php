<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\MCP\McpAuthStore;

/**
 * Implements the `mcp auth` command for managing MCP server OAuth credentials.
 *
 * Usage:
 *   mcp auth list                    — show all registered servers and their auth status
 *   mcp auth add <server>           — trigger OAuth registration for a server
 *   mcp auth remove <server>         — remove stored credentials for a server
 *
 * The command delegates token lifecycle (registration, fetch, refresh) to
 * {@see McpAuthStore} which wraps {@see \SugarCraft\Crush\MCP\OAuthClientRegistration}.
 *
 * @mirrors charmbracelet/<repo>.McpAuthCommand
 */
final class McpAuthCommand
{
    /**
     * The `mcp auth list` table: header text => cell budget, in cells.
     *
     * `Server` replaces `str_repeat(" ", max(1, 55 - strlen($serverUrl)))`,
     * which measured BYTES against a cell grid and could not clip at all: a
     * server URL is whatever `.mcp.json` says it is, so a long or non-ASCII
     * one either shifted the status column or emitted a row wider than the
     * pane.
     *
     * `Expires` and `Scopes` were a SECOND, indented line under each server,
     * printed only when `expiresAt !== null` — so a server holding
     * credentials with no expiry never showed its scopes at all. As columns
     * they are shown for every row, with `—` where the value is absent.
     *
     * `Status` is 16 because `○ no credentials` is the longest label
     * {@see formatStatus()} emits, and `Expires` is 16 because that is the
     * width of `Y-m-d H:i`; NEITHER IS EVER CLIPPED, which is the point of
     * sizing them at their maximum rather than at a round number. Sized for
     * `no credentials` even though this command cannot currently produce it:
     * both {@see McpAuthStore} constructions of `ServerAuthStatus` hard-code
     * `hasCredentials: true`, so the widest label a `list` renders today is
     * `● expiring soon` at 15. Budgeting for the label the formatter CAN emit
     * rather than the one the store happens to reach means wiring that case
     * up later cannot silently start clipping a column.
     *
     * These sum — with {@see TranscriptTable::maxCells()}'s border overhead —
     * to 88 cells, well past the **74** an 80-column terminal's transcript
     * pane holds (`max(20, cols() - 6)`, {@see TranscriptTable::CHROME_COLS}).
     * An earlier revision defended that overrun as "a measured trade" forced
     * by the pane width being unknowable from here. IT IS KNOWABLE:
     * {@see \SugarCraft\Crush\Chat::cols()} carries it and `execute()` is
     * handed the `Chat`, so {@see listServers()} runs these budgets through
     * {@see TranscriptTable::fit()} and 88 renders only when the pane HAS 88
     * cells.
     *
     * MEASURED at a 74-cell pane (an 80-column terminal): `Server` 20,
     * `Status` 16, `Expires` 16, `Scopes` 9 — the two free-text columns absorb
     * the whole 14-cell loss, so a long `.mcp.json` URL clips with `…` instead
     * of hard-wrapping the box into fragments, and the timestamp stays a
     * timestamp. The old hand-built rows had no bound at all; the revision
     * before this one had a bound that only held on a wide terminal.
     *
     * `Expires` is 16 because `2026-08-22 03:00` is 16 and a timestamp clipped
     * to `2026-08-22 03` is nonsense rather than abbreviation — which is why
     * {@see TranscriptTable::fit()} shrinks in proportion to each column's
     * slack above its header floor rather than uniformly.
     */
    private const COLUMNS = [
        'Server' => 30,
        'Status' => 16,
        'Expires' => 16,
        'Scopes' => 13,
    ];

    /**
     * The two columns {@see TranscriptTable::fit()} may not shrink.
     *
     * `Expires` is a `Y-m-d H:i` timestamp — 16 cells, and `2026-08-22 0…` is
     * a WRONG date, not a short one. `Status` is 15 because
     * `● expiring soon` is the widest label {@see formatStatus()} can emit and
     * a clipped status label is the same class of error. `Server` and `Scopes`
     * carry `.mcp.json` text where `…` reads as the abbreviation it is, so
     * they absorb the whole loss on a narrow pane.
     */
    private const COLUMN_FLOORS = ['Status' => 15, 'Expires' => 16];

    public function __construct(
        private readonly McpAuthStore $authStore,
    ) {
    }

    /**
     * Execute the `mcp auth` command.
     *
     * @param Chat  $chat  The current chat session (unused but part of the contract)
     * @param array $args  Parsed sub-command arguments: [subCommand, ...rest]
     * @return int         Exit code: 0 on success, non-zero on failure
     */
    public function execute(Chat $chat, array $args = []): int
    {
        $subCommand = $args[0] ?? 'list';

        return match ($subCommand) {
            'list' => $this->listServers(TranscriptTable::paneWidth($chat)),
            'add' => $this->addServer($args),
            'remove' => $this->removeServer($args),
            default => $this->printError("Unknown sub-command '{$subCommand}'. Use: list, add, remove"),
        };
    }

    /**
     * List all registered servers and their auth status.
     */
    private function listServers(int $paneWidth): int
    {
        $servers = $this->authStore->listServers();

        if ($servers === []) {
            echo "\n";
            echo "  No MCP servers registered.\n";
            echo "\n";
            echo "  Run `mcp auth add` *<server>* to register a server.\n";
            echo "\n";

            return 0;
        }

        // COLUMNS is the budget at a comfortable width; fit() is what makes it
        // true at the width this pane actually has. The 88-cell worst case
        // below only ever renders when the pane is at least that wide.
        $columns = TranscriptTable::fit(self::COLUMNS, $paneWidth, self::COLUMN_FLOORS);
        $table = TranscriptTable::headed($columns);

        foreach ($servers as $serverUrl => $status) {
            // Every cell goes through cell(), including the two this class
            // generates itself: that is what keeps the natural width under
            // TranscriptTable's derived cap, so the cap's proportional shrink
            // never fires and never clips a column that had room.
            $table = $table->row(
                TranscriptTable::cell((string) $serverUrl, $columns['Server']),
                TranscriptTable::cell($this->formatStatus($status->statusLabel()), $columns['Status']),
                TranscriptTable::cell(
                    $status->expiresAt !== null ? date('Y-m-d H:i', $status->expiresAt) : '—',
                    $columns['Expires'],
                ),
                TranscriptTable::cell(
                    $status->scopes !== [] ? implode(', ', $status->scopes) : '—',
                    $columns['Scopes'],
                ),
            );
        }

        echo "\n";
        echo "  **MCP Servers**\n";
        echo "\n";
        echo $table->render() . "\n";
        echo "\n";

        return 0;
    }

    /**
     * Trigger OAuth registration for a server.
     *
     * @param array<string> $args  [add, serverUrl, registrationUrl?, tokenUrl?]
     */
    private function addServer(array $args): int
    {
        $serverUrl = $args[1] ?? null;

        if ($serverUrl === null) {
            return $this->printError('Usage: mcp auth add <server> [registration-url] [token-url]');
        }

        $registrationUrl = $args[2] ?? null;
        $tokenUrl = $args[3] ?? null;

        // If registration URL not provided, try to discover from the server's well-known endpoint.
        // Many OAuth servers publish their metadata at /.well-known/oauth-authorization-server.
        if ($registrationUrl === null) {
            $wellKnown = rtrim($serverUrl, '/') . '/.well-known/oauth-authorization-server';
            try {
                $metadata = $this->fetchOAuthMetadata($wellKnown);
                $registrationUrl = $metadata['registration_endpoint'] ?? null;
                $tokenUrl = $metadata['token_endpoint'] ?? null;
            } catch (\Throwable) {
                // Discovery failed; require explicit URLs.
            }
        }

        if ($registrationUrl === null || $tokenUrl === null) {
            echo "\n";
            echo "  ! OAuth endpoints could not be discovered for `{$serverUrl}`.\n";
            echo "\n";
            echo "  Please provide them explicitly:\n";
            echo "    `mcp auth add {$serverUrl}` *<registration-url>* *<token-url>*\n";
            echo "\n";

            return 1;
        }

        try {
            $clientName = 'sugar-crush/' . parse_url($serverUrl, PHP_URL_HOST);
            $oauth = $this->authStore->oauth();

            // Step 1: Register the client
            $registered = $oauth->registerClient($registrationUrl, $clientName);

            // Step 2: Fetch initial token
            $token = $oauth->fetchToken(
                $tokenUrl,
                $registered['clientId'],
                $registered['clientSecret'],
            );

            // Step 3: Save the auth entry
            $entry = new \SugarCraft\Crush\MCP\AuthEntry(
                clientId: $registered['clientId'],
                clientSecret: $registered['clientSecret'],
                registrationAccessToken: $registered['registrationAccessToken'],
                accessToken: $token['accessToken'],
                refreshToken: $token['refreshToken'],
                expiresAt: time() + $token['expiresIn'],
            );

            $oauth->saveAuth($serverUrl, $entry);

            echo "\n";
            echo "  ✓ Successfully registered `{$serverUrl}`\n";
            echo "  Client ID: `{$registered['clientId']}`\n";
            echo "\n";

            return 0;
        } catch (\Throwable $e) {
            echo "\n";
            echo "  ✗ Registration failed: {$e->getMessage()}\n";
            echo "\n";

            return 1;
        }
    }

    /**
     * Remove stored credentials for a server.
     *
     * @param array<string> $args  [remove, serverUrl]
     */
    private function removeServer(array $args): int
    {
        $serverUrl = $args[1] ?? null;

        if ($serverUrl === null) {
            return $this->printError('Usage: mcp auth remove <server>');
        }

        if (!$this->authStore->hasServer($serverUrl)) {
            echo "\n";
            echo "  ! No credentials found for `{$serverUrl}`.\n";
            echo "\n";

            return 1;
        }

        $this->authStore->removeServer($serverUrl);

        echo "\n";
        echo "  ✓ Removed credentials for `{$serverUrl}`\n";
        echo "\n";

        return 0;
    }

    /**
     * Fetch OAuth authorization server metadata from a well-known URL.
     *
     * @return array<string, mixed>
     */
    private function fetchOAuthMetadata(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL error {$errno}: {$error}");
        }

        if ($body === '' || $body === false) {
            throw new \RuntimeException('Empty response from metadata URL');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Non-JSON metadata response');
        }

        return $data;
    }

    /**
     * Decorate a status label with its state bullet.
     *
     * NO `**` EMPHASIS, and its removal is the point rather than a tidy-up:
     * these labels are now table CELLS, and {@see TranscriptTable} pads a
     * column to the width it measured. The transcript's Markdown pass then
     * consumes the four asterisks and re-emits the text bolded, leaving the
     * cell four cells short of the padding already written around it — one
     * `**● active**` row was measured pushing its closing border out of
     * line. The bullet alone carries the same distinction and measures the
     * same before and after rendering.
     *
     * Still no ANSI here for the reason
     * {@see \SugarCraft\Crush\Tests\Commands\NoRawAnsiInTranscriptTest}
     * gives: this output is folded into the TUI transcript, which styles its
     * own text.
     */
    private function formatStatus(string $label): string
    {
        return match ($label) {
            'active' => '● active',
            'expired' => '○ expired',
            'expiring soon' => '● expiring soon',
            'no credentials' => "○ no credentials",
            default => "{$label}",
        };
    }

    /**
     * Print an error message.
     */
    private function printError(string $message): int
    {
        echo "\n";
        echo "  ✗ {$message}\n";
        echo "\n";
        echo "  Usage:\n";
        echo "    mcp auth list                    — list registered servers\n";
        echo "    mcp auth add <server> [reg-url] [token-url]  — register a server\n";
        echo "    mcp auth remove <server>         — remove a server's credentials\n";
        echo "\n";

        return 1;
    }
}

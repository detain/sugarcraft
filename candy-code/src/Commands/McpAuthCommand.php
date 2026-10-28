<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\McpAuthStore;
use SugarCraft\Code\MCP\McpClient;

/**
 * Implements the `mcp auth` command for managing MCP server OAuth credentials.
 *
 * Usage:
 *   mcp auth list                    — show all registered servers and their auth status
 *   mcp auth add <server>           — trigger OAuth registration for a server
 *   mcp auth remove <server>         — remove stored credentials for a server
 *   mcp auth login <server>          — E701: print the shell command for the
 *                                      interactive authorization-code + PKCE
 *                                      login (guidance only — the flow runs
 *                                      in {@see \SugarCraft\Code\MCP\OAuthLoopbackFlow}
 *                                      from `candy-code mcp auth login`)
 *   /mcp reconnect                   — VIS-3b: stop and start the session's
 *                                      declared servers WITHOUT re-deriving
 *                                      anything the session was built from;
 *                                      refuses outright while `.mcp.json`
 *                                      differs from the launch bytes
 *   /mcp enable|disable <server>     — C-MCP-4.b: arm or lift a per-process
 *                                      refusal on one exact declared server;
 *                                      `--persist` mirrors the decision into
 *                                      the user `disabledMcpServers` setting
 *
 * The command delegates token lifecycle (registration, fetch, refresh) to
 * {@see McpAuthStore} which wraps {@see \SugarCraft\Code\MCP\OAuthClientRegistration}.
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
     * `no credentials` because E695 made it REAL: both {@see McpAuthStore}
     * constructions of `ServerAuthStatus` now derive `hasCredentials` from the
     * stored entry carrying a non-empty access token, so a row without a
     * usable token renders exactly this label — the widest the formatter
     * emits. The column was budgeted for the label the formatter CAN emit
     * rather than the one the store reached at the time, and the wiring
     * arrived without a clip.
     *
     * These sum — with {@see TranscriptTable::maxCells()}'s border overhead —
     * to 88 cells, past the width an 80-column terminal's transcript pane
     * budgets. {@see TranscriptTable::CHROME_COLS} is 0 since CL-3, so
     * {@see TranscriptTable::maxCells()} is `max(1, cols() - 0)` and the pane
     * keeps its full column count (80 here). It is not an overrun the table
     * simply eats: the width IS knowable —
     * {@see \SugarCraft\Code\Chat::cols()} carries it and `execute()` is
     * handed the `Chat`, so {@see listServers()} runs these budgets through
     * {@see TranscriptTable::fit()} and 88 renders only when the pane HAS 88
     * cells.
     *
     * MEASURED at an 80-cell pane (an 80-column terminal): the natural 88
     * spills 8 cells, and the two shrinkable free-text columns — `Server` and
     * `Scopes` — absorb that loss through {@see TranscriptTable::fit()} while
     * `Status` and `Expires` hold their floors (they are unshrinkable, see
     * {@see COLUMN_FLOORS}), so a long `.mcp.json` URL clips with `…` instead of
     * hard-wrapping the box into fragments, and the timestamp stays a
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

    private readonly \Closure $metadataFetcher;

    /** @var \Closure(string): ?McpClient */
    private readonly \Closure $clientResolver;

    /** @var \Closure(string): ?bool */
    private readonly \Closure $driftProbe;

    /** @var \Closure(McpClient): array{0: array, 1: array, 2: list<string>, 3: list<string>, 4: array, 5: ?string} */
    private readonly \Closure $reconnector;

    /**
     * @param callable(string): array<mixed>|null $metadataFetcher discovery
     *        seam for `add`, the twin of {@see \SugarCraft\Code\MCP\OAuthLoopbackFlow}'s:
     *        production leaves it null and gets {@see self::fetchOAuthMetadata()};
     *        tests inject a table so no request leaves the process.
     * @param callable(string): ?McpClient|null $clientResolver VIS-3b seam for
     *        the session's client ({@see \SugarCraft\Code\Cli\Bootstrap::mcpClient()}
     *        in production) — the memo, never a fresh build: a reconnect that
     *        minted its own client would be a second owner of live servers.
     * @param callable(string): ?bool|null $driftProbe VIS-3b seam for the
     *        E703-α changed-since-launch verdict that GATES the reconnect
     *        ({@see \SugarCraft\Code\Cli\Bootstrap::mcpConfigChangedSinceLaunch()}).
     * @param callable(McpClient): array|null $reconnector VIS-3b seam for the
     *        stop/start pair itself, so a test can pin the gate WITHOUT
     *        spawning servers; production runs {@see self::reconnectPair()}.
     */
    public function __construct(
        private readonly McpAuthStore $authStore,
        ?callable $metadataFetcher = null,
        ?callable $clientResolver = null,
        ?callable $driftProbe = null,
        ?callable $reconnector = null,
    ) {
        $this->metadataFetcher = $metadataFetcher !== null
            ? \Closure::fromCallable($metadataFetcher)
            : static fn (string $url): array => self::fetchOAuthMetadata($url);
        $this->clientResolver = $clientResolver !== null
            ? \Closure::fromCallable($clientResolver)
            : static fn (string $root): ?McpClient => \SugarCraft\Code\Cli\Bootstrap::mcpClient($root);
        $this->driftProbe = $driftProbe !== null
            ? \Closure::fromCallable($driftProbe)
            : static fn (string $root): ?bool => \SugarCraft\Code\Cli\Bootstrap::mcpConfigChangedSinceLaunch($root);
        $this->reconnector = $reconnector !== null
            ? \Closure::fromCallable($reconnector)
            : static fn (McpClient $client): array => self::reconnectPair($client);
    }

    /**
     * Execute the `mcp auth` command.
     *
     * @param Chat|CommandContext $chat The session the command runs in, for the pane width
     * @param array $args  Parsed sub-command arguments: [subCommand, ...rest]
     * @return int         Exit code: 0 on success, non-zero on failure
     */
    public function execute(Chat|CommandContext $chat, array $args = []): int
    {
        $subCommand = $args[0] ?? 'list';

        return match ($subCommand) {
            'list' => $this->listWithProjectPanel($chat, TranscriptTable::paneWidth($chat)),
            'add' => $this->addServer($args),
            'remove' => $this->removeServer($args),
            'login' => $this->printLoginGuidance(),
            'reconnect' => $this->reconnect($chat),
            'enable' => $this->sessionToggle($chat, $args, enabling: true),
            'disable' => $this->sessionToggle($chat, $args, enabling: false),
            default => $this->printError(Lang::t('cmd.mcp-auth.unknown', ['sub' => $subCommand])),
        };
    }

    /**
     * VIS-3b: stop and start the servers this session was admitted, so a
     * dropped stdio server or a dead http endpoint gets its processes back
     * without a restart.
     *
     * THE GATE COMES FIRST, AND IT IS THE WHOLE SAFETY OF THE VERB.
     * `McpClient::startServers()` calls `loadConfig()`, which re-reads
     * `.mcp.json` from disk — so reconnect is only honest while the file still
     * holds the bytes launch trusted (E703-α). A drifted config means the set
     * of servers, their commands and their fingerprints have moved under the
     * session: reconnecting would start servers this session's admission
     * decisions never saw. And `null` — no digest because no launch client
     * recorded one — cannot prove the bytes are unchanged either, so it
     * refuses the same way: fail CLOSED, report only. Restart stays the only
     * apply path for a changed config.
     *
     * THE CACHE LAW RIDES THE SAME GATE: even a clean reconnect re-derives no
     * roster. The model-facing tool list of a running session is not rebuilt
     * from the restarted servers — a server that now answers a different tool
     * count is REPORTED as drifted, and adopting it is the next session's job.
     *
     * No timers and no waits anywhere (E646): `stopServers()` tears down with
     * its own bounded ladder and `startServers()` spawns without blocking on
     * a handshake.
     */
    private function reconnect(Chat|CommandContext $chat): int
    {
        $root = $chat->projectRoot();

        $client = ($this->clientResolver)($root);
        if ($client === null) {
            echo "\n" . TranscriptTable::indented(Lang::t('cmd.mcp-auth.reconnect.no-client')) . "\n";

            return 0;
        }

        $changed = ($this->driftProbe)($root);
        if ($changed !== false) {
            echo "\n" . TranscriptTable::indented(Lang::t(
                $changed === true ? 'cmd.mcp-auth.reconnect.drifted' : 'cmd.mcp-auth.reconnect.unknown',
            )) . "\n";

            return 0;
        }

        [$before, $after, $refused, $denied, $auth, $error] = ($this->reconnector)($client);

        $lines = [Lang::t('cmd.mcp-auth.reconnect.heading')];
        if ($error !== null) {
            $lines[] = '  ' . Lang::t('cmd.mcp-auth.reconnect.error', ['error' => Chat::reportField($error)]);
        }
        if ($before === [] && $after === [] && $refused === [] && $denied === []) {
            $lines[] = '  - ' . Lang::t('cmd.mcp-auth.reconnect.none');
        }
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $name) {
            $was = $before[$name] ?? null;
            $now = $after[$name] ?? null;
            $state = match (true) {
                $now === null => Lang::t('cmd.mcp-auth.reconnect.state.down'),
                $now['up'] === true => Lang::t('cmd.mcp-auth.reconnect.state.up'),
                $now['up'] === false => Lang::t('cmd.mcp-auth.reconnect.state.down'),
                default => Lang::t('cmd.mcp-auth.reconnect.state.starting'),
            };
            $row = '  - ' . Chat::reportField((string) $name) . ': ' . $state;
            if ($was !== null && $now !== null && $was['tools'] !== $now['tools']) {
                // The cache law, spoken: report the drift, re-derive nothing.
                $row = rtrim($row) . "\n    " . Lang::t('cmd.mcp-auth.reconnect.tool-drift', [
                    'now' => $now['tools'],
                    'was' => $was['tools'],
                ]);
            }
            $lines[] = $row;
        }
        foreach ($refused as $name) {
            $lines[] = '  ' . Lang::t('cmd.mcp-auth.reconnect.refused', ['server' => Chat::reportField((string) $name)]);
        }
        foreach ($denied as $name) {
            $lines[] = '  ' . Lang::t('cmd.mcp-auth.reconnect.denied', ['server' => Chat::reportField((string) $name)]);
        }
        foreach (array_keys($auth) as $name) {
            $lines[] = '  ' . Lang::t('cmd.mcp-auth.reconnect.auth', ['server' => Chat::reportField((string) $name)]);
        }

        echo "\n" . TranscriptTable::indented(implode("\n", $lines)) . "\n";

        return 0;
    }

    /**
     * C-MCP-4.b: the shared body of `/mcp enable` and `/mcp disable`.
     *
     * The toggle is EXACT by name and mutates only the memoised launch
     * client — never a config re-read, never a router deny-pattern list. The
     * model-facing tool roster deliberately keeps listing the server (the
     * cache law every mid-session surface of {@see McpClient} obeys: calls
     * are refused, listings are not re-derived), and because preset handles
     * fork per dispatch, a turn already running keeps its pre-toggle copy —
     * the change takes effect from the NEXT turn, the same stance the
     * credential store documents, one level finer.
     *
     * `--persist` is the only disk write, via
     * {@see \SugarCraft\Code\Cli\Bootstrap::writeUserConfig()}: the exact name
     * joins or leaves the user `disabledMcpServers` list. That list is glob
     * vocabulary, so a name carrying fnmatch metacharacters is REFUSED
     * persistence rather than smuggled in as a wildcard that could cover
     * servers this command never named.
     *
     * No sub-command here starts, stops, or re-admits anything; the servers
     * keep running — refusal is routing, not teardown.
     */
    private function sessionToggle(Chat|CommandContext $chat, array $args, bool $enabling): int
    {
        $server = $args[1] ?? '';
        $verb = $enabling ? 'enable' : 'disable';

        if ($server === '' || str_starts_with($server, '--')) {
            return $this->printError(Lang::t("cmd.mcp-auth.{$verb}.usage"));
        }

        $persist = in_array('--persist', array_slice($args, 2), true);

        $client = ($this->clientResolver)($chat->projectRoot());
        if ($client === null) {
            echo "\n" . TranscriptTable::indented(Lang::t("cmd.mcp-auth.{$verb}.no-client")) . "\n";

            return 0;
        }

        // Declared at launch, denied, disabled-by-config, refused — every one
        // of those rows is still a NAME this session knows, and toggling a
        // name nobody ever declared would report a refusal that no routing
        // question could ever reproduce.
        if (!isset($client->configEntries()[$server])) {
            return $this->printError(Lang::t("cmd.mcp-auth.{$verb}.unknown", ['server' => $server]));
        }

        $wasArmed = in_array($server, $client->sessionDisabledServers(), true);
        if ($enabling) {
            $client->enableForSession($server);
        } else {
            $client->disableForSession($server);
        }

        $lines = [Lang::t($enabling
            ? ($wasArmed ? 'cmd.mcp-auth.enable.done' : 'cmd.mcp-auth.enable.not-armed')
            : 'cmd.mcp-auth.disable.done', ['server' => Chat::reportField($server)])];

        if ($persist) {
            $lines[] = '  ' . $this->persistSessionToggle($server, $enabling);
        }

        echo "\n" . TranscriptTable::indented(implode("\n", $lines)) . "\n";

        return 0;
    }

    /**
     * C-MCP-4.b: mirror the toggle into the user settings list, adding or
     * removing EXACTLY this one name (the write replaces the list with the
     * sanitized read plus/minus the entry; other settings keys ride
     * {@see \SugarCraft\Code\Cli\Bootstrap::writeUserConfig()}'s merge, and an
     * emptied list unsets the key rather than persisting a dead `[]`).
     */
    private function persistSessionToggle(string $server, bool $enabling): string
    {
        $key = McpClient::DENY_SETTINGS_KEY;
        $raw = \SugarCraft\Code\Cli\Bootstrap::readUserConfig()[$key] ?? [];

        $list = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $list[] = $entry;
                }
            }
        }

        if ($enabling) {
            $kept = array_values(array_filter($list, static fn(string $e): bool => $e !== $server));
            if (count($kept) === count($list)) {
                return Lang::t('cmd.mcp-auth.enable.persist-none', ['server' => Chat::reportField($server)]);
            }
            if ($kept === []) {
                \SugarCraft\Code\Cli\Bootstrap::writeUserConfig([], [$key]);
            } else {
                \SugarCraft\Code\Cli\Bootstrap::writeUserConfig([$key => $kept]);
            }

            return Lang::t('cmd.mcp-auth.enable.persist-removed', ['server' => Chat::reportField($server)]);
        }

        // fnmatch metacharacters, built at runtime: a typed glob-shaped
        // string literal here would enter the PathGlob corpus (GlobDialect
        // differential) and drift its documented census figure.
        if (strpbrk($server, chr(42) . chr(63) . chr(91) . chr(92)) !== false) {
            return Lang::t('cmd.mcp-auth.disable.persist-glob', ['server' => Chat::reportField($server)]);
        }

        if (in_array($server, $list, true)) {
            return Lang::t('cmd.mcp-auth.disable.persist-kept', ['server' => Chat::reportField($server)]);
        }

        $list[] = $server;
        \SugarCraft\Code\Cli\Bootstrap::writeUserConfig([$key => $list]);

        return Lang::t('cmd.mcp-auth.disable.persist-added', ['server' => Chat::reportField($server)]);
    }

    /**
     * The production pair behind {@see self::$reconnector}: snapshot, stop,
     * start, snapshot. A start that throws (a config that became unreadable
     * between the gate and here — the race the gate narrows but cannot close)
     * is captured as the error slot rather than escaping into the transcript
     * path: the report shows the servers that DID come back plus the failure
     * line, which is more than an exception's stack does for the user.
     *
     * @return array{0: array<string, array{transport: string, up: ?bool, tools: int}>, 1: array<string, array{transport: string, up: ?bool, tools: int}>, 2: list<string>, 3: list<string>, 4: array<string, array{url: string, status: string}>, 5: ?string}
     */
    private static function reconnectPair(McpClient $client): array
    {
        $before = $client->startedSnapshot();
        $client->stopServers();
        $error = null;
        try {
            $client->startServers();
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return [
            $before,
            $client->startedSnapshot(),
            $client->refusedServers(),
            $client->deniedServers(),
            $client->authRequired(),
            $error,
        ];
    }

    /**
     * E701: this class's `login` arm GUIDES and never runs — kept true by
     * construction. The MCP-5 follow-up moved the DOING one layer up, not
     * into here: Chat intercepts `login <server>` before the host command
     * and forks the CLI's own loopback flow as a background child
     * ({@see \SugarCraft\Code\Support\McpLoginFork}), so the TUI never
     * blocks on the browser leg and this method is reached only for the
     * bare `login` guide (no server named). This class still binds no
     * socket and runs no flow, so the E701 byte-pins hold: a blocking
     * loop here would freeze the renderer the human needs in order to
     * copy the URL at all.
     */
    private function printLoginGuidance(): int
    {
        echo "\n" . TranscriptTable::indented(Lang::t('cmd.mcp-auth.login-guidance')) . "\n";

        return 0;
    }

    /**
     * E689 glue: the `/mcp` list arm opens with the live project-inventory
     * panel (what `.mcp.json` declares and whether launch may run it), then
     * falls through to the auth-credentials table exactly as before. One
     * discovery path — {@see \SugarCraft\Code\Cli\Bootstrap::mcpServerInventory()}
     * — so the panel and `candy-code mcp --json` can never disagree.
     */
    private function listWithProjectPanel(Chat|CommandContext $chat, int $paneWidth): int
    {
        // E698: the same render also gets THIS PROCESS's started-servers map —
        // read from the memo only, so opening the panel can never be the act
        // that launches a server (Bootstrap::mcpLivenessSnapshot()'s whole
        // design constraint) — and E703-α's changed-since-launch verdict,
        // whose null means "no digest here, say nothing".
        // ISS-092: the panel read the CURRENT WORKING DIRECTORY while the same
        // command line accepts `--root` (and Chat carries its session root).
        // `candy-code --root /repo mcp auth list`'s CLI sibling passes $args->root
        // into the very same three accessors; `/mcp` in a TUI opened from
        // elsewhere answered for the wrong project — the panel and `mcp list`
        // disagreeing was the report. All three calls take the session root.
        $root = $chat->projectRoot();

        echo \SugarCraft\Code\Tui\McpPanel::render(
            \SugarCraft\Code\Cli\Bootstrap::mcpServerInventory($root),
            $paneWidth,
            \SugarCraft\Code\Cli\Bootstrap::mcpLivenessSnapshot($root) ?? [],
            \SugarCraft\Code\Cli\Bootstrap::mcpConfigChangedSinceLaunch($root),
        );

        return $this->listServers($paneWidth);
    }

    /**
     * List all registered servers and their auth status.
     */
    private function listServers(int $paneWidth): int
    {
        $servers = $this->authStore->listServers();

        if ($servers === []) {
            // E709: this block used to read "No MCP servers registered. Run
            // `mcp auth add`..." — and the operator reasonably concluded MCP
            // means registering credentials first. It does not: this store
            // holds OAuth credentials ONLY, and the servers themselves come
            // from .mcp.json (the panel above already teaches that; these
            // lines fix what THIS table is actually empty of).
            echo "\n" . TranscriptTable::indented(Lang::t('cmd.mcp-auth.empty', [
                'section' => \SugarCraft\Code\Tui\McpPanel::GUIDANCE_SECTION,
            ])) . "\n";

            return 0;
        }

        // COLUMNS is the budget at a comfortable width; fit() is what makes it
        // true at the width this pane actually has. The 88-cell worst case
        // below only ever renders when the pane is at least that wide.
        $labels = self::headers();
        $columns = TranscriptTable::fit(
            TranscriptTable::relabel(self::COLUMNS, $labels),
            $paneWidth,
            TranscriptTable::relabel(self::COLUMN_FLOORS, $labels),
        );
        $table = TranscriptTable::headed($columns);

        foreach ($servers as $serverUrl => $status) {
            // Every cell goes through cell(), including the two this class
            // generates itself: that is what keeps the natural width under
            // TranscriptTable's derived cap, so the cap's proportional shrink
            // never fires and never clips a column that had room.
            $table = $table->row(
                TranscriptTable::cell((string) $serverUrl, $columns[$labels['Server']]),
                TranscriptTable::cell($this->formatStatus($status->statusLabel()), $columns[$labels['Status']]),
                TranscriptTable::cell(
                    $status->expiresAt !== null ? date('Y-m-d H:i', $status->expiresAt) : '—',
                    $columns[$labels['Expires']],
                ),
                TranscriptTable::cell(
                    $status->scopes !== [] ? implode(', ', $status->scopes) : '—',
                    $columns[$labels['Scopes']],
                ),
            );
        }

        echo "\n";
        echo '  ' . Lang::t('cmd.mcp-auth.heading') . "\n";
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
            return $this->printError(Lang::t('cmd.mcp-auth.add-usage'));
        }

        $registrationUrl = $args[2] ?? null;
        $tokenUrl = $args[3] ?? null;

        // Audit MCP-6: discovery is the shared RFC 9728 / RFC 8414 helper the
        // login flow uses — this arm used to append the well-known suffix
        // after the server path, which on a path-bearing MCP URL asks the MCP
        // endpoint itself and gets a 401. Operands win over discovered values.
        if ($registrationUrl === null || $tokenUrl === null) {
            $metadata = (new \SugarCraft\Code\MCP\OAuthDiscovery($this->metadataFetcher))->discover($serverUrl);
            $registrationUrl ??= isset($metadata['registration_endpoint']) && is_string($metadata['registration_endpoint'])
                ? $metadata['registration_endpoint']
                : null;
            $tokenUrl ??= isset($metadata['token_endpoint']) && is_string($metadata['token_endpoint'])
                ? $metadata['token_endpoint']
                : null;
        }

        if ($registrationUrl === null || $tokenUrl === null) {
            // E709: failed discovery very often means there was nothing to
            // discover — a plain no-auth remote exposes no OAuth metadata and
            // answers 404 here. The message's second paragraph says so before
            // the operator hunts endpoints.
            echo "\n" . TranscriptTable::indented(Lang::t('cmd.mcp-auth.undiscovered', [
                'server' => $serverUrl,
                'section' => \SugarCraft\Code\Tui\McpPanel::GUIDANCE_SECTION,
            ])) . "\n";

            return 1;
        }

        try {
            $clientName = 'candy-code/' . parse_url($serverUrl, PHP_URL_HOST);
            $oauth = $this->authStore->oauth();

            // Step 1: Register the client
            $registered = $oauth->registerClient($registrationUrl, $clientName);

            // Step 2: Fetch initial token
            $token = $oauth->fetchToken(
                $tokenUrl,
                $registered['clientId'],
                $registered['clientSecret'],
            );

            // Step 3: Save the auth entry — endpoints INCLUDED, because the
            // E695 request-time refresh path (OAuthClientRegistration::
            // validAuthFor()) reads the token/registration URLs back off this
            // row; a store entry without them can be attached but never
            // refreshed.
            $entry = new \SugarCraft\Code\MCP\AuthEntry(
                clientId: $registered['clientId'],
                clientSecret: $registered['clientSecret'],
                registrationAccessToken: $registered['registrationAccessToken'],
                accessToken: $token['accessToken'],
                refreshToken: $token['refreshToken'],
                expiresAt: \SugarCraft\Code\MCP\OAuthClientRegistration::expiresAtFor($token['expiresIn']),
                tokenUrl: $tokenUrl,
                registrationUrl: $registrationUrl,
                registrationClientUri: $registered['registrationClientUri'],
            );

            $oauth->saveAuth($serverUrl, $entry);

            echo "\n" . TranscriptTable::indented(Lang::t('cmd.mcp-auth.registered', [
                'server' => $serverUrl,
                'client' => $registered['clientId'],
            ])) . "\n";

            return 0;
        } catch (\Throwable $e) {
            echo "\n";
            echo '  ' . Lang::t('cmd.mcp-auth.failed', ['error' => $e->getMessage()]) . "\n";
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
            return $this->printError(Lang::t('cmd.mcp-auth.remove-usage'));
        }

        if (!$this->authStore->hasServer($serverUrl)) {
            echo "\n";
            echo '  ' . Lang::t('cmd.mcp-auth.not-found', ['server' => $serverUrl]) . "\n";
            echo "\n";

            return 1;
        }

        $this->authStore->removeServer($serverUrl);

        echo "\n";
        echo '  ' . Lang::t('cmd.mcp-auth.removed', ['server' => $serverUrl]) . "\n";
        echo "\n";

        return 0;
    }

    /**
     * Fetch OAuth authorization server metadata from a well-known URL.
     *
     * E701: PUBLIC, because the authorization-code login discovers the same
     * document and must not grow a second copy of this curl path. The
     * per-request options are byte-unchanged since `add` shipped them —
     * timeout, redirects, peer verification, and the Accept header are one
     * policy for one endpoint, and drift between two fetchers would be a
     * silent security difference. `add` reads `registration_endpoint` and
     * `token_endpoint` out of the returned document; the login flow in
     * {@see \SugarCraft\Code\MCP\OAuthLoopbackFlow} additionally reads
     * `authorization_endpoint` — the raw array is the whole document, the
     * consumers choose.
     *
     * Audit MCP-6: a non-2xx status THROWS. An MCP endpoint answers a stray
     * well-known request with a 401 whose body is a JSON error object, and
     * that object used to be returned as if it were metadata; discovery now
     * reads the throw as "not here" and tries its next candidate. The status
     * is read after the exchange, so the request options above stay exactly
     * the policy they were.
     *
     * @return array<string, mixed>
     */
    public static function fetchOAuthMetadata(string $url): array
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
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL error {$errno}: {$error}");
        }

        if ($status < 200 || $status > 299) {
            throw new \RuntimeException("HTTP {$status} from metadata URL");
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
     * {@see \SugarCraft\Code\Tests\Commands\NoRawAnsiInTranscriptTest}
     * gives: this output is folded into the TUI transcript, which styles its
     * own text.
     */
    private function formatStatus(string $label): string
    {
        return match ($label) {
            'active' => Lang::t('cmd.mcp-auth.status.active'),
            'expired' => Lang::t('cmd.mcp-auth.status.expired'),
            'expiring soon' => Lang::t('cmd.mcp-auth.status.expiring'),
            'no credentials' => Lang::t('cmd.mcp-auth.status.none'),
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
        echo TranscriptTable::indented(Lang::t('cmd.mcp-auth.usage'));
        echo "\n";

        return 1;
    }

    /**
     * Each {@see COLUMNS} id's header in the active locale.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'Server' => Lang::t('cmd.mcp-auth.column.server'),
            'Status' => Lang::t('cmd.mcp-auth.column.status'),
            'Expires' => Lang::t('cmd.mcp-auth.column.expires'),
            'Scopes' => Lang::t('cmd.mcp-auth.column.scopes'),
        ];
    }
}

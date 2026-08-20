<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

/**
 * The real CLI subcommands — `mcp list`, `session list`, `session delete <id>`,
 * `models`, `doctor` and `completion bash|zsh|fish` (crush_code.md Phase 4
 * item 6).
 *
 * EVERY ONE ANSWERS WITHOUT A SESSION. `bin/sugarcrush` dispatches these in the
 * same pre-flight place it dispatches `--help` and `--version`, before
 * `Program` is constructed and before `NonInteractive::run()` reaches a
 * backend, because each of them is a question about the INSTALL rather than a
 * turn of conversation: they have to answer on a machine with no provider, no
 * API key and no TTY. A subcommand that fell through to `Program::run()` would
 * attach to the terminal and enter the alt-screen — the "`--help` opens the
 * TUI" bug of crush_code.md Phase 0 item 3, reopened by a new verb. Nothing in
 * this class constructs a backend, a `Program`, a `Chat` or an `App`.
 *
 * `doctor` is the sharpest case of that rule and the reason it is stated
 * first: it is a health check for an install that may be broken, so it must
 * never require the thing it is diagnosing. Every probe below is individually
 * wrapped, and a probe that throws becomes a FAIL LINE rather than a fatal.
 *
 * DISTINCT FROM THE MODEL-INVOKED `doctor` TOOL, which exists and is a
 * different thing entirely: {@see \SugarCraft\Crush\Tools\BuiltIn\Doctor} is
 * registered in {@see Bootstrap::tools()}, advertised to the LLM in the
 * tool-calling schema, and reports ONE fact — the terminal's detected
 * candy-mosaic image protocol — with a 16x16 PNG capability swatch attached.
 * It is called by a model mid-conversation. This one is typed by a human at a
 * shell, reports the install (PHP, extensions, config file, permission policy,
 * provider selection, session database, MCP config), takes no model, and
 * cannot be reached by a tool call. Neither calls the other; the only thing
 * they share is the English word.
 *
 * EXIT CODES are the convention `bin/sugarcrush` and {@see NonInteractive}
 * already document at five exits, reused rather than re-invented:
 *   0 — {@see NonInteractive::EXIT_OK}: the command ran and answered.
 *   1 — {@see NonInteractive::EXIT_FAILURE}: it RAN and FAILED (a doctor check
 *       came back FAIL, `session delete` found no such session).
 *   2 — {@see NonInteractive::EXIT_CONFIG}: usage or pre-flight; nothing was
 *       attempted and a retry cannot help (a missing or unknown operand). Every
 *       one of those goes through {@see NonInteractive::failUsage()}, which is
 *       also what emits the `--output-format json` error document — so a
 *       subcommand misuse produces the same one-JSON-object-on-stdout shape as
 *       an unrecognized flag, rather than a second reporting channel.
 */
final class Subcommands
{
    /**
     * The shells {@see completion()} can emit a script for.
     *
     * @var list<string>
     */
    public const SHELLS = ['bash', 'zsh', 'fish'];

    /**
     * How many rows `session list` shows unless `--limit` narrows it. Matches
     * {@see \SugarCraft\Crush\Session\SessionStore::listSessions()}'s own
     * default so the CLI shows what the store considers a page.
     */
    private const SESSION_LIST_LIMIT = 20;

    /**
     * Run the subcommand $args carries and return the process exit code.
     *
     * Called by `bin/sugarcrush` only when `$args->subcommand !== null`, after
     * every global pre-flight check (unknown flags, `--root`, `--config`) has
     * passed and after `Bootstrap::useConfigPath()` has registered the
     * override — so `sugarcrush --config /tmp/x.json doctor` reports the policy
     * in `/tmp/x.json`, which is the whole point of asking.
     */
    public static function dispatch(ParsedArgs $args): int
    {
        return match ($args->subcommand) {
            'doctor'     => self::doctor($args),
            'models'     => self::models($args),
            'session'    => self::session($args),
            'mcp'        => self::mcp($args),
            'completion' => self::completion($args),
            // Unreachable: ArgvParser only ever stores a ParsedArgs::SUBCOMMANDS
            // member. Answered rather than asserted because the two lists
            // drifting apart must not be a PHP fatal on a user's terminal.
            default => NonInteractive::failUsage(
                \sprintf('sugarcrush: %s: unknown subcommand', (string) $args->subcommand),
                $args->outputFormat,
                'Valid subcommands are: ' . \implode(', ', ParsedArgs::SUBCOMMANDS) . '.',
            ),
        };
    }


    /**
     * Put exactly one contract document on stdout, whatever happens.
     *
     * Shares {@see NonInteractive::encodeDocument()} — and therefore the
     * `JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR` flags and the one
     * definition of the document shape — with the one-shot path. The catch is
     * the same last-resort literal {@see NonInteractive::emitErrorDocument()}
     * carries and exists for the same reason: an EMPTY stdout at exit 0 is the
     * one outcome a `| jq` consumer cannot recover from, and a session name or
     * an MCP `command` string is bytes this package does not control. It is not
     * reachable with those flags; it is here so that if it ever becomes
     * reachable the failure is a readable document rather than a fatal.
     *
     * @param array<string, mixed> $document
     */
    private static function emitDocument(array $document): void
    {
        try {
            echo NonInteractive::encodeDocument($document) . "\n";
        } catch (\JsonException) {
            echo '{"result":null,"error":{"type":"encoding","message":"the answer could not be encoded as JSON"}}' . "\n";
        }
    }

    /**
     * A verb that takes NO operands got one.
     *
     * `session`, `mcp` and `completion` all reject an unknown operand at exit
     * 2; `doctor` and `models` silently discarded theirs, so `sugarcrush
     * models delete everything` printed the provider table and exited 0. A
     * word the CLI ignores is a word the user believes did something.
     */
    private static function rejectOperands(string $verb, ParsedArgs $args): int
    {
        return NonInteractive::failUsage(
            \sprintf('sugarcrush: %s %s: unexpected operand', $verb, (string) $args->subcommandArgs[0]),
            $args->outputFormat,
            'Usage: sugarcrush ' . $verb . ' — this subcommand takes no arguments.',
        );
    }

    // ---------------------------------------------------------------------
    // doctor
    // ---------------------------------------------------------------------

    /**
     * `sugarcrush doctor` — one line per install check, then a verdict.
     *
     * Exit 1 when ANY check is FAIL, 0 otherwise. WARN does not fail the
     * command: a warning here means "this is configured in a way that will
     * surprise you", not "this install is broken", and a CI gate that treats
     * every warning as a failure stops being run.
     */
    private static function doctor(ParsedArgs $args): int
    {
        if ($args->subcommandArgs !== []) {
            return self::rejectOperands('doctor', $args);
        }

        $checks = [];
        foreach (self::doctorProbes($args) as $label => $probe) {
            // EVERY probe wrapped, individually. This command exists for the
            // broken install, so a throw from any one reader has to become a
            // reported line rather than take the whole report down with it —
            // an unreadable ~/.sugar-crush/config.json makes permissionGate()
            // throw PermissionConfigException, and that is precisely the
            // diagnosis the user ran doctor to get.
            try {
                $checks[] = ['label' => $label] + $probe();
            } catch (\Throwable $e) {
                $checks[] = [
                    'label' => $label,
                    'status' => 'FAIL',
                    'detail' => $e::class . ': ' . $e->getMessage(),
                ];
            }
        }

        $failed = \array_filter($checks, static fn (array $c): bool => $c['status'] === 'FAIL');

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument([
                'result' => ['checks' => \array_values($checks), 'failed' => \count($failed)],
            ]);

            return $failed === [] ? NonInteractive::EXIT_OK : NonInteractive::EXIT_FAILURE;
        }

        $width = 0;
        foreach ($checks as $check) {
            $width = \max($width, \strlen($check['label']));
        }

        foreach ($checks as $check) {
            \printf(
                "%-4s %-{$width}s  %s\n",
                $check['status'],
                $check['label'],
                $check['detail'],
            );
        }

        echo "\n" . ($failed === []
            ? "No problems detected.\n"
            : \sprintf("%d check%s failed.\n", \count($failed), \count($failed) === 1 ? '' : 's'));

        return $failed === [] ? NonInteractive::EXIT_OK : NonInteractive::EXIT_FAILURE;
    }

    /**
     * The DSN the `pdo_sqlite` probe opens, whose SCHEME is the one
     * {@see \SugarCraft\Crush\Session\SessionStore}'s constructor builds
     * (`new PDO("sqlite:$dbPath")`). `:memory:` rather than a path so the
     * probe touches no file and creates no database.
     *
     * The linkage is asserted, not asserted-about: a test compares this
     * scheme against the DSN literal in SessionStore's own source, because a
     * probe of some OTHER driver would report a green install on a box where
     * every session write fatals — which is exactly what checking the
     * manifest's `ext-sqlite3` (declared, and called by nothing in src/) would
     * have done.
     */
    private const SESSION_STORE_PROBE_DSN = 'sqlite::memory:';

    /**
     * Can PDO actually open this DSN?
     *
     * EXERCISED, not name-checked. `extension_loaded('pdo_sqlite')` was the
     * earlier spelling and it encodes an extension NAME — swap the name for a
     * neighbouring one and the check goes on passing while the capability is
     * gone. Opening the driver is the same act SessionStore performs, so there
     * is no name left to get wrong, and the failing branch is reachable in a
     * test by handing it a DSN no driver claims.
     *
     * @return array{status: string, detail: string}
     */
    private static function pdoDriverProbe(string $dsn): array
    {
        $driver = \strtok($dsn, ':');
        $driver = $driver === false ? $dsn : $driver;

        try {
            new \PDO($dsn);
            $usable = true;
            $why = '';
        } catch (\Throwable $e) {
            $usable = false;
            $why = ': ' . $e->getMessage();
        }

        return [
            'status' => $usable ? 'OK' : 'FAIL',
            'detail' => 'pdo_' . $driver . ' ' . ($usable
                ? 'usable'
                : 'UNUSABLE — the session store cannot open its database' . $why)
                . '; ext-sqlite3 (declared in composer.json, unused by the code) '
                . (\extension_loaded('sqlite3') ? 'loaded' : 'absent'),
        ];
    }

    /**
     * The doctor's checks, as label => closure returning
     * `{status: 'OK'|'WARN'|'FAIL', detail: string}`.
     *
     * Closures rather than values so {@see doctor()} can wrap each one: a
     * pre-computed array would evaluate every reader before the try/catch and
     * so let the first throw kill the whole report.
     *
     * @return array<string, \Closure(): array{status: string, detail: string}>
     */
    private static function doctorProbes(ParsedArgs $args): array
    {
        return [
            'sugarcrush' => static fn (): array => [
                'status' => 'OK',
                'detail' => Help::versionString(),
            ],

            'php' => static fn (): array => [
                // ^8.3 is this package's own composer `require`. Checked at
                // RUNTIME anyway because a `php8.2 bin/sugarcrush` on a box
                // where Composer installed under 8.3 satisfies the manifest and
                // still cannot run the code.
                'status' => \PHP_VERSION_ID >= 80300 ? 'OK' : 'FAIL',
                'detail' => \PHP_VERSION . ' at ' . (\PHP_BINARY !== '' ? \PHP_BINARY : 'unknown')
                    . (\PHP_VERSION_ID >= 80300 ? '' : ' (sugarcrush requires PHP 8.3+)'),
            ],

            'pdo_sqlite' => static fn (): array => self::pdoDriverProbe(self::SESSION_STORE_PROBE_DSN),

            'ext-curl' => static fn (): array => [
                // `suggest`, not `require`: only the HTTP providers need it, so
                // a missing curl is a warning on an install that may be running
                // the echo or shell-out backend entirely legitimately.
                'status' => \extension_loaded('curl') ? 'OK' : 'WARN',
                'detail' => \extension_loaded('curl')
                    ? 'loaded'
                    : 'missing — the HTTP providers (openai, anthropic, sglang, custom) will fail',
            ],

            'config file' => static function (): array {
                $path = Bootstrap::userConfigPath();
                if (!\is_file($path)) {
                    // ABSENT IS NOT BROKEN. A first run has no config file and
                    // every default applies; reporting FAIL here would tell a
                    // healthy install it is sick.
                    return ['status' => 'OK', 'detail' => $path . ' (absent — defaults apply)'];
                }
                if (!\is_readable($path)) {
                    return ['status' => 'FAIL', 'detail' => $path . ' is not readable'];
                }
                $raw = (string) \file_get_contents($path);
                try {
                    \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    return ['status' => 'FAIL', 'detail' => $path . ': invalid JSON (' . $e->getMessage() . ')'];
                }

                return ['status' => 'OK', 'detail' => $path];
            },

            'permission policy' => static function (): array {
                // The one probe whose FAILURE MODE IS THE POINT: a config with
                // an unusable permissionMode makes bin/sugarcrush refuse to
                // launch at all (PermissionConfigException -> exit 2), and
                // before this command existed the only way to see why was to
                // read that message off a failed launch. Caught HERE rather
                // than by doctor()'s generic wrapper so the line names the
                // policy instead of a class name.
                try {
                    $gate = Bootstrap::permissionGate();
                } catch (PermissionConfigException $e) {
                    return ['status' => 'FAIL', 'detail' => $e->getMessage()];
                }

                return ['status' => 'OK', 'detail' => 'mode ' . $gate->mode()->value];
            },

            'provider' => static function (): array {
                [$name, $model] = Bootstrap::selectedProviderLabel();
                if (Bootstrap::selectedProviderName() === null) {
                    // 'echo' means no provider is selected at all — usable, but
                    // every answer is canned, which is worth saying out loud to
                    // someone who ran doctor because "it isn't answering".
                    return [
                        'status' => $name === 'echo' ? 'WARN' : 'OK',
                        'detail' => $name === 'echo'
                            ? 'none selected — the offline echo backend will answer (set SUGARCRUSH_PROVIDER)'
                            : 'shell-out backend ($SUGARCRUSH_BACKEND_CMD tier)',
                    ];
                }

                // NOT CONSTRUCTED, deliberately. Bootstrap::backendFor() builds
                // the tool array, which reaches mcpClient(), which proc_open()s
                // every configured MCP server — a health check must not launch
                // programs. So this reports the SELECTION and whether a config
                // for it exists, which is what a misconfiguration looks like.
                $known = \array_key_exists($name, Bootstrap::availableProviders());

                return [
                    'status' => $known ? 'OK' : 'FAIL',
                    'detail' => $known
                        ? $name . ' (model ' . $model . ')'
                        : $name . ' is selected but is not a known provider — see `sugarcrush models`',
                ];
            },

            'session store' => static function (): array {
                // prune: false — A DIAGNOSTIC MUST NOT DELETE CONVERSATIONS.
                // Bootstrap::sessionStore() applies the opt-in
                // SUGARCRUSH_SESSION_RETENTION_DAYS sweep on construction, so
                // the plain accessor made `sugarcrush doctor` destroy rows:
                // MEASURED with two rows aged to 2020 and a 7-day window, a
                // doctor run printed "retention removed 1 unnamed session" and
                // the table went 2 -> 1. Retention belongs to a LAUNCH, which
                // is the one moment no session is open; a health check is
                // read-only by contract.
                $store = Bootstrap::sessionStore(prune: false);
                $count = \count($store->listSessions(1000));

                return ['status' => 'OK', 'detail' => $count . ' session(s) stored'];
            },

            'mcp config' => static function () use ($args): array {
                $inventory = Bootstrap::mcpServerInventory($args->root);

                return match (true) {
                    $inventory['status'] === Bootstrap::MCP_ABSENT
                        => ['status' => 'OK', 'detail' => 'no ' . Bootstrap::MCP_CONFIG_FILENAME . ' in this project'],
                    $inventory['status'] === Bootstrap::MCP_OUTSIDE_TREE
                        => ['status' => 'FAIL', 'detail' => $inventory['path'] . ' resolves outside the project tree; it is ignored'],
                    $inventory['status'] === Bootstrap::MCP_UNTRUSTED
                        => ['status' => 'WARN', 'detail' => $inventory['path'] . ' is present but this root is not trusted; its servers are not started'],
                    $inventory['error'] !== null
                        => ['status' => 'FAIL', 'detail' => $inventory['path'] . ' ' . $inventory['error']],
                    default
                        => ['status' => 'OK', 'detail' => \count($inventory['servers']) . ' server(s) declared in ' . $inventory['path']],
                };
            },
        ];
    }

    // ---------------------------------------------------------------------
    // models
    // ---------------------------------------------------------------------

    /**
     * `sugarcrush models` — every provider this install can select, with the
     * model each one defaults to, and a marker on the selected one.
     *
     * Reads {@see Bootstrap::availableProviders()}, which is the SAME
     * enumeration `Bootstrap::backendFor()` resolves a name against and the
     * same one the Ctrl+P "Switch model" palette offers. Re-deriving the list
     * from `ProviderFactory` here would have been a second discovery path that
     * silently omits the `config.dev.json` overlay the first one applies.
     */
    private static function models(ParsedArgs $args): int
    {
        if ($args->subcommandArgs !== []) {
            return self::rejectOperands('models', $args);
        }

        $selected = Bootstrap::selectedProviderName();
        $providers = Bootstrap::availableProviders();
        \ksort($providers);

        $rows = [];
        foreach ($providers as $name => $config) {
            $model = $config['model'] ?? null;
            $rows[] = [
                'provider' => $name,
                'model' => \is_string($model) && $model !== '' ? $model : 'unknown',
                'selected' => $name === $selected,
            ];
        }

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument([
                'result' => ['providers' => $rows, 'selected' => $selected],
            ]);

            return NonInteractive::EXIT_OK;
        }

        if ($rows === []) {
            // Exit 0, not 1: an install with no provider configured is a
            // correctly-answered question, not a command that failed.
            echo "No providers are configured.\n";

            return NonInteractive::EXIT_OK;
        }

        $width = 0;
        foreach ($rows as $row) {
            $width = \max($width, \strlen($row['provider']));
        }
        foreach ($rows as $row) {
            \printf("%s %-{$width}s  %s\n", $row['selected'] ? '*' : ' ', $row['provider'], $row['model']);
        }
        echo "\n" . ($selected === null
            ? "No provider is selected; set SUGARCRUSH_PROVIDER or use Ctrl+P \"Switch model\".\n"
            : '* = selected (SUGARCRUSH_PROVIDER or ' . Bootstrap::userConfigPath() . ").\n");

        return NonInteractive::EXIT_OK;
    }

    // ---------------------------------------------------------------------
    // session
    // ---------------------------------------------------------------------

    /**
     * `sugarcrush session list` / `sugarcrush session delete <id>`.
     *
     * Both go through {@see Bootstrap::sessionStore()} — the accessor the TUI
     * and the one-shot path already use — rather than opening
     * `~/.sugar-crush/session.db` directly, so the id printed by `list` is
     * exactly the id `delete` and a resumed session accept. NOTE that accessor
     * also applies the configured RETENTION sweep on construction, which is the
     * launch behaviour and is inherited here deliberately: a `session list`
     * that showed rows the next launch would delete would be lying about what
     * is stored.
     */
    private static function session(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[0] ?? null;

        return match ($verb) {
            'list' => self::sessionList($args),
            'delete' => self::sessionDelete($args),
            null => NonInteractive::failUsage(
                'sugarcrush: session: no action given',
                $args->outputFormat,
                'Usage: sugarcrush session list | sugarcrush session delete <id>',
            ),
            default => NonInteractive::failUsage(
                \sprintf('sugarcrush: session %s: unknown action', $verb),
                $args->outputFormat,
                'Usage: sugarcrush session list | sugarcrush session delete <id>',
            ),
        };
    }

    private static function sessionList(ParsedArgs $args): int
    {
        $rows = Bootstrap::sessionStore()->listSessions(self::SESSION_LIST_LIMIT);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['sessions' => \array_values($rows)]]);

            return NonInteractive::EXIT_OK;
        }

        if ($rows === []) {
            echo "No sessions stored.\n";

            return NonInteractive::EXIT_OK;
        }

        foreach ($rows as $row) {
            \printf(
                "%-36s  %-19s  %-24s  %s\n",
                (string) ($row['id'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
                (string) ($row['provider'] ?? '') . '/' . (string) ($row['model'] ?? ''),
                (string) ($row['name'] ?? '(unnamed)'),
            );
        }

        return NonInteractive::EXIT_OK;
    }

    private static function sessionDelete(ParsedArgs $args): int
    {
        $id = $args->subcommandArgs[1] ?? null;
        if ($id === null || $id === '') {
            return NonInteractive::failUsage(
                'sugarcrush: session delete: no session id given',
                $args->outputFormat,
                'Usage: sugarcrush session delete <id> — run `sugarcrush session list` for the ids.',
            );
        }

        $store = Bootstrap::sessionStore();
        if ($store->getSession($id) === null) {
            // EXIT 1, NOT 2, and the distinction is the documented one: the
            // store WAS opened and queried, so something was attempted. Exit 2
            // means nothing ran — which is what a MISSING id above means, and
            // is why the two branches of "delete went wrong" report differently.
            \fwrite(\STDERR, \sprintf("sugarcrush: session %s: no such session\n", $id));
            if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
                self::emitDocument([
                    'result' => null,
                    'error' => ['type' => 'not-found', 'message' => 'no such session: ' . $id],
                ]);
            }

            return NonInteractive::EXIT_FAILURE;
        }

        $store->deleteSession($id);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['deleted' => $id]]);
        } else {
            echo 'Deleted session ' . $id . "\n";
        }

        return NonInteractive::EXIT_OK;
    }

    // ---------------------------------------------------------------------
    // mcp
    // ---------------------------------------------------------------------

    /**
     * `sugarcrush mcp list` — what `.mcp.json` declares, and whether this
     * launch would start it.
     *
     * READ-ONLY BY CONSTRUCTION. It goes through
     * {@see Bootstrap::mcpServerInventory()}, which shares its path,
     * containment and trust decision with {@see Bootstrap::mcpClient()} but
     * never calls `proc_open()` — see that method for why listing must not be
     * implemented by asking for the client.
     */
    private static function mcp(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[0] ?? null;
        if ($verb !== 'list') {
            return NonInteractive::failUsage(
                $verb === null
                    ? 'sugarcrush: mcp: no action given'
                    : \sprintf('sugarcrush: mcp %s: unknown action', $verb),
                $args->outputFormat,
                'Usage: sugarcrush mcp list',
            );
        }

        $inventory = Bootstrap::mcpServerInventory($args->root);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            if ($inventory['error'] !== null) {
                // THE SAME EXIT CODE THE TEXT ARM GIVES for the same install
                // state. Returning 0 here because "the question was answered"
                // made `sugarcrush mcp list --output-format json || fail` a CI
                // gate that never fires while `sugarcrush mcp list` next to it
                // exits 1 — one install, two verdicts, decided by a formatting
                // flag. Only a config that was found, trusted and then failed
                // to READ takes this branch; absent/outside/untrusted stay at
                // exit 0 below, exactly as the text arm does, because those
                // are answers rather than failures.
                //
                // Shape is the package's ONE error envelope —
                // {"result":null,"error":{"type","message"}}, the same
                // {@see NonInteractive::failUsage()} emits. The earlier
                // spelling put the string at `result.error`, so a consumer
                // branching on top-level `.error` read null on a failure.
                self::emitDocument([
                    'result' => null,
                    'error' => [
                        'type' => 'mcp-config',
                        'message' => $inventory['path'] . ' ' . $inventory['error'],
                    ],
                ]);

                return NonInteractive::EXIT_FAILURE;
            }

            self::emitDocument(['result' => $inventory]);

            // Exit 0 for a refused (absent/outside/untrusted) config: the
            // question "what does this project declare" was answered
            // correctly. The STATUS field carries the refusal, which is what a
            // consumer branches on — and the text arm returns 0 for the same
            // three statuses.
            return NonInteractive::EXIT_OK;
        }

        switch ($inventory['status']) {
            case Bootstrap::MCP_ABSENT:
                echo 'No ' . Bootstrap::MCP_CONFIG_FILENAME . " in this project (looked for {$inventory['path']}).\n";

                return NonInteractive::EXIT_OK;

            case Bootstrap::MCP_OUTSIDE_TREE:
                echo $inventory['path'] . " resolves outside the project tree and is ignored.\n";

                return NonInteractive::EXIT_OK;

            case Bootstrap::MCP_UNTRUSTED:
                echo $inventory['path'] . " is present but this project root is not trusted,\n"
                    . "so its servers are not started and are not listed. Add the root to\n"
                    . '"trustedProjectMcp" in ' . Bootstrap::userConfigPath() . " to opt in.\n";

                return NonInteractive::EXIT_OK;
        }

        if ($inventory['error'] !== null) {
            \fwrite(\STDERR, 'sugarcrush: ' . $inventory['path'] . ' ' . $inventory['error'] . "\n");

            // Exit 1: the file was found, trusted and READ, and reading it is
            // what failed. Nothing about a retry helps, but something did run —
            // the same reading `session delete <unknown id>` gets.
            return NonInteractive::EXIT_FAILURE;
        }

        if ($inventory['servers'] === []) {
            echo $inventory['path'] . " declares no servers.\n";

            return NonInteractive::EXIT_OK;
        }

        $width = 0;
        foreach ($inventory['servers'] as $server) {
            $width = \max($width, \strlen($server['name']));
        }
        foreach ($inventory['servers'] as $server) {
            \printf("%-{$width}s  %-5s  %s\n", $server['name'], $server['type'], $server['detail']);
        }

        return NonInteractive::EXIT_OK;
    }

    // ---------------------------------------------------------------------
    // completion
    // ---------------------------------------------------------------------

    /**
     * `sugarcrush completion bash|zsh|fish` — a completion script on stdout,
     * for `eval "$(sugarcrush completion bash)"` or redirection into the
     * shell's own completions directory.
     *
     * THREE REAL DIALECTS, not one script under three labels. bash uses
     * `complete -F` with `COMPREPLY`/`compgen`; zsh uses `#compdef` with
     * `_arguments`/`_describe`, which is a different language and gives the
     * per-option descriptions bash has nowhere to put; fish uses one
     * `complete -c` line per option, with `-n` conditions rather than a
     * dispatch function, because fish has no COMPREPLY equivalent at all.
     * Emitting bash syntax under a `zsh` label would be worse than emitting
     * nothing: `compinit` would source it and break the user's completion.
     */
    private static function completion(ParsedArgs $args): int
    {
        $shell = $args->subcommandArgs[0] ?? null;

        if ($shell === null || !\in_array($shell, self::SHELLS, true)) {
            return NonInteractive::failUsage(
                $shell === null
                    ? 'sugarcrush: completion: no shell given'
                    : \sprintf('sugarcrush: completion %s: unsupported shell', $shell),
                $args->outputFormat,
                'Usage: sugarcrush completion ' . \implode('|', self::SHELLS),
            );
        }

        // Deliberately NOT wrapped in the JSON document even under
        // `--output-format json`: the output of this command is a shell script
        // that gets `eval`'d, and JSON-quoting it would produce something no
        // shell can source. Same reasoning as `--help` and `--version`, which
        // are also plain text at exit 0 with no document.
        echo match ($shell) {
            'bash' => self::bashCompletion(),
            'zsh' => self::zshCompletion(),
            'fish' => self::fishCompletion(),
        };

        return NonInteractive::EXIT_OK;
    }

    /**
     * Every option the three completion scripts offer, with the SHAPE of its
     * value — one table, so bash, zsh and fish cannot drift apart on which
     * flags take a path and which take nothing.
     *
     * `value`: null = a bare switch; 'text' = free text; 'dir'/'file' = a path
     * the shell should complete; 'format' = one of
     * {@see ParsedArgs::OUTPUT_FORMATS}; 'mode' = one of
     * {@see \SugarCraft\Crush\Permissions\PermissionMode::cases()}, derived at generation time rather than
     * written out so a mode added to the enum cannot go un-completable. The three generators below translate
     * that one column into three genuinely different dialects rather than
     * sharing a script — see {@see completion()}.
     *
     * @var array<string, array{short: string|null, value: string|null, desc: string}>
     */
    private const OPTIONS = [
        '--prompt' => ['short' => '-p', 'value' => 'text', 'desc' => 'Run a single prompt and exit (one-shot mode)'],
        '--output-format' => ['short' => null, 'value' => 'format', 'desc' => 'Output format: text or json'],
        '--root' => ['short' => null, 'value' => 'dir', 'desc' => 'Use <dir> as the project root'],
        '--config' => ['short' => null, 'value' => 'file', 'desc' => 'Read settings and permissions from <file>'],
        '--model' => ['short' => null, 'value' => 'text', 'desc' => 'Conversation model name (not a provider)'],
        '--permission-mode' => ['short' => null, 'value' => 'mode', 'desc' => 'Permission mode to run under'],
        '--help' => ['short' => '-h', 'value' => null, 'desc' => 'Show the help screen'],
        '--version' => ['short' => '-v', 'value' => null, 'desc' => 'Show the installed version'],
    ];

    /**
     * The one-line summary each subcommand gets in the two shells that have
     * somewhere to put one (zsh's `_describe`, fish's `-d`). bash's `compgen
     * -W` takes bare words only, which is why its script shows names alone.
     *
     * @var array<string, string>
     */
    private const SUBCOMMAND_DESCRIPTIONS = [
        'run' => 'Run a single prompt and exit (alias for --prompt)',
        'completion' => 'Emit a shell completion script',
        'doctor' => 'Report on this installation and exit',
        'mcp' => 'Inspect the project MCP configuration',
        'models' => 'List the providers this install can select',
        'session' => 'Manage stored sessions',
    ];

    /**
     * Each subcommand's own operands, for the nested completion the three
     * scripts all offer after the verb.
     *
     * @var array<string, list<string>>
     */
    private const SUBCOMMAND_ACTIONS = [
        'session' => ['list', 'delete'],
        'mcp' => ['list'],
        'completion' => self::SHELLS,
    ];

    /**
     * The permission modes, space-separated, for the three completion dialects.
     *
     * DERIVED from the enum rather than listed, for the reason the OPTIONS
     * docblock gives: a literal list here would be a set of modes measured once
     * and then quietly wrong about the enum it claims to describe.
     */
    private static function permissionModeWords(): string
    {
        return \implode(' ', \array_map(
            static fn (\SugarCraft\Crush\Permissions\PermissionMode $m): string => $m->value,
            \SugarCraft\Crush\Permissions\PermissionMode::cases(),
        ));
    }

    private static function bashCompletion(): string
    {
        $verbs = \implode(' ', \array_keys(self::SUBCOMMAND_DESCRIPTIONS));
        $formats = \implode(' ', ParsedArgs::OUTPUT_FORMATS);

        // compgen -d for a directory-valued option, -f for a file-valued one:
        // offering the subcommand word list after `--root` would be actively
        // wrong, which is the whole reason this case block exists.
        $valueCases = '';
        foreach (self::OPTIONS as $flag => $spec) {
            $action = match ($spec['value']) {
                'dir' => 'COMPREPLY=($(compgen -d -- "$cur"))',
                'file' => 'COMPREPLY=($(compgen -f -- "$cur"))',
                'format' => 'COMPREPLY=($(compgen -W "' . $formats . '" -- "$cur"))',
                'mode' => 'COMPREPLY=($(compgen -W "' . self::permissionModeWords() . '" -- "$cur"))',
                default => null,
            };
            if ($action !== null) {
                $valueCases .= \sprintf("        %s) %s; return ;;\n", $flag, $action);
            }
        }
        foreach (self::SUBCOMMAND_ACTIONS as $verb => $actions) {
            $valueCases .= \sprintf(
                "        %s) COMPREPLY=(\$(compgen -W \"%s\" -- \"\$cur\")); return ;;\n",
                $verb,
                \implode(' ', $actions),
            );
        }

        $options = \implode(' ', \array_keys(self::OPTIONS));

        return "# bash completion for sugarcrush. Install with:\n"
            . "#   eval \"\$(sugarcrush completion bash)\"\n"
            . "_sugarcrush() {\n"
            . "    local cur prev\n"
            . "    cur=\"\${COMP_WORDS[COMP_CWORD]}\"\n"
            . "    prev=\"\${COMP_WORDS[COMP_CWORD-1]}\"\n"
            . "\n"
            . "    case \"\$prev\" in\n"
            . $valueCases
            . "    esac\n"
            . "\n"
            . "    if [[ \"\$cur\" == -* ]]; then\n"
            . "        COMPREPLY=(\$(compgen -W \"" . $options . "\" -- \"\$cur\"))\n"
            . "        return\n"
            . "    fi\n"
            . "\n"
            . "    COMPREPLY=(\$(compgen -W \"" . $verbs . "\" -- \"\$cur\"))\n"
            . "}\n"
            . "complete -F _sugarcrush sugarcrush\n";
    }

    /**
     * zsh's `_arguments`, which is a different language from bash's
     * `compgen` and not a translation of it: the description goes INSIDE the
     * spec's brackets, and a value-taking option carries a
     * `:message:action` tail whose action is a real completer
     * (`_files -/` for a directory, `(text json)` for a fixed set). bash has
     * nowhere to put any of that.
     */
    private static function zshCompletion(): string
    {
        $specs = [];
        foreach (self::OPTIONS as $flag => $spec) {
            $tail = match ($spec['value']) {
                'dir' => ':directory:_files -/',
                'file' => ':file:_files',
                'format' => ':format:(' . \implode(' ', ParsedArgs::OUTPUT_FORMATS) . ')',
                'mode' => ':mode:(' . self::permissionModeWords() . ')',
                'text' => ':text:',
                default => '',
            };
            // The exclusion list keeps zsh from offering `--help` again once
            // `-h` is on the line; a spec pair without it double-offers.
            $exclusion = $spec['short'] === null ? '' : '(' . $spec['short'] . ' ' . $flag . ')';
            $specs[] = "        '" . $exclusion . $flag . '[' . $spec['desc'] . ']' . $tail . "'";
            if ($spec['short'] !== null) {
                $specs[] = "        '(" . $spec['short'] . ' ' . $flag . ')' . $spec['short']
                    . '[' . $spec['desc'] . ']' . $tail . "'";
            }
        }

        $verbs = [];
        foreach (self::SUBCOMMAND_DESCRIPTIONS as $verb => $desc) {
            $verbs[] = "        '" . $verb . ':' . $desc . "'";
        }

        $actionCases = '';
        foreach (self::SUBCOMMAND_ACTIONS as $verb => $actions) {
            $actionCases .= \sprintf(
                "                %s) _values 'action' %s ;;\n",
                $verb,
                \implode(' ', $actions),
            );
        }

        return "#compdef sugarcrush\n"
            . "# zsh completion for sugarcrush. Install it somewhere on \$fpath as\n"
            . "# _sugarcrush, e.g. sugarcrush completion zsh > \"\${fpath[1]}/_sugarcrush\"\n"
            . "_sugarcrush() {\n"
            . "    local state\n"
            . "    local -a subcommands\n"
            . "    subcommands=(\n"
            . \implode("\n", $verbs) . "\n"
            . "    )\n"
            . "\n"
            . "    _arguments -C \\\n"
            . \implode(" \\\n", $specs) . " \\\n"
            . "        '1: :->subcommand' \\\n"
            . "        '*:: :->args'\n"
            . "\n"
            . "    case \$state in\n"
            . "        subcommand) _describe -t commands 'sugarcrush subcommand' subcommands ;;\n"
            . "        args)\n"
            . "            case \$words[1] in\n"
            . $actionCases
            . "            esac\n"
            . "            ;;\n"
            . "    esac\n"
            . "}\n"
            . "\n"
            . "_sugarcrush \"\$@\"\n";
    }

    /**
     * fish has no COMPREPLY equivalent and no dispatch function: completion is
     * declarative, one `complete -c` line per rule, gated by `-n` conditions.
     * So this is a third real dialect rather than a relabelling — note that
     * the blanket `complete -c sugarcrush -f` turns file completion OFF (the
     * first argument is a verb, not a path) and `-F` turns it back on for
     * exactly the two options that take one.
     */
    private static function fishCompletion(): string
    {
        $lines = [
            '# fish completion for sugarcrush. Install with:',
            '#   sugarcrush completion fish > ~/.config/fish/completions/sugarcrush.fish',
            '',
            '# The first argument is a subcommand, not a path.',
            'complete -c sugarcrush -f',
            '',
        ];

        foreach (self::SUBCOMMAND_DESCRIPTIONS as $verb => $desc) {
            $lines[] = \sprintf(
                'complete -c sugarcrush -n "__fish_use_subcommand" -a %s -d %s',
                \escapeshellarg($verb),
                \escapeshellarg($desc),
            );
        }
        $lines[] = '';

        foreach (self::OPTIONS as $flag => $spec) {
            $line = 'complete -c sugarcrush -l ' . \substr($flag, 2);
            if ($spec['short'] !== null) {
                $line .= ' -s ' . \substr($spec['short'], 1);
            }
            $line .= match ($spec['value']) {
                // -r requires a value; -F re-enables the file completion the
                // blanket -f above switched off; -x is -r plus "these values
                // only", which is right for a closed set and wrong for a path.
                'dir', 'file' => ' -r -F',
                'format' => ' -x -a ' . \escapeshellarg(\implode(' ', ParsedArgs::OUTPUT_FORMATS)),
                'mode' => ' -x -a ' . \escapeshellarg(self::permissionModeWords()),
                'text' => ' -r',
                default => '',
            };
            $lines[] = $line . ' -d ' . \escapeshellarg($spec['desc']);
        }
        $lines[] = '';

        foreach (self::SUBCOMMAND_ACTIONS as $verb => $actions) {
            $lines[] = \sprintf(
                'complete -c sugarcrush -n %s -a %s -d %s',
                \escapeshellarg('__fish_seen_subcommand_from ' . $verb),
                \escapeshellarg(\implode(' ', $actions)),
                \escapeshellarg($verb . ' argument'),
            );
        }
        $lines[] = '';

        return \implode("\n", $lines);
    }
}

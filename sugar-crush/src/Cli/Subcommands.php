<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionQuery;
use SugarCraft\Crush\Session\SessionResolver;
use SugarCraft\Crush\Session\SessionRow;
use SugarCraft\Crush\Session\TitleSource;
use SugarCraft\Crush\Util\Exporter;

/**
 * The real CLI subcommands — `mcp list`, `session list|show|rename|delete|
 * pin|unpin|archive|unarchive`, `models`, `doctor`, `serve` and `completion
 * bash|zsh|fish` (crush_code.md Phase 4 item 6; the session verbs past
 * `list`/`delete` are Appendix P §3.4; `serve` is Appendix O §4.7, its body in
 * {@see Serve} — the one verb that keeps running rather than answering, and
 * still constructs no `Program`; `attach` is Appendix O §4.9, its body in
 * {@see Attach} — the one verb that DOES run the TUI, over a running server's
 * session, and only once it has connected).
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
            'serve'      => Serve::run($args),
            'attach'     => Attach::run($args),
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

            'server mode' => static function (): array {
                // WARN, never FAIL: an install without these runs the TUI and
                // -p perfectly well; only `serve` refuses to start.
                $missing = \SugarCraft\Crush\Server\Preflight::detect()->environmentProblems();

                return [
                    'status' => $missing === [] ? 'OK' : 'WARN',
                    'detail' => $missing === []
                        ? 'pcntl, posix and ffi available — `serve` can start'
                        : '`serve` will refuse to start: ' . \implode('; ', $missing),
                ];
            },

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
                    $tui = Bootstrap::permissionGate(interactive: true);
                } catch (PermissionConfigException $e) {
                    return ['status' => 'FAIL', 'detail' => $e->getMessage()];
                }

                // With nothing configured the two paths start in different
                // modes (decision D5), and a one-word answer would describe
                // only one of them.
                return ['status' => 'OK', 'detail' => $tui->mode() === $gate->mode()
                    ? 'mode ' . $gate->mode()->value
                    : 'mode ' . $tui->mode()->value . ' in the TUI, ' . $gate->mode()->value
                        . ' for -p and background sessions (built-in defaults)'];
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
     * `sugarcrush session list|show|rename|delete|pin|unpin|archive|unarchive`
     * (Appendix P §3.4).
     *
     * Every verb goes through {@see Bootstrap::sessionStore()} — the accessor
     * the TUI and the one-shot path already use — rather than opening
     * `~/.sugar-crush/session.db` directly, so the id printed by `list` is
     * exactly the id the other verbs and a resumed session accept. NOTE that
     * accessor also applies the configured RETENTION sweep on construction,
     * which is the launch behaviour and is inherited here deliberately: a
     * `session list` that showed rows the next launch would delete would be
     * lying about what is stored.
     *
     * A TARGET is an id, a name or a unique id prefix, resolved by
     * {@see \SugarCraft\Crush\Session\SessionResolver} — the same resolver
     * `--resume` uses, over every kind and archived rows too. Nothing matching
     * is exit 1 (`not-found`: the store was asked); an ambiguous prefix is
     * exit 2 with the candidates listed, because the invocation itself cannot
     * be carried out as typed.
     */
    private static function session(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[0] ?? null;
        if ($verb === null) {
            return NonInteractive::failUsage('sugarcrush: session: no action given', $args->outputFormat, self::SESSION_USAGE);
        }
        if (!isset(self::SESSION_ACTION_FLAGS[$verb])) {
            return NonInteractive::failUsage(
                \sprintf('sugarcrush: session %s: unknown action', $verb),
                $args->outputFormat,
                self::SESSION_USAGE,
            );
        }

        foreach (\array_keys($args->subcommandFlags) as $flag) {
            if (!\in_array($flag, self::SESSION_ACTION_FLAGS[$verb], true)) {
                return NonInteractive::failUsage(
                    \sprintf('sugarcrush: session %s: %s does not apply to this action', $verb, $flag),
                    $args->outputFormat,
                    self::SESSION_ACTION_FLAGS[$verb] === []
                        ? 'session ' . $verb . ' takes no options.'
                        : 'session ' . $verb . ' accepts: ' . \implode(', ', self::SESSION_ACTION_FLAGS[$verb]) . '.',
                );
            }
        }

        return match ($verb) {
            'list' => self::sessionList($args),
            'show' => self::sessionShow($args),
            'rename' => self::sessionRename($args),
            'delete' => self::sessionDelete($args),
            default => self::sessionFlag($args, $verb),
        };
    }

    /**
     * One line per verb, for every `session` usage error.
     */
    private const SESSION_USAGE = 'Usage: sugarcrush session list [--all|--archived|--children] [--limit N]'
        . ' | show <target> | rename <target> <title…> | delete <target> [--with-children]'
        . ' | pin|unpin|archive|unarchive <target> — <target> is an id, a name or a unique id prefix.';

    /**
     * Each `session` action and the {@see ParsedArgs::SUBCOMMAND_FLAGS} it
     * accepts. The parser admits any `session` flag after the verb; which
     * action a flag means something to is decided here, so `session show
     * --all` is refused rather than silently ignored.
     *
     * @var array<string, list<string>>
     */
    private const SESSION_ACTION_FLAGS = [
        'list' => ['--all', '--archived', '--children', '--limit'],
        'show' => [],
        'rename' => [],
        'delete' => ['--with-children'],
        'pin' => [],
        'unpin' => [],
        'archive' => [],
        'unarchive' => [],
    ];

    /**
     * `session list`: the user's own conversations (main and branch rows),
     * pinned first and newest first, 20 by default. `--children` adds the
     * sub-agent and background rows that hang under them, `--archived` adds
     * archived rows, `--all` is both, and `--limit N` changes the page size.
     *
     * The text row is `★ id updated kind turns provider/model name`, with
     * `[archived]` after an archived row's name; the JSON rows are
     * {@see \SugarCraft\Crush\Session\SessionRow::toArray()}, which carries
     * every column the store keeps (kind, parent, pinned, archived_at, turns,
     * cwd, branch, preview).
     */
    private static function sessionList(ParsedArgs $args): int
    {
        if (\count($args->subcommandArgs) > 1) {
            return self::rejectSessionOperand('list', $args->subcommandArgs[1], $args);
        }

        $limit = self::SESSION_LIST_LIMIT;
        if (isset($args->subcommandFlags['--limit'])) {
            $raw = (string) $args->subcommandFlags['--limit'];
            if (\preg_match('/^[1-9]\d{0,8}$/', $raw) !== 1) {
                return NonInteractive::failUsage(
                    \sprintf('sugarcrush: session list --limit %s: not a positive whole number', $raw),
                    $args->outputFormat,
                    'Usage: sugarcrush session list --limit <N>, where N is 1 or more.',
                );
            }
            $limit = (int) $raw;
        }

        $all = isset($args->subcommandFlags['--all']);
        $query = SessionQuery::new()->withPinnedFirst()->withLimit($limit);
        if ($all || isset($args->subcommandFlags['--children'])) {
            $query = $query->withKinds();
        }
        if ($all || isset($args->subcommandFlags['--archived'])) {
            $query = $query->withIncludeArchived();
        }

        $rows = Bootstrap::sessionStore()->listSessionsFiltered($query);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['sessions' => \array_map(
                static fn(SessionRow $r): array => $r->toArray(),
                $rows,
            )]]);

            return NonInteractive::EXIT_OK;
        }

        if ($rows === []) {
            echo "No sessions stored.\n";

            return NonInteractive::EXIT_OK;
        }

        $idWidth = 0;
        foreach ($rows as $row) {
            $idWidth = \max($idWidth, \strlen($row->id));
        }
        foreach ($rows as $row) {
            \printf(
                "%s %-{$idWidth}s  %-19s  %-10s  %5s  %-24s  %s\n",
                $row->pinned ? '★' : ' ',
                $row->id,
                $row->updatedAt,
                $row->kind->value,
                $row->turns . 't',
                $row->provider . '/' . $row->model,
                self::sessionLabel($row) . ($row->archived() ? ' [archived]' : ''),
            );
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `session show <target>`: the session's row, then its transcript as
     * Markdown ({@see Exporter::toMarkdown()}, the
     * `/export` formatter, so hidden rows stay hidden). Under
     * `--output-format json` the document is `{"session": <row>, "messages":
     * <Exporter::toJson() rows>}`.
     */
    private static function sessionShow(ParsedArgs $args): int
    {
        if (\count($args->subcommandArgs) > 2) {
            return self::rejectSessionOperand('show', $args->subcommandArgs[2], $args);
        }

        $store = Bootstrap::sessionStore();
        $row = self::resolveSessionTarget('show', $args, $store);
        if (\is_int($row)) {
            return $row;
        }

        $messages = Chat::loadTranscript($store, $row->id);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            $decoded = \json_decode(Exporter::toJson($messages), true);
            self::emitDocument(['result' => [
                'session' => $row->toArray(),
                'messages' => \is_array($decoded) ? $decoded : [],
            ]]);

            return NonInteractive::EXIT_OK;
        }

        echo '# ' . self::sessionLabel($row) . "\n\n";
        echo '- id: ' . $row->id . "\n";
        echo '- kind: ' . $row->kind->value . ($row->parentId !== null ? ' (parent ' . $row->parentId . ')' : '') . "\n";
        echo '- model: ' . $row->provider . '/' . $row->model . "\n";
        echo '- updated: ' . $row->updatedAt . ' · ' . $row->turns . " turn(s)\n";
        if ($row->pinned || $row->archived()) {
            echo '- flags: ' . \implode(', ', \array_filter([$row->pinned ? 'pinned' : null, $row->archived() ? 'archived ' . $row->archivedAt : null])) . "\n";
        }
        echo "\n" . ($messages === [] ? "(no transcript stored)\n" : \rtrim(Exporter::toMarkdown($messages)) . "\n");

        return NonInteractive::EXIT_OK;
    }

    /**
     * `session rename <target> <title…>`: every word after the target is the
     * title, joined with single spaces, so it needs no quoting. Recorded as a
     * USER title, which the auto-titler never overwrites.
     */
    private static function sessionRename(ParsedArgs $args): int
    {
        $title = \trim(\implode(' ', \array_slice($args->subcommandArgs, 2)));
        if (isset($args->subcommandArgs[1]) && $title === '') {
            return NonInteractive::failUsage(
                'sugarcrush: session rename: no title given',
                $args->outputFormat,
                'Usage: sugarcrush session rename <target> <title…>',
            );
        }

        $store = Bootstrap::sessionStore();
        $row = self::resolveSessionTarget('rename', $args, $store);
        if (\is_int($row)) {
            return $row;
        }

        $store->renameSession($row->id, $title, TitleSource::User);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['renamed' => $row->id, 'name' => $title]]);
        } else {
            echo 'Renamed session ' . $row->id . ' to "' . $title . "\"\n";
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `session delete <target> [--with-children]`. Sub-agent children always
     * go with their parent; branch and background children are detached and
     * kept unless `--with-children` deletes every descendant
     * ({@see \SugarCraft\Crush\Session\SessionStore::deleteSession()}).
     */
    private static function sessionDelete(ParsedArgs $args): int
    {
        if (\count($args->subcommandArgs) > 2) {
            return self::rejectSessionOperand('delete', $args->subcommandArgs[2], $args);
        }

        $store = Bootstrap::sessionStore();
        $row = self::resolveSessionTarget('delete', $args, $store);
        if (\is_int($row)) {
            return $row;
        }

        $deleted = $store->deleteSession($row->id, isset($args->subcommandFlags['--with-children']));

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['deleted' => $row->id, 'deletedIds' => $deleted]]);
        } else {
            $others = \count($deleted) - 1;
            echo 'Deleted session ' . $row->id . ($others > 0 ? ' and ' . $others . ' child session(s)' : '') . "\n";
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `session pin|unpin|archive|unarchive <target>`. Repeating one is not an
     * error: the row already is what was asked for, which the message says.
     * Pinned sessions list first and are exempt from retention; archived ones
     * leave the default list, the tab strip and `--continue` but keep their
     * transcript.
     */
    private static function sessionFlag(ParsedArgs $args, string $verb): int
    {
        if (\count($args->subcommandArgs) > 2) {
            return self::rejectSessionOperand($verb, $args->subcommandArgs[2], $args);
        }

        $store = Bootstrap::sessionStore();
        $row = self::resolveSessionTarget($verb, $args, $store);
        if (\is_int($row)) {
            return $row;
        }

        $changed = match ($verb) {
            'pin' => !$row->pinned && $store->setPinned($row->id, true),
            'unpin' => $row->pinned && $store->setPinned($row->id, false),
            'archive' => $store->archive($row->id),
            'unarchive' => $store->unarchive($row->id),
        };
        $past = match ($verb) {
            'pin' => 'pinned',
            'unpin' => 'unpinned',
            'archive' => 'archived',
            'unarchive' => 'unarchived',
        };

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['session' => $row->id, 'action' => $verb, 'changed' => $changed]]);
        } else {
            echo ($changed ? \ucfirst($past) . ' session ' : 'Session ') . $row->id
                . ($changed ? '' : ' is already ' . $past) . "\n";
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * The one row `session <verb> <target>` names, or the exit code already
     * reported: 2 for a missing target or an ambiguous prefix (the candidates
     * are listed in the hint), 1 for a target nothing matches.
     */
    private static function resolveSessionTarget(
        string $verb,
        ParsedArgs $args,
        EnhancedSessionStore $store,
    ): SessionRow|int {
        $target = $args->subcommandArgs[1] ?? null;
        if ($target === null || $target === '') {
            return NonInteractive::failUsage(
                \sprintf('sugarcrush: session %s: no session id given', $verb),
                $args->outputFormat,
                'Usage: sugarcrush session ' . $verb . ' <id|prefix|name> — run `sugarcrush session list --all` for the ids.',
            );
        }

        $matches = SessionResolver::matches($store, $target);
        if (\count($matches) > 1) {
            $shown = \array_slice($matches, 0, 10);

            return NonInteractive::failUsage(
                \sprintf('sugarcrush: session %s %s: ambiguous id prefix, %d sessions match', $verb, $target, \count($matches)),
                $args->outputFormat,
                'Candidates: ' . \implode(', ', \array_map(
                    static fn(SessionRow $r): string => $r->id . ' (' . self::sessionLabel($r) . ')',
                    $shown,
                )) . (\count($matches) > \count($shown) ? ', …' : '') . '. Type more of the id.',
            );
        }

        if ($matches === []) {
            // EXIT 1, NOT 2, and the distinction is the documented one: the
            // store WAS opened and queried, so something was attempted. Exit 2
            // means nothing ran — which is what a MISSING target above means,
            // and is why the two branches of "the target went wrong" report
            // differently.
            \fwrite(\STDERR, \sprintf("sugarcrush: session %s: no such session\n", $target));
            if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
                self::emitDocument([
                    'result' => null,
                    'error' => ['type' => 'not-found', 'message' => 'no such session: ' . $target],
                ]);
            }

            return NonInteractive::EXIT_FAILURE;
        }

        return $matches[0];
    }

    private static function rejectSessionOperand(string $verb, string $operand, ParsedArgs $args): int
    {
        return NonInteractive::failUsage(
            \sprintf('sugarcrush: session %s %s: unexpected operand', $verb, $operand),
            $args->outputFormat,
            self::SESSION_USAGE,
        );
    }

    /** A row's name, or `(unnamed)` — raw storage, printed to a terminal the user owns. */
    private static function sessionLabel(SessionRow $row): string
    {
        return $row->name ?? '(unnamed)';
    }

    // ---------------------------------------------------------------------
    // mcp
    // ---------------------------------------------------------------------

    /**
     * `sugarcrush mcp list` — what `.mcp.json` declares, and whether this
     * launch would start it —, since E701, `sugarcrush mcp auth login`,
     * the interactive OAuth flow handed off whole to {@see self::mcpAuth()}
     * at the verb gate below, and, since E710, `sugarcrush mcp import`,
     * the print-only foreign-config translator handed to {@see self::mcpImport()}.
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
        if ($verb === 'auth') {
            return self::mcpAuth($args);
        }
        if ($verb === 'import') {
            return self::mcpImport($args);
        }
        if ($verb === 'trust') {
            return self::mcpTrust($args);
        }
        if ($verb !== 'list') {
            return NonInteractive::failUsage(
                $verb === null
                    ? 'sugarcrush: mcp: no action given'
                    : \sprintf('sugarcrush: mcp %s: unknown action', $verb),
                $args->outputFormat,
                'Usage: sugarcrush mcp list | sugarcrush mcp trust | sugarcrush mcp auth login <server> | sugarcrush mcp import claude|opencode <path>',
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

            // E678: the JSON arm answers what-is-live row-for-row, so it
            // carries the wire identity the text arm prints for rewritten
            // keys — on EVERY row, machine-readable. `wirePrefix` is computed
            // from each row's live name through the ONE sanitizer that
            // produces it, never from a hard-coded roster.
            $wireServers = [];
            foreach ($inventory['servers'] as $server) {
                $server['wirePrefix'] = \SugarCraft\Crush\Tools\McpToolBridge::wireServerPrefix($server['name']);
                $wireServers[] = $server;
            }
            $inventory['servers'] = $wireServers;

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

        // E665 (E42(b) surfacing): a server key holding bytes outside
        // [A-Za-z0-9-] is written differently ON THE WIRE — every dot, slash and
        // space hex-escaped by McpToolBridge::sanitize() — and a permission rule
        // matches the written form, not the typed one. Showing only the typed key
        // would send the user to write `mcp__github.com/foo__*`, which matches
        // nothing, with no hint of why the Ask never stops. Print the mapping for
        // each rewritten key ONLY: an identity-spelled server adds no line, so the
        // common `git`-style config stays as quiet as it was.
        foreach ($inventory['servers'] as $server) {
            $wirePrefix = \SugarCraft\Crush\Tools\McpToolBridge::wireServerPrefix($server['name']);
            if ($wirePrefix === \SugarCraft\Crush\Tools\McpToolBridge::NAME_PREFIX . $server['name'] . '__') {
                continue;
            }
            \printf(
                "  server \"%s\" is written %s on the wire (permission rules match: %s<tool>)\n",
                $server['name'],
                $wirePrefix,
                $wirePrefix,
            );
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `sugarcrush mcp trust` — approve this project's `.mcp.json` as it is now
     * (audit MCP-5): list the root under `trustedProjectMcp` if it is not
     * already, and record each server's fingerprint, so the next launch starts
     * exactly these entries and refuses any later change to them. Prints every
     * server with how it compares to the previous record; starts nothing.
     * {@see Bootstrap::trustProjectMcp()} does the work.
     */
    private static function mcpTrust(ParsedArgs $args): int
    {
        if (\count($args->subcommandArgs) > 1) {
            return NonInteractive::failUsage(
                \sprintf('sugarcrush mcp trust: unexpected operand %s', $args->subcommandArgs[1]),
                $args->outputFormat,
                'Usage: sugarcrush mcp trust (run it in the project, or pass the project directory first)',
            );
        }

        $report = Bootstrap::trustProjectMcp($args->root);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            if ($report['error'] !== null) {
                self::emitDocument([
                    'result' => null,
                    'error' => ['type' => 'mcp-config', 'message' => $report['path'] . ' ' . $report['error']],
                ]);

                return NonInteractive::EXIT_FAILURE;
            }
            self::emitDocument(['result' => $report]);

            return $report['recorded'] || !\in_array($report['status'], [Bootstrap::MCP_UNTRUSTED, Bootstrap::MCP_TRUSTED], true)
                ? NonInteractive::EXIT_OK
                : NonInteractive::EXIT_FAILURE;
        }

        switch ($report['status']) {
            case Bootstrap::MCP_ABSENT:
                echo 'No ' . Bootstrap::MCP_CONFIG_FILENAME . " in this project (looked for {$report['path']}); nothing to trust.\n";

                return NonInteractive::EXIT_OK;

            case Bootstrap::MCP_OUTSIDE_TREE:
                echo $report['path'] . " resolves outside the project tree and cannot be trusted.\n";

                return NonInteractive::EXIT_OK;
        }

        // ONE stderr site for the three ways an approval can fail to land: the
        // file could not be read, the grant could not be written, or the
        // record could not be. Each leaves the launch refusing what it refused.
        $failure = match (true) {
            $report['error'] !== null && !$report['granted'] => $report['path'] . ' ' . $report['error'],
            !$report['granted'] => 'mcp trust: could not add ' . $report['root'] . ' to "trustedProjectMcp" in '
                . Bootstrap::userConfigPath() . '; nothing was recorded',
            !$report['recorded'] => 'mcp trust: ' . (string) $report['error'],
            default => null,
        };

        if ($failure === null || $report['granted']) {
            echo 'Trusting the MCP servers in ' . $report['path'] . ":\n";
            $width = 0;
            foreach ([...array_column($report['servers'], 'name'), ...$report['removed'], ...$report['invalid']] as $name) {
                $width = \max($width, \strlen($name));
            }
            foreach ($report['servers'] as $server) {
                \printf("  %-{$width}s  %-9s  %s\n", $server['name'], $server['change'], $server['summary']);
            }
            foreach ($report['removed'] as $name) {
                \printf("  %-{$width}s  %-9s\n", $name, 'removed');
            }
            foreach ($report['invalid'] as $name) {
                \printf("  %-{$width}s  %-9s  (not recorded: the entry is malformed)\n", $name, 'invalid');
            }
        }

        if ($failure !== null) {
            \fwrite(\STDERR, 'sugarcrush: ' . $failure . "\n");

            return NonInteractive::EXIT_FAILURE;
        }

        echo \count($report['servers']) . ' server' . (\count($report['servers']) === 1 ? '' : 's')
            . ' recorded; the next launch starts these and refuses any later change to them.' . "\n";

        return NonInteractive::EXIT_OK;
    }

    /**
     * `sugarcrush mcp auth login <server> [token-url] [authorize-url] [registration-url] [-- --timeout N]`
     * — E701's interactive authorization-code + PKCE login.
     *
     * INTERACTIVE BY DESIGN, and the JSON door says so rather than half-
     * answering: a `--output-format json` consumer gets a machine-readable
     * usage refusal at exit 2, because a flow whose only progress channel is
     * "open this URL in a browser, come back when the tab says so" cannot be
     * streamed as a contract document.
     *
     * `--timeout` is read here, from the verb's own operands, and not by
     * `ArgvParser`: it bounds a human leg of THIS command, the same stance
     * `session`/`mcp` take toward every other operand, and the completion
     * census that every parser-branched flag must reach the OPTIONS table
     * stays honest — a flag the parser does not branch on belongs to no
     * OPTIONS row. The parser does not know the word, so it must arrive as
     * a post-`--` operand: `sugarcrush mcp auth login <server> -- --timeout
     * 60`. A bare `--timeout` dies earlier, at the parser's
     * unknown-options gate, which is the parser being honest about a flag
     * it does not implement rather than this verb silently eating it.
     */
    private static function mcpAuth(ParsedArgs $args): int
    {
        $action = $args->subcommandArgs[1] ?? null;
        if ($action !== 'login') {
            return NonInteractive::failUsage(
                $action === null
                    ? 'sugarcrush: mcp auth: no action given'
                    : \sprintf('sugarcrush: mcp auth %s: unknown action', $action),
                $args->outputFormat,
                'Usage: sugarcrush mcp auth login <server> [token-url] [authorize-url] [registration-url] [-- --timeout N]',
            );
        }

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(
                'sugarcrush: mcp auth login: login is interactive by design',
                $args->outputFormat,
                'Run it as a plain command: it prints a URL, waits for your browser, and stores the tokens.',
            );
        }

        $operands = [];
        $timeout = \SugarCraft\Crush\MCP\OAuthLoopbackFlow::DEFAULT_TIMEOUT_SECONDS;
        $count = \count($args->subcommandArgs);
        for ($i = 2; $i < $count; $i++) {
            $token = (string) $args->subcommandArgs[$i];
            if ($token !== '--timeout') {
                $operands[] = $token;
                continue;
            }
            $value = $args->subcommandArgs[$i + 1] ?? null;
            if ($value === null || !\is_numeric($value) || (float) $value <= 0.0) {
                return NonInteractive::failUsage(
                    'sugarcrush: mcp auth login: --timeout needs a positive number of seconds',
                    $args->outputFormat,
                    'Usage: sugarcrush mcp auth login <server> [token-url] [authorize-url] [registration-url] [-- --timeout N]',
                );
            }
            $timeout = (float) $value;
            $i++;
        }

        $serverUrl = $operands[0] ?? null;
        if ($serverUrl === null) {
            return NonInteractive::failUsage(
                'sugarcrush: mcp auth login: no server URL given',
                $args->outputFormat,
                'Usage: sugarcrush mcp auth login <server> [token-url] [authorize-url] [registration-url] [-- --timeout N]',
            );
        }

        // The flow owns every echo of the browser leg and returns the exit
        // code; the store is the same file `mcp auth add` writes, so a
        // login's entry attaches exactly like an add's does (E695 path).
        $flow = new \SugarCraft\Crush\MCP\OAuthLoopbackFlow(
            \SugarCraft\Crush\MCP\McpAuthStore::create()->oauth(),
        );

        return $flow->login(
            $serverUrl,
            $operands[1] ?? null,
            $operands[2] ?? null,
            $timeout,
            registrationUrl: $operands[3] ?? null,
        );
    }

    /**
     * The dialects `sugarcrush mcp import` can read — the keys of
     * {@see \SugarCraft\Crush\MCP\McpForeignTranslate::CONTAINERS}. Derived
     * from that map rather than re-typed here, so a third dialect added to
     * the translator is completable and dispatchable the moment it is
     * importable, and this door can never advertise a word nothing can answer.
     *
     * @return list<string>
     */
    private static function importSources(): array
    {
        return \array_keys(\SugarCraft\Crush\MCP\McpForeignTranslate::CONTAINERS);
    }

    /**
     * The one usage line this verb's four doors all print.
     */
    private const IMPORT_USAGE = 'Usage: sugarcrush mcp import claude|opencode <path>';

    /**
     * `sugarcrush mcp import claude|opencode <path>` — E710's print-only
     * foreign-config translator.
     *
     * THE NEVER-WRITE LAW. This verb reads one file and PRINTS the translated
     * `.mcp.json` block on stdout; it never touches the filesystem again. An
     * importer that wrote the destination would decide where the operator's
     * config lives — project root vs `--root` vs a scratch clone are three
     * different answers a human picks — and a wrong decision silently
     * swallowed is worse than a paste. stdout carries ONLY the document (and
     * the JSON envelope under `--output-format json`), so
     * `sugarcrush mcp import opencode ~/.config/opencode.json > .mcp.json`
     * is exact; every note about WHAT the translation did rides stderr, where
     * a redirect cannot corrupt it.
     *
     * The renames themselves are not spelled here: they are
     * {@see \SugarCraft\Crush\MCP\McpForeignTranslate}, the same table
     * `McpClient::startServer()` reads foreign spellings through since E708.
     * Importer and loader share one vocabulary by construction — a second
     * hand-typed mapping would be a second answer waiting to drift.
     *
     * Exit codes are the five-way convention this class documents: `2` for
     * the operand doors (no source, unknown source, no path, extra operand,
     * unreadable file — nothing was attempted), `1` for a file that was read
     * and then refused (not JSON, not a config of the named dialect, a
     * malformed entry — it ran and failed, the same reading as `mcp list` on
     * a trusted-but-unparseable `.mcp.json`), `0` for the printed document
     * even when it came with notes.
     */
    private static function mcpImport(ParsedArgs $args): int
    {
        $source = $args->subcommandArgs[1] ?? null;
        if ($source === null) {
            return NonInteractive::failUsage(
                'sugarcrush: mcp import: no source given',
                $args->outputFormat,
                self::IMPORT_USAGE,
            );
        }
        if (!\in_array($source, self::importSources(), true)) {
            return NonInteractive::failUsage(
                \sprintf('sugarcrush: mcp import %s: unknown source', $source),
                $args->outputFormat,
                \sprintf('Valid sources are: %s.', \implode(', ', self::importSources())),
            );
        }
        $path = $args->subcommandArgs[2] ?? null;
        if ($path === null) {
            return NonInteractive::failUsage(
                'sugarcrush: mcp import: no file given',
                $args->outputFormat,
                self::IMPORT_USAGE,
            );
        }
        $extra = $args->subcommandArgs[3] ?? null;
        if ($extra !== null) {
            return NonInteractive::failUsage(
                \sprintf('sugarcrush: mcp import: unexpected operand %s', $extra),
                $args->outputFormat,
                self::IMPORT_USAGE,
            );
        }

        // One read, and the operator's own argv named the path — the same
        // CALLER_SUPPLIED posture as every other CLI file operand (`--config`
        // above, `session delete`'s store). The bytes are a document to
        // translate, never code to execute: nothing here reaches `buildServer`
        // or spawns anything; the servers only START after the operator pastes
        // the block into `.mcp.json` and trusts the root.
        $raw = @file_get_contents($path);  // BARE spelling — the \-prefixed form is invisible to the read-path census scanner
        if ($raw === false) {
            return NonInteractive::failUsage(
                \sprintf('sugarcrush: mcp import: cannot read %s', $path),
                $args->outputFormat,
                'Check the path; nothing has been translated or written.',
            );
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                throw new \RuntimeException('the file is not a JSON object');
            }
            $translated = \SugarCraft\Crush\MCP\McpForeignTranslate::translateDocument($source, $decoded);
        } catch (\JsonException $e) {
            return self::mcpImportFailure($path, 'is not valid JSON: ' . $e->getMessage(), $args);
        } catch (\RuntimeException $e) {
            return self::mcpImportFailure($path, $e->getMessage(), $args);
        }

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument([
                'result' => [
                    'source' => $source,
                    'path' => $path,
                    'mcpServers' => $translated['servers'],
                    'notes' => $translated['notes'],
                ],
            ]);
        } else {
            echo \json_encode(
                ['mcpServers' => $translated['servers']],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ) . "\n";
        }

        // Notes after the document, all of them on stderr: the translation
        // earned its exit 0 by printing, and the account of what it renamed
        // must not contaminate the bytes a pipe or redirect carries.
        foreach ($translated['notes'] as $note) {
            self::mcpImportLine($note);
        }
        self::mcpImportLine(\sprintf(
            'nothing was written — paste the block into %s and add the root to "trustedProjectMcp" in %s to start these servers',
            // Same ambient default mcpServerInventory() takes for a null
            // `--root`: the launch directory names where the block belongs.
            \rtrim($args->root ?? getcwd(), '/') . '/' . Bootstrap::MCP_CONFIG_FILENAME,
            Bootstrap::userConfigPath(),
        ));

        return NonInteractive::EXIT_OK;
    }

    /**
     * One prefixed sentence on stderr — the shared channel for both this
     * verb's notes and its post-read failures. Single site by design: the
     * DIRECT_SITES census credits this file one writer for `mcp import`, and
     * a second spelling of the same funnel is how a roster loses track.
     */
    private static function mcpImportLine(string $line): void
    {
        \fwrite(\STDERR, 'sugarcrush: mcp import: ' . $line . "\n");
    }

    /**
     * The file WAS read and then refused: exit 1, the failure named on stderr
     * (text) or in the package's one error envelope (JSON), and — the
     * acceptance half — NO partial document on stdout in either format. The
     * `mcp-config` error type is the family `mcp list` already uses for an
     * unparseable trusted config; a second type word here would fork the
     * README's exit-table roster for no new fact.
     */
    private static function mcpImportFailure(string $path, string $message, ParsedArgs $args): int
    {
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            // Error-only envelope, the same shape failUsage() prints: a
            // `"result": null` twin would let a reader mistake a refusal for
            // a successful import of nothing.
            self::emitDocument([
                'error' => [
                    'type' => 'mcp-config',
                    'message' => $path . ': ' . $message,
                ],
            ]);
        } else {
            self::mcpImportLine($path . ': ' . $message);
        }

        return NonInteractive::EXIT_FAILURE;
    }

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
        '--continue' => ['short' => '-c', 'value' => null, 'desc' => 'Continue the most recent session'],
        '--resume' => ['short' => null, 'value' => 'text', 'desc' => 'Resume a stored session by id or name'],
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
        'attach' => 'Run the TUI on a session of a running server',
        'completion' => 'Emit a shell completion script',
        'doctor' => 'Report on this installation and exit',
        'mcp' => 'Inspect the project MCP configuration',
        'models' => 'List the providers this install can select',
        'serve' => 'Run the WebSocket server for the web UI',
        'session' => 'Manage stored sessions',
    ];

    /**
     * Each subcommand's own operands, for the nested completion the three
     * scripts all offer after the verb.
     *
     * @var array<string, list<string>>
     */
    private const SUBCOMMAND_ACTIONS = [
        'session' => ['list', 'show', 'rename', 'delete', 'pin', 'unpin', 'archive', 'unarchive'],
        'mcp' => ['list', 'auth', 'import', 'trust'],
        'serve' => Serve::ACTIONS,
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

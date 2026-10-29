<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Bundles\BundleDiscovery;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Bundles\BundleRecommender;
use SugarCraft\Code\Bundles\BundleSource;
use SugarCraft\Code\Bundles\Marketplace;
use SugarCraft\Code\Bundles\MarketplaceEntry;
use SugarCraft\Code\Bundles\MarketplaceRegistry;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\Keychain;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Session\SessionQuery;
use SugarCraft\Code\Session\SessionResolver;
use SugarCraft\Code\Session\SessionRow;
use SugarCraft\Code\Session\TitleSource;
use SugarCraft\Code\Util\Exporter;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Core\Util\Tty;

/**
 * The real CLI subcommands — `mcp list`, `session list|show|rename|delete|
 * pin|unpin|archive|unarchive`, `models`, `doctor`, `bundles list|install`,
 * `serve`, `self-update` and `completion bash|zsh|fish` (crush_code.md Phase 4 item 6; the
 * session verbs past
 * `list`/`delete` are Appendix P §3.4; `serve` is Appendix O §4.7, its body in
 * {@see Serve} — the one verb that keeps running rather than answering, and
 * still constructs no `Program`; `attach` is Appendix O §4.9, its body in
 * {@see Attach} — the one verb that DOES run the TUI, over a running server's
 * session, and only once it has connected; `acp` is roadmap 5.9, its body in
 * {@see Acp} — the one verb whose stdin and stdout belong to another
 * program, an editor speaking the Agent Client Protocol, and which runs turns
 * without a screen); `self-update` is DST-3, its body in {@see SelfUpdate} —
 * the one verb that rewrites the file it was launched from (a phar-only
 * swap, sidecar-verified before the rename, with a one-shot `--rollback`).
 *
 * EVERY ONE ANSWERS WITHOUT A SESSION. `bin/candy-code` dispatches these in the
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
 * different thing entirely: {@see \SugarCraft\Code\Tools\BuiltIn\Doctor} is
 * registered in {@see Bootstrap::tools()}, advertised to the LLM in the
 * tool-calling schema, and reports ONE fact — the terminal's detected
 * sugar-image image protocol — with a 16x16 PNG capability swatch attached.
 * It is called by a model mid-conversation. This one is typed by a human at a
 * shell, reports the install (PHP, extensions, config file, permission policy,
 * provider selection, session database, MCP config, sandbox jail), takes no
 * model, and cannot be reached by a tool call. Neither calls the other; the
 * only thing they share is the English word.
 *
 * EXIT CODES are the convention `bin/candy-code` and {@see NonInteractive}
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
     * {@see \SugarCraft\Code\Session\SessionStore::listSessions()}'s own
     * default so the CLI shows what the store considers a page.
     */
    private const SESSION_LIST_LIMIT = 20;

    /**
     * Run the subcommand $args carries and return the process exit code.
     *
     * Called by `bin/candy-code` only when `$args->subcommand !== null`, after
     * every global pre-flight check (unknown flags, `--root`, `--config`) has
     * passed and after `Bootstrap::useConfigPath()` has registered the
     * override — so `candy-code --config /tmp/x.json doctor` reports the policy
     * in `/tmp/x.json`, which is the whole point of asking.
     */
    public static function dispatch(ParsedArgs $args): int
    {
        return match ($args->subcommand) {
            'doctor'     => self::doctor($args),
            'models'     => self::models($args),
            'self-update' => SelfUpdate::run($args),
            'login'      => self::login($args),
            'session'    => self::session($args),
            'serve'      => Serve::run($args),
            'attach'     => Attach::run($args),
            'acp'        => Acp::run($args),
            'bundles'    => self::bundles($args),
            'mcp'        => self::mcp($args),
            'completion' => self::completion($args),
            // Unreachable: ArgvParser only ever stores a ParsedArgs::SUBCOMMANDS
            // member. Answered rather than asserted because the two lists
            // drifting apart must not be a PHP fatal on a user's terminal.
            default => NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.sub.unknown_subcommand', ['verb' => (string) $args->subcommand]),
                $args->outputFormat,
                Lang::t('cli.sub.valid_subcommands', ['verbs' => \implode(', ', ParsedArgs::SUBCOMMANDS)]),
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
        // The envelope version rides HERE rather than at the fifteen call
        // sites (rv-tui-5): every subcommand document passes through this one
        // method, so one prepend keeps the whole family — `models`, `doctor`,
        // `session …`, `mcp …` — byte-for-byte in contract with the one-shot
        // path, and a call site can no more forget the key than it can forget
        // the encode flags. `+` preserves the literal's key order after the
        // leading `schema`; a call site that ever spelled its own `schema`
        // wins, which is the same offset-union precedence the array-plus
        // idiom carries everywhere else in this file.
        $document = ['schema' => NonInteractive::SCHEMA] + $document;

        try {
            echo NonInteractive::encodeDocument($document) . "\n";
        } catch (\JsonException) {
            echo '{"schema":' . NonInteractive::SCHEMA . ',"result":null,"error":{"type":"encoding","message":"the answer could not be encoded as JSON"}}' . "\n";
        }
    }

    /**
     * A verb that takes NO operands got one.
     *
     * `session`, `mcp` and `completion` all reject an unknown operand at exit
     * 2; `doctor` and `models` silently discarded theirs, so `candy-code
     * models delete everything` printed the provider table and exited 0. A
     * word the CLI ignores is a word the user believes did something.
     */
    private static function rejectOperands(string $verb, ParsedArgs $args): int
    {
        return NonInteractive::failUsage(
            'candy-code: ' . Lang::t('cli.sub.unexpected_operand', ['verb' => $verb, 'operand' => (string) $args->subcommandArgs[0]]),
            $args->outputFormat,
            Lang::t('cli.sub.takes_no_arguments', ['verb' => $verb]),
        );
    }

    // ---------------------------------------------------------------------
    // doctor
    // ---------------------------------------------------------------------

    /**
     * `candy-code doctor` — one line per install check, then a verdict.
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
            // an unreadable ~/.candy-code/config.json makes permissionGate()
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

        echo "\n" . match (\count($failed)) {
            0 => Lang::t('cli.doctor.no_problems'),
            1 => Lang::t('cli.doctor.failed.one', ['count' => 1]),
            default => Lang::t('cli.doctor.failed.many', ['count' => \count($failed)]),
        } . "\n";

        return $failed === [] ? NonInteractive::EXIT_OK : NonInteractive::EXIT_FAILURE;
    }

    /**
     * The DSN the `pdo_sqlite` probe opens, whose SCHEME is the one
     * {@see \SugarCraft\Code\Session\SessionStore}'s constructor builds
     * (`new PDO("sqlite:$dbPath")`). `:memory:` rather than a path so the
     * probe touches no file and creates no database.
     *
     * The linkage is asserted, not asserted-about: a test compares this
     * scheme against the DSN literal in SessionStore's own source, because a
     * probe of some OTHER driver would report a green install on a box where
     * every session write fatals.
     *
     * ISS-083 corrects the old tail clause ("`ext-sqlite3` is declared and
     * called by nothing in src/"): it was never true — {@see
     * \SugarCraft\Code\Agents\TaskList} opens `new \SQLite3` for the Team
     * task list, and the Team tool ships on every launch. Both halves are
     * therefore exercised here, and an absent `ext-sqlite3` FAILS the probe
     * the same way the mbstring/intl probes fail: the manifest declares it.
     */
    private const SESSION_STORE_PROBE_DSN = 'sqlite::memory:';

    /**
     * Can PDO actually open this DSN, and can `ext-sqlite3` open a database?
     *
     * EXERCISED, not name-checked. `extension_loaded('pdo_sqlite')` was the
     * earlier spelling and it encodes an extension NAME — swap the name for a
     * neighbouring one and the check goes on passing while the capability is
     * gone. Opening the driver is the same act SessionStore performs, so there
     * is no name left to get wrong, and the failing branch is reachable in a
     * test by handing it a DSN no driver claims.
     *
     * The sqlite3 half (ISS-083) is the act {@see
     * \SugarCraft\Code\Agents\TaskList} performs: constructing the class and
     * opening `:memory:` — a name check would call an install broken-or-fine
     * from a string rather than from the capability. `$sqlite3Available` is
     * the test seam for the branch no host here can reach by unloading a
     * declared extension; production callers omit it.
     *
     * @return array{status: string, detail: string}
     */
    private static function pdoDriverProbe(string $dsn, ?bool $sqlite3Available = null): array
    {
        $driver = \strtok($dsn, ':');
        $driver = $driver === false ? $dsn : $driver;

        try {
            new \PDO($dsn);
            $state = Lang::t('cli.doctor.pdo_usable');
            $usable = true;
        } catch (\Throwable $e) {
            $state = Lang::t('cli.doctor.pdo_unusable', ['error' => $e->getMessage()]);
            $usable = false;
        }

        $sqlite3Ok = $sqlite3Available ?? self::sqlite3Exercise();

        return [
            // ISS-083: `ext-sqlite3` is declared in composer.json and opened
            // directly by the Team task list, so its absence FAILS — the same
            // doctrine as the declared mbstring/intl probes below. WARN would
            // tell the user "usable" and the first Team call would fatal.
            'status' => $usable && $sqlite3Ok ? 'OK' : 'FAIL',
            'detail' => Lang::t('cli.doctor.pdo', [
                'driver' => $driver,
                'state' => $state,
                'sqlite3' => $sqlite3Ok ? Lang::t('cli.doctor.loaded') : Lang::t('cli.doctor.absent'),
            ]),
        ];
    }

    /**
     * Perform the construction the Team task list performs ({@see
     * \SugarCraft\Code\Agents\TaskList}, `new \SQLite3($dbPath)`), against
     * `:memory:` so no file is touched. Returns false when the class is
     * absent or refuses the open — the capability, not its name.
     */
    private static function sqlite3Exercise(): bool
    {
        try {
            return (new \SQLite3(':memory:'))->querySingle('SELECT 1;') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The SES-2 capability question: can this SQLite actually build an FTS5
     * table? EXERCISED, not name-checked, in the ISS-083 doctrine — FTS5 is
     * a compile option of SQLite rather than a PHP extension, so the only
     * honest probe is the exact `CREATE VIRTUAL TABLE ... USING fts5` the
     * session index issues, run against `sqlite::memory:` (and read back
     * with a real MATCH, so a table that builds but cannot search still
     * reports what it is).
     *
     * WARN, never FAIL: session body search keeps answering through the
     * LIKE prefilter while the module is absent
     * ({@see \SugarCraft\Code\Session\SessionSearchIndex}), so a refusal is
     * a degraded feature, not a broken install.
     *
     * @param string $module test seam — the FTS5 module name to attempt
     *
     * @return array{status: string, detail: string}
     */
    private static function fts5Probe(string $module = 'fts5'): array
    {
        $error = '';
        try {
            $pdo = \SugarCraft\Code\Support\SqliteConnect::openDsn(self::SESSION_STORE_PROBE_DSN);
            $pdo->exec('CREATE VIRTUAL TABLE candy_code_fts5_probe USING ' . $module . '(body, tokenize = \'porter unicode61\')');
            $pdo->exec('INSERT INTO candy_code_fts5_probe(body) VALUES (\'fts5 probe sentence\')');
            $answered = (int) $pdo->query('SELECT COUNT(*) FROM candy_code_fts5_probe WHERE body MATCH \'fts5\'')->fetchColumn() === 1;
        } catch (\Throwable $e) {
            $answered = false;
            $error = $e->getMessage();
        }

        return [
            'status' => $answered ? 'OK' : 'WARN',
            'detail' => Lang::t($answered ? 'cli.doctor.fts5_ok' : 'cli.doctor.fts5_missing', ['error' => $error]),
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
            'candy-code' => static fn (): array => [
                'status' => 'OK',
                'detail' => Help::versionString(),
            ],

            'php' => static fn (): array => [
                // ^8.3 is this package's own composer `require`. Checked at
                // RUNTIME anyway because a `php8.2 bin/candy-code` on a box
                // where Composer installed under 8.3 satisfies the manifest and
                // still cannot run the code.
                'status' => \PHP_VERSION_ID >= 80300 ? 'OK' : 'FAIL',
                'detail' => Lang::t(\PHP_VERSION_ID >= 80300 ? 'cli.doctor.php' : 'cli.doctor.php_too_old', [
                    'version' => \PHP_VERSION,
                    'binary' => \PHP_BINARY !== '' ? \PHP_BINARY : Lang::t('cli.doctor.unknown'),
                ]),
            ],

            'pdo_sqlite' => static fn (): array => self::pdoDriverProbe(self::SESSION_STORE_PROBE_DSN),

            'fts5' => static fn (): array => self::fts5Probe(),

            'ext-curl' => static fn (): array => [
                // `suggest`, not `require`: only the HTTP providers need it, so
                // a missing curl is a warning on an install that may be running
                // the echo or shell-out backend entirely legitimately.
                'status' => \extension_loaded('curl') ? 'OK' : 'WARN',
                'detail' => \extension_loaded('curl')
                    ? Lang::t('cli.doctor.loaded')
                    : Lang::t('cli.doctor.curl_missing'),
            ],

            'ext-mbstring' => static function (): array {
                // `require`, and EXERCISED, not name-checked (rv-enduser-6):
                // `Message`, `Renderer` and the palette all count and clip
                // text through mb_* on the painted path, so the probe performs
                // a real multibyte call and demands its answer. A box without
                // the extension fails the same call at first paint — FAIL,
                // never WARN, because the manifest already declares it.
                try {
                    $exercised = \mb_substr_count('candy-code', 'c') === 2;
                    $state = Lang::t('cli.doctor.loaded');
                } catch (\Throwable) {
                    $exercised = false;
                    $state = Lang::t('appLaunch.doctor.mbstring_missing');
                }

                return [
                    'status' => $exercised ? 'OK' : 'FAIL',
                    'detail' => $state,
                ];
            },

            'ext-intl' => static function (): array {
                // Same shape as mbstring (rv-devops-6): the renderer walks
                // every frame through `grapheme_extract()` (see
                // `Renderer::graphemes()`) and the Edit matcher NFKC-normalises
                // through `Normalizer`, so the probe calls into the extension
                // rather than naming it.
                try {
                    $exercised = \IntlChar::ord('B') === 66;
                    $state = Lang::t('cli.doctor.loaded');
                } catch (\Throwable) {
                    $exercised = false;
                    $state = Lang::t('appLaunch.doctor.intl_missing');
                }

                return [
                    'status' => $exercised ? 'OK' : 'FAIL',
                    'detail' => $state,
                ];
            },

            'server mode' => static function (): array {
                // WARN, never FAIL: an install without these runs the TUI and
                // -p perfectly well; only `serve` refuses to start.
                $missing = \SugarCraft\Code\Server\Preflight::detect()->environmentProblems();

                return [
                    'status' => $missing === [] ? 'OK' : 'WARN',
                    'detail' => $missing === []
                        ? Lang::t('cli.doctor.server_ready')
                        : Lang::t('cli.doctor.server_refuses', ['problems' => \implode('; ', $missing)]),
                ];
            },

            'config file' => static function (): array {
                $path = Bootstrap::userConfigPath();
                if (!\is_file($path)) {
                    // ABSENT IS NOT BROKEN. A first run has no config file and
                    // every default applies; reporting FAIL here would tell a
                    // healthy install it is sick.
                    return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.config_absent', ['path' => $path])];
                }
                if (!\is_readable($path)) {
                    return ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.config_unreadable', ['path' => $path])];
                }
                $raw = (string) \file_get_contents($path);
                try {
                    \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    return ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.config_invalid', ['path' => $path, 'error' => $e->getMessage()])];
                }

                return ['status' => 'OK', 'detail' => $path];
            },

            'settings keys' => static function () use ($args): array {
                // ISS-029: the tolerant reader dropped a misspelled key
                // without a word, so a config full of `permisionMode` used to
                // read OK here. The audit names every top-level key no reader
                // consumes from its file, guesses near-miss spellings, and
                // flags known keys whose value falls back to its default.
                $rows = LayeredSettings::keyAuditRows(\dirname(Bootstrap::userConfigPath()), $args->root);

                return $rows === []
                    ? ['status' => 'OK', 'detail' => Lang::t('cli.doctor.settings_clean')]
                    : ['status' => 'WARN', 'detail' => implode('; ', $rows)];
            },

            'spend cap' => static function (): array {
                // ISS-029: doctor used to never call the cap resolver. A
                // `CANDY_CODE_MAX_COST=lots` throws right here — `-p` refuses
                // the launch with exit 2 over it — while the old report said
                // not one word about spend caps.
                try {
                    $cap = Bootstrap::maxCostUsd();
                } catch (PermissionConfigException $e) {
                    return ['status' => 'FAIL', 'detail' => $e->getMessage()];
                }

                return [
                    'status' => 'OK',
                    'detail' => $cap === null
                        ? Lang::t('cli.doctor.cap_none')
                        : Lang::t('cli.doctor.cap', ['amount' => sprintf('%.2f', $cap)]),
                ];
            },

            'permission policy' => static function (): array {
                // The one probe whose FAILURE MODE IS THE POINT: a config with
                // an unusable permissionMode makes bin/candy-code refuse to
                // launch at all (PermissionConfigException -> exit 2), and
                // before this command existed the only way to see why was to
                // read that message off a failed launch. Caught HERE rather
                // than by doctor()'s generic wrapper so the line names the
                // policy instead of a class name.
                try {
                    // announceBypassMode: false — this probe READS the policy to
                    // diagnose it; the bypass transcript row describes a session
                    // about to run, and doctor starts none (audit v3:U-F3).
                    $gate = Bootstrap::permissionGate(announceBypassMode: false);
                    $tui = Bootstrap::permissionGate(interactive: true, announceBypassMode: false);
                } catch (PermissionConfigException $e) {
                    return ['status' => 'FAIL', 'detail' => $e->getMessage()];
                }

                // With nothing configured the two paths start in different
                // modes (decision D5), and a one-word answer would describe
                // only one of them.
                return ['status' => 'OK', 'detail' => $tui->mode() === $gate->mode()
                    ? Lang::t('cli.doctor.mode', ['mode' => $gate->mode()->value])
                    : Lang::t('cli.doctor.mode_split', ['tui' => $tui->mode()->value, 'headless' => $gate->mode()->value])];
            },

            'sandbox' => static function (): array {
                return self::sandboxProbe();
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
                            ? Lang::t('cli.doctor.provider_none')
                            : Lang::t('cli.doctor.provider_shell'),
                    ];
                }

                // ISS-028: CONSTRUCTED, deliberately — but only via the
                // factory. Bootstrap::backendFor() builds the tool array,
                // which reaches mcpClient(), which proc_open()s every
                // configured MCP server, so a health check must not call it.
                // ProviderFactory::create() does no I/O (see
                // NonInteractive::EXIT_CONFIG: every constructor only builds
                // a client object or stores strings), so a throw here is
                // always a configuration error — the missing apiKey, the
                // absent projectId — exactly what `candy-code -p` will die
                // on with exit 2 while the old name-only probe said OK.
                $known = \array_key_exists($name, Bootstrap::availableProviders());
                if (!$known) {
                    return [
                        'status' => 'FAIL',
                        'detail' => Lang::t('cli.doctor.provider_unknown', ['provider' => $name]),
                    ];
                }

                try {
                    $factory = new ProviderFactory();
                    $config = Bootstrap::providerConfigFor($name, $factory);
                    $config['model'] = $model;
                    $factory->create($config);
                } catch (\Throwable $e) {
                    return [
                        'status' => 'FAIL',
                        'detail' => Lang::t('cli.doctor.provider_unconstructable', [
                            'provider' => $name,
                            'error' => $e->getMessage(),
                        ]),
                    ];
                }

                return [
                    'status' => 'OK',
                    'detail' => Lang::t('cli.doctor.provider', ['provider' => $name, 'model' => $model]),
                ];
            },

            'session store' => static function (): array {
                // prune: false — A DIAGNOSTIC MUST NOT DELETE CONVERSATIONS.
                // Bootstrap::sessionStore() applies the opt-in
                // CANDY_CODE_SESSION_RETENTION_DAYS sweep on construction, so
                // the plain accessor made `candy-code doctor` destroy rows:
                // MEASURED with two rows aged to 2020 and a 7-day window, a
                // doctor run printed "retention removed 1 unnamed session" and
                // the table went 2 -> 1. Retention belongs to a LAUNCH, which
                // is the one moment no session is open; a health check is
                // read-only by contract.
                $store = Bootstrap::sessionStore(prune: false);
                $count = \count($store->listSessions(1000));

                return ['status' => 'OK', 'detail' => Lang::t('cli.doctor.sessions', ['count' => $count])];
            },

            'mcp config' => static function () use ($args): array {
                $inventory = Bootstrap::mcpServerInventory($args->root);

                return match (true) {
                    $inventory['status'] === Bootstrap::MCP_DISABLED
                        => ['status' => 'OK', 'detail' => Lang::t('cli.doctor.mcp_disabled')],
                    $inventory['status'] === Bootstrap::MCP_ABSENT
                        => ['status' => 'OK', 'detail' => Lang::t('cli.doctor.mcp_absent', ['file' => Bootstrap::MCP_CONFIG_FILENAME])],
                    $inventory['status'] === Bootstrap::MCP_OUTSIDE_TREE
                        => ['status' => 'FAIL', 'detail' => Lang::t('cli.doctor.mcp_outside', ['path' => $inventory['path']])],
                    $inventory['status'] === Bootstrap::MCP_UNTRUSTED
                        => ['status' => 'WARN', 'detail' => Lang::t('cli.doctor.mcp_untrusted', ['path' => $inventory['path']])],
                    $inventory['error'] !== null
                        => ['status' => 'FAIL', 'detail' => $inventory['path'] . ' ' . $inventory['error']],
                    default
                        => ['status' => 'OK', 'detail' => Lang::t('cli.doctor.mcp_servers', [
                            'count' => \count($inventory['servers']),
                            'path' => $inventory['path'],
                        ])],
                };
            },
        ];
    }

    /**
     * The `sandbox` doctor row (C-TOOL-4.a): the three facts someone typing
     * "sandboxing is not working" needs, each answered through the SAME code
     * the Bash tool uses, never a second opinion —
     *   1. what `bashSandbox` is configured to;
     *   2. where `bwrap` resolves;
     *   3. whether the jail actually STARTS — exercised through
     *      {@see Bash::sandboxRefusal()}, the probe the tool itself runs
     *      before its first command. Presence is not capability: a host with
     *      /usr/bin/bwrap and AppArmor's `apparmor_restrict_unprivileged_userns`
     *      set carries a jail that never runs, and `doctor` used to say
     *      nothing about it while every `Bash` call died refusing.
     *
     * The row NEVER FAILs. A configured-but-dead jail is a WARN with a remedy:
     * `bashSandbox: off` is a supported configuration, so the install is not
     * broken — only the jail is — and FAIL belongs to the checks that say the
     * program itself cannot run (doctor()'s exit convention).
     *
     * $jail, $sysctlPath and $osFamily are test seams: injecting them makes
     * every verdict arm reachable on ANY host — Linux or not, bwrap or not —
     * without the live row (all three defaulted) ever lying about the real
     * one. The live row still spawns exactly what Bash would spawn, once per
     * process, which is the whole point of asking through Bash.
     *
     * @param ?\Closure(): ?string $jail
     */
    private static function sandboxProbe(?\Closure $jail = null, ?string $sysctlPath = null, string $osFamily = \PHP_OS_FAMILY): array
    {
        $configured = Bubblewrap::fromSetting(Bootstrap::readUserConfig()['bashSandbox'] ?? null);
        $mode = $configured?->mode() ?? Bubblewrap::MODE_OFF;

        if ($osFamily !== 'Linux') {
            // The jail cannot exist here. OK when nothing was configured —
            // off is honest on every OS — WARN when something was, because
            // that setting will never take effect on this host.
            return [
                'status' => $configured === null ? 'OK' : 'WARN',
                'detail' => Lang::t($configured === null
                    ? 'cli.doctor.sandbox_nonlinux_off'
                    : 'cli.doctor.sandbox_nonlinux', [
                    'mode' => $mode,
                    'os' => $osFamily,
                ]),
            ];
        }

        // The jail is exercised even while `off`: "off, and a working bwrap is
        // sitting right there" is a different sentence from "off, and it would
        // not start anyway", and the first one tells the user the fix is a
        // setting rather than an install.
        $sandbox = $configured ?? Bubblewrap::new();
        $binary = $sandbox->resolvedBinary();
        $refusal = ($jail ?? static fn (): ?string => (new Bash(sandbox: $sandbox))->sandboxRefusal())();

        if ($configured === null) {
            return [
                'status' => 'OK',
                'detail' => Lang::t($refusal === null
                    ? 'cli.doctor.sandbox_off'
                    : 'cli.doctor.sandbox_off_blocked', [
                    'bwrap' => $binary === ''
                        ? Lang::t('cli.doctor.sandbox_binary_absent')
                        : Lang::t('cli.doctor.sandbox_binary', ['path' => $binary]),
                    'reason' => (string) $refusal,
                ]),
            ];
        }

        if ($refusal === null) {
            // Reaching this arm with a jail that starts means the binary
            // resolved — Bubblewrap::probe() answers 'not found on PATH'
            // first — so the path form is the only one printed here.
            return [
                'status' => 'OK',
                'detail' => Lang::t('cli.doctor.sandbox_ready', [
                    'mode' => $mode,
                    'bwrap' => Lang::t('cli.doctor.sandbox_binary', ['path' => $binary]),
                ]),
            ];
        }

        // The remedy arms split on the one host fact that turns "install
        // bubblewrap" into bad advice: when the kernel restricts unprivileged
        // user namespaces, bwrap is probably already installed and a fresh
        // install changes nothing — the fix is the AppArmor profile, with the
        // sysctl as the blunt fallback. An unreadable sysctl (null) is not
        // "permissive": absence answers unknown, and the generic remedy stays
        // honest for the missing-binary case it also covers.
        $appArmor = Bubblewrap::appArmorRestrictsUserns($sysctlPath ?? Bubblewrap::APPARMOR_RESTRICT_USERNS_PATH);

        return [
            'status' => 'WARN',
            'detail' => Lang::t('cli.doctor.sandbox_refused', [
                'mode' => $mode,
                'reason' => $refusal,
                'remedy' => Lang::t($appArmor === true
                    ? 'cli.doctor.sandbox_remedy_apparmor'
                    : 'cli.doctor.sandbox_remedy_generic'),
            ]),
        ];
    }

    // ---------------------------------------------------------------------
    // models
    // ---------------------------------------------------------------------

    /**
     * `candy-code models` — every provider this install can select, with the
     * model each one defaults to, and a marker on the selected one. The one
     * operand `all` expands each row with the merged model catalog (PRV-1).
     *
     * Reads {@see Bootstrap::availableProviders()}, which is the SAME
     * enumeration `Bootstrap::backendFor()` resolves a name against and the
     * same one the Ctrl+P "Switch model" palette offers. Re-deriving the list
     * from `ProviderFactory` here would have been a second discovery path that
     * silently omits the `config.dev.json` overlay the first one applies.
     */
    private static function models(ParsedArgs $args): int
    {
        // PRV-1 (§6.5): `models all` appends the catalog tiers per provider.
        // The plan words this as `models --all`; the flag FORM is deliberately
        // not added — `models` owning `--all` would widen the verb's flag
        // roster (ParsedArgs::SUBCOMMAND_FLAGS) whose stability is pinned
        // test-first (SessionSubcommandsTest: `models --all` answers
        // unknownFlags `['--all']`), and that headless roster is frozen for
        // this wave. The catalog therefore rides the verb's OWN operand:
        // exactly `all` selects the expanded listing, anything else is still
        // the rejectOperands error, so `models delete everything` stays a
        // usage error and not a silent table print.
        $all = $args->subcommandArgs === ['all'];
        if (!$all && $args->subcommandArgs !== []) {
            return self::rejectOperands('models', $args);
        }

        $selected = Bootstrap::selectedProviderName();
        $providers = Bootstrap::availableProviders();
        \ksort($providers);

        $metadata = $all ? \SugarCraft\Code\Providers\ModelMetadata::new() : null;

        $rows = [];
        foreach ($providers as $name => $config) {
            $model = $config['model'] ?? null;
            $model = \is_string($model) && $model !== '' ? $model : 'unknown';
            $row = [
                'provider' => $name,
                'model' => $model,
                'selected' => $name === $selected,
            ];

            if ($all) {
                $row['catalog'] = self::modelCatalogRows(
                    $name,
                    \is_array($config) ? $config : [],
                    $model,
                    $metadata,
                );
            }

            $rows[] = $row;
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
            echo Lang::t('cli.models.none_configured') . "\n";

            return NonInteractive::EXIT_OK;
        }

        $width = 0;
        foreach ($rows as $row) {
            $width = \max($width, \strlen($row['provider']));
        }
        foreach ($rows as $row) {
            \printf("%s %-{$width}s  %s\n", $row['selected'] ? '*' : ' ', $row['provider'], $row['model']);
            // One indented wrapped line of catalog ids under the row; the
            // configured model leads it, exactly like the palette merge.
            foreach (isset($row['catalog']) && $row['catalog'] !== []
                ? \explode("\n", \wordwrap(implode(', ', $row['catalog']), 96, "\n", true))
                : [] as $line) {
                echo '  ' . \str_repeat(' ', $width) . '  ' . $line . "\n";
            }
        }
        echo "\n" . ($selected === null
            ? Lang::t('cli.models.none_selected')
            : Lang::t('cli.models.selected_legend', ['path' => Bootstrap::userConfigPath()])) . "\n";

        return NonInteractive::EXIT_OK;
    }

    /**
     * PRV-1: the merged catalog rows `models all` offers for one provider.
     * The live tier rides only for the OpenAI-compatible transports — the
     * same three that implement {@see \SugarCraft\Code\Providers\HasModelCatalog}
     * — asking the endpoint the chat calls would use (openai's unset base is
     * the SDK default, never a guess), and only when the discovery opt-out
     * ({@see \SugarCraft\Code\Providers\ModelMetadata::OPT_OUT_ENV}) is not
     * set, exactly like the palette's prefetch. Everything else answers from
     * the LiteLLM table and the configured id alone. A dead endpoint costs its
     * bounded seconds once and degrades to the table tiers — this is a CLI
     * listing, so a wait is honest here where the palette may only ever
     * read memos.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private static function modelCatalogRows(string $name, array $config, string $configured, ?\SugarCraft\Code\Providers\ModelMetadata $metadata): array
    {
        $type = \is_string($config['type'] ?? null) ? strtolower($config['type']) : $name;
        $live = null;

        if ($type === 'openai' || $type === 'sglang' || $type === 'custom') {
            $key = \is_string($config['apiKey'] ?? null) && $config['apiKey'] !== '' ? $config['apiKey'] : null;
            $base = \is_string($config['baseUrl'] ?? null) && trim($config['baseUrl']) !== ''
                ? trim($config['baseUrl'])
                // The SDK default is only worth asking with a key in hand:
                // an anonymous api.openai.com call is a guaranteed 401, so
                // without one the catalog stays table-and-config, no bytes
                // spent. (sglang/custom default to null — no invented base.)
                : ($type === 'openai' && $key !== null ? 'https://api.openai.com/v1' : null);

            if ($base !== null && !\SugarCraft\Code\Providers\ModelMetadata::disabledFromEnvironment()) {
                $live = \SugarCraft\Code\Providers\OpenAiModelsEndpoint::fetch($base, $key);
            }
        }

        return \SugarCraft\Code\Providers\ModelCatalog::merge(
            // The literal 'unknown' is the row display's placeholder, not a
            // model id; the catalog tier starts from what is actually set.
            $configured === 'unknown' ? [] : [$configured],
            $live,
            \SugarCraft\Code\Providers\ModelCatalog::litellm($name, $metadata),
        );
    }

    // ---------------------------------------------------------------------
    // login (PRV-4 tier 2)
    // ---------------------------------------------------------------------

    /**
     * The five built-in types whose credential IS an API key this command
     * can store. The env names are the ones ProviderFactory's own defaults
     * read; naming one nothing consumes would be worse than naming none.
     * (C-PRV-2.c added `azure`/AZURE_OPENAI_API_KEY and
     * `openrouter`/OPENROUTER_API_KEY — azure still needs a `resource` or
     * `baseUrl` in its block, which login deliberately does not invent.)
     *
     * @var array<string, string>
     */
    private const LOGIN_KEY_ENVS = [
        'openai' => 'OPENAI_API_KEY',
        'azure' => 'AZURE_OPENAI_API_KEY',
        'anthropic' => 'ANTHROPIC_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
        'openrouter' => 'OPENROUTER_API_KEY',
    ];

    /**
     * Test seam for the passthrough spawn: null runs the real proc_open
     * inheritance. The suite NEVER launches a vendor login flow — that would
     * open browsers, wait on terminals, and spend real accounts.
     *
     * @var (callable(array<string>): int)|null
     */
    private static ?\Closure $loginSpawn = null;

    /**
     * Test seam for reading the pasted key; null reads one STDIN line.
     *
     * @var (callable(): string|false)|null
     */
    private static ?\Closure $loginKeyRead = null;

    /**
     * Test seam for the echo-off terminal handle; null opens sugar-core's
     * {@see Tty} over STDIN. The armed fake — `isTty`, `enableRawMode`,
     * `getc`, `restore` — is what makes the raw-mode branch testable with
     * no terminal anywhere: {@see self::loginKeyLine()} consults the seam
     * ONLY when `$loginKeyRead` itself is null, so the existing paste
     * seams never touch the terminal at all.
     *
     * @var (callable(): object)|null
     */
    private static ?\Closure $loginTty = null;

    /**
     * @param (callable(array<string>): int)|null $spawn
     * @param (callable(): string|false)|null $keyRead
     * @param (callable(): object)|null $tty
     */
    public static function setLoginSeamsForTesting(?\Closure $spawn, ?\Closure $keyRead, ?\Closure $tty = null): void
    {
        self::$loginSpawn = $spawn;
        self::$loginKeyRead = $keyRead;
        self::$loginTty = $tty;
    }

    public static function resetLoginSeamsForTesting(): void
    {
        self::setLoginSeamsForTesting(null, null, null);
    }

    /**
     * `candy-code login <provider>` — the tier-2 half of PRV-4.
     *
     * Deliberately NOT a credential system: subscription arms hand over to
     * the upstream CLI (`claude auth login`, `codex login` — both probed
     * present on 2026-10-19) whose OWN auth code writes the token, so no
     * secret ever passes through this process; API-key arms store via the
     * existing {@see Bootstrap::writeUserConfig()} path into the SAME
     * `providers` block every launch already reads ({@see
     * \SugarCraft\Code\Cli\Bootstrap::providerOverrides()} — which is why
     * the entry carries `type`, the reader requires it); ambient arms get a
     * refusal naming who actually owns their credential. Tier 3 (extracting
     * MCP-OAuth into a shared store) is explicitly out of scope.
     *
     * C-SEC-8.f: API-key arms take `--keychain`, which hands the pasted
     * secret to {@see Keychain::store()} (secret on stdin, never argv) and
     * persists only the `${keychain:<provider>}` reference in the config.
     * Subscription and ambient arms refuse the flag — no key passes through
     * this process for them to keep.
     */
    private static function login(ParsedArgs $args): int
    {
        $words = $args->subcommandArgs;
        $types = \implode(', ', (new ProviderFactory())->availableTypes());

        if ($words === []) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.sub.login.usage'),
                $args->outputFormat,
                Lang::t('cli.sub.login.selectable', ['types' => $types]),
            );
        }

        if (count($words) > 1) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.sub.login.too_many', ['count' => (string) count($words)]),
                $args->outputFormat,
            );
        }

        $type = $words[0];
        if (!\in_array($type, (new ProviderFactory())->availableTypes(), true)) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.sub.login.unknown', ['name' => $type]),
                $args->outputFormat,
                Lang::t('cli.sub.login.selectable', ['types' => $types]),
            );
        }

        // C-SEC-8.f: `--keychain` belongs to the paste-and-store arms only.
        $keychain = \array_key_exists('--keychain', $args->subcommandFlags);
        if ($keychain && !isset(self::LOGIN_KEY_ENVS[$type])) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.login.keychain.not_key', ['provider' => $type]),
                $args->outputFormat,
            );
        }

        return match ($type) {
            'claude-code' => self::loginPassthrough(['claude', 'auth', 'login'], 'cli.sub.login.passthrough_claude'),
            'codex' => self::loginPassthrough(['codex', 'login'], 'cli.sub.login.passthrough_codex'),
            'openai', 'azure', 'anthropic', 'gemini', 'openrouter' => self::loginStoreKey($type, $keychain),
            default => self::loginAmbient($type),
        };
    }

    /**
     * Hand the terminal to the vendor's own login command. No pipes are set
     * up on purpose: an OAuth flow prints a URL, opens a browser, and waits —
     * it must inherit exactly the stdio the user is sitting at.
     *
     * @param array<string> $cmd
     */
    private static function loginPassthrough(array $cmd, string $explainKey): int
    {
        echo Lang::t($explainKey) . "\n";

        $spawn = self::$loginSpawn ?? static function (array $argv): int {
            // E672 pre-check twin: under the containment wrapper a missing
            // binary would exit 127 attributed to the run; name it ourselves.
            if (ProcessContainment::detachedSpawnBinary() !== ''
                && !(str_contains($argv[0], '/')
                    ? is_executable($argv[0])
                    : ProcessContainment::locateOnPath($argv[0]) !== '')
            ) {
                return -1;
            }

            // `@`: with no wrapper the failed exec raises an E_WARNING from
            // proc_open() itself; the classification below is by return value,
            // and failOnWarning must not red a run this command answers.
            $process = @proc_open(
                ProcessContainment::spawnSpec($argv),
                [],
                $pipes,
                null,
                ProcessContainment::env()
            );

            return is_resource($process) ? proc_close($process) : -1;
        };

        $status = $spawn($cmd);

        if ($status === -1) {
            echo Lang::t('cli.sub.login.missing_binary', ['name' => $cmd[0]]) . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        echo Lang::t('cli.sub.login.passthrough_done', ['name' => $cmd[0], 'code' => (string) $status]) . "\n";

        return $status === 0 ? NonInteractive::EXIT_OK : NonInteractive::EXIT_FAILURE;
    }

    /**
     * Read one key from stdin and persist it into the user config's
     * `providers` map — merging onto the file's EXISTING map by hand, because
     * writeUserConfig() merges shallow at the top level and replacing the
     * whole `providers` key would silently drop every other named provider.
     *
     * The provider's OWN block is merged too (C-PRV-1.c): a rotation rewrites
     * `apiKey` and (re)asserts `type` but keeps `model`, `thinkingBudget`,
     * `modelPrices` and — worst case to lose — a gateway `baseUrl`. When the
     * existing block declares a DIFFERENT `type` the write is refused
     * outright: two provider kinds cannot share one slot, and silently
     * flipping `type` would repoint a hand-tuned block's endpoint and prices
     * at a protocol it never asked for. A block with no `type` at all is
     * untyped rather than contradicting, so it merges and gains one. A
     * non-array block has no `type` to differ and is replaced, as before.
     *
     * The read itself goes through {@see self::loginKeyLine()}: echo stays
     * off on a live terminal.
     *
     * With C-SEC-8.f's `$keychain` the secret is written to the OS backend
     * FIRST and the config only ever sees the `${keychain:<provider>}`
     * reference: a failed store leaves the config byte-untouched. A config
     * refusal that arrives after a successful store (corrupt file, type
     * mismatch) leaves an entry under this provider's name — harmless, as
     * the next attempt replaces it through the same `-U`/same-attributes
     * write.
     */
    private static function loginStoreKey(string $type, bool $keychain = false): int
    {
        $path = Bootstrap::userConfigPath();
        echo Lang::t('cli.sub.login.ask_key', ['provider' => $type, 'path' => $path]) . "\n";

        $key = trim((string) self::loginKeyLine());

        if ($key === '') {
            echo Lang::t('cli.sub.login.empty_key') . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            echo Lang::t('cli.sub.login.control_chars') . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        if ($keychain) {
            // The secret leaves this process only down the child's stdin.
            // Nothing has touched the config yet, and nothing below writes
            // it unless this verdict is 'ok'.
            $verdict = Keychain::store($type, $key);
            if ($verdict !== 'ok') {
                $family = Keychain::osFamily();
                $backend = (Keychain::storeArgvFor($family, $type) ?? [''])[0];
                echo match ($verdict) {
                    'missing' => Lang::t('cli.login.keychain.missing', ['backend' => $backend, 'provider' => $type]),
                    'timeout' => Lang::t('cli.login.keychain.timeout', ['backend' => $backend, 'provider' => $type]),
                    'unsupported' => Lang::t('cli.login.keychain.unsupported', ['os' => $family, 'provider' => $type]),
                    default => Lang::t('cli.login.keychain.failed', ['provider' => $type]),
                } . "\n";

                return NonInteractive::EXIT_FAILURE;
            }
            // From here the ONLY thing this method knows about the secret is
            // its reference — the settle re-read compares against this word,
            // and an unsupported family never even reaches the store call.
            $key = '${keychain:' . $type . '}';
        }

        $providers = [];
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    echo Lang::t('cli.sub.login.corrupt_config', ['path' => $path]) . "\n";

                    return NonInteractive::EXIT_FAILURE;
                }
                if (is_array($decoded['providers'] ?? null)) {
                    $providers = $decoded['providers'];
                }
            }
        }

        $block = $providers[$type] ?? null;
        if (is_array($block) && \array_key_exists('type', $block) && $block['type'] !== $type) {
            $actual = \is_string($block['type']) ? $block['type'] : \get_debug_type($block['type']);
            echo Lang::t('cli.sub.login.type_mismatch', ['path' => $path, 'provider' => $type, 'actual' => $actual]) . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        $providers[$type] = [...(is_array($block) ? $block : []), 'type' => $type, 'apiKey' => $key];
        Bootstrap::writeUserConfig(['providers' => $providers]);

        // writeUserConfig() can bail without writing (lock contention, an
        // unresolvable symlink); the confirmation must not promise a persist
        // that did not happen, so re-read the exact slot instead.
        $settled = json_decode((string) @file_get_contents($path), true);
        if (!is_array($settled) || ($settled['providers'][$type]['apiKey'] ?? null) !== $key) {
            echo Lang::t('cli.sub.login.stored_failed', ['path' => $path]) . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        echo ($keychain
            ? Lang::t('cli.login.keychain.stored', ['provider' => $type, 'path' => $path, 'reference' => $key])
            : Lang::t('cli.sub.login.stored', ['provider' => $type, 'path' => $path, 'env' => self::LOGIN_KEY_ENVS[$type]])) . "\n";

        return NonInteractive::EXIT_OK;
    }

    /**
     * Read the pasted key line in whichever shape the situation has.
     *
     * - A test-armed `$loginKeyRead` IS the whole read: the terminal is
     *   never opened, never probed, never mode-switched — which is what
     *   keeps this suite TTY-free exactly as it was before C-PRV-1.c.
     * - Otherwise a handle over STDIN (the `$loginTty` seam, or the real
     *   sugar-core {@see Tty}) answers whether this is a terminal. A pipe
     *   gets the original `fgets()` line with nothing touched.
     * - A terminal gets raw mode, which is `stty raw -echo`: the typed key
     *   never appears on screen, and because raw also clears ISIG, Ctrl-C
     *   arrives as a byte the reader answers rather than as a signal. The
     *   handle is given back in `finally` on EVERY exit path — normal end,
     *   Ctrl-C, EOF, or a throwing read — and an externally delivered
     *   SIGINT/SIGTERM is covered by {@see self::armLoginKeySignals()},
     *   whose `finally` cannot be skipped because default disposition kills
     *   before any shutdown runs.
     *
     * No child process is spawned FROM CANDY-CODE for any of this;
     * sugar-core's Tty drives termios through its own FFI/stty seam, and
     * that seam's stty arm may shell out to stty(1) on hosts without FFI.
     */
    private static function loginKeyLine(): string|false
    {
        $read = self::$loginKeyRead;
        if ($read !== null) {
            return $read();
        }

        $openTty = self::$loginTty ?? static fn (): object => self::openLoginTtyHandle();
        $tty = $openTty();

        if (!$tty->isTty()) {
            return \fgets(\STDIN);
        }

        $disarm = self::armLoginKeySignals($tty);
        try {
            $tty->enableRawMode();

            return self::readLoginKeyLine($tty);
        } finally {
            // Restore first: the signal guard points AT the handle, so the
            // terminal goes back before its interrupt handlers come off.
            $tty->restore();

            if ($disarm !== null) {
                $disarm();
            }
        }
    }

    /**
     * The production shape of the `$loginTty` handle: sugar-core's Tty for
     * the modes, one byte at a time from the same STDIN it was opened over.
     * Anonymous on purpose — this is a private seam shape, not a type.
     */
    private static function openLoginTtyHandle(): object
    {
        $tty = new Tty(\STDIN);

        return new class($tty) {
            public function __construct(private readonly Tty $tty)
            {
            }

            public function isTty(): bool
            {
                return $this->tty->isTty();
            }

            public function enableRawMode(): void
            {
                $this->tty->enableRawMode();

                // sugar-core leaves STDIN non-blocking for render loops that
                // must never stall on a key. This read waits on a human, so
                // it blocks again and lets a dispatched signal interrupt the
                // wait instead of spinning on "nothing yet". restore()
                // re-asserts blocking mode on the POSIX backend anyway.
                if (\DIRECTORY_SEPARATOR !== '\\') {
                    @\stream_set_blocking(\STDIN, true);
                }
            }

            public function restore(): void
            {
                $this->tty->restore();
            }

            public function getc(): string|false
            {
                return \fgetc(\STDIN);
            }
        };
    }

    /**
     * One echo-off key line: Enter ends it, Backspace erases the character
     * before the caret, Ctrl-C — which raw mode delivers as a byte, not a
     * signal — refuses the read whole (a half-typed key is thrown away,
     * never stored), and a real end-of-file answers with whatever was typed,
     * exactly as `fgets()` would. Every other byte, arrow escapes included,
     * joins the line and is left for the control-character check after the
     * read to refuse.
     */
    private static function readLoginKeyLine(object $tty): string|false
    {
        $line = '';

        while (true) {
            $byte = $tty->getc();

            if ($byte === false) {
                return $line === '' ? false : $line;
            }
            if ($byte === "\r" || $byte === "\n") {
                return $line;
            }
            if ($byte === "\x7F" || $byte === "\x08") {
                $line = \substr($line, 0, -1);

                continue;
            }
            if ($byte === "\x03") {
                return false;
            }

            $line .= $byte;
        }
    }

    /**
     * The one exit path a `finally` cannot cover: default-disposition
     * SIGINT kills the process mid-read without running any finally or
     * destructor, leaving the terminal raw and echoless for the shell that
     * inherits it. While the echo-off read is in flight, SIGINT and SIGTERM
     * are caught, the terminal is handed back, and the signal is re-raised
     * at its default disposition — so the process still dies with true
     * signal status (128+N to the shell) rather than a laundered exit.
     * Returns the teardown that puts the previous handlers back, or null
     * when this process has no pcntl/posix to ask (Windows consoles need
     * none: raw mode there delivers Ctrl-C as a byte, which the reader
     * already answers).
     */
    private static function armLoginKeySignals(object $tty): ?callable
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')
            || !\function_exists('pcntl_signal_get_handler') || !\function_exists('posix_kill')
            || !\defined('SIGINT') || !\defined('SIGTERM')
        ) {
            return null;
        }

        $handler = static function (int $signo) use ($tty): void {
            try {
                $tty->restore();
            } finally {
                \pcntl_signal($signo, \SIG_DFL);
                \posix_kill(\getmypid(), $signo);
            }
        };

        /** @var array<int, int|callable> $previous */
        $previous = [];
        foreach ([\SIGINT, \SIGTERM] as $signo) {
            $previous[$signo] = \pcntl_signal_get_handler($signo);
            \pcntl_signal($signo, $handler);
        }
        $previousAsync = \pcntl_async_signals(true);

        return static function () use ($previous, $previousAsync): void {
            foreach ($previous as $signo => $handler) {
                \pcntl_signal($signo, $handler);
            }
            \pcntl_async_signals($previousAsync);
        };
    }

    /**
     * Types with no credential this command could sensibly take: the vendor
     * chain or the provider block owns theirs. Answers and exits 1 — the
     * requested login did not happen.
     */
    private static function loginAmbient(string $type): int
    {
        // Full literal keys, never concatenation: CliLangCatalogueTest proves
        // every cli.* key is asked for by name, and a composed name is invisible
        // to that scan.
        $hint = match ($type) {
            'sglang' => Lang::t('cli.sub.login.hint_sglang'),
            'bedrock' => Lang::t('cli.sub.login.hint_bedrock'),
            'vertex' => Lang::t('cli.sub.login.hint_vertex'),
            default => Lang::t('cli.sub.login.hint_custom'),
        };

        echo Lang::t('cli.sub.login.ambient', ['provider' => $type, 'hint' => $hint]) . "\n";

        return NonInteractive::EXIT_FAILURE;
    }

    // ---------------------------------------------------------------------
    // session
    // ---------------------------------------------------------------------

    /**
     * `candy-code session list|show|rename|delete|pin|unpin|archive|unarchive`
     * (Appendix P §3.4).
     *
     * Every verb goes through {@see Bootstrap::sessionStore()} — the accessor
     * the TUI and the one-shot path already use — rather than opening
     * `~/.candy-code/session.db` directly, so the id printed by `list` is
     * exactly the id the other verbs and a resumed session accept. NOTE that
     * accessor also applies the configured RETENTION sweep on construction,
     * which is the launch behaviour and is inherited here deliberately: a
     * `session list` that showed rows the next launch would delete would be
     * lying about what is stored.
     *
     * A TARGET is an id, a name or a unique id prefix, resolved by
     * {@see \SugarCraft\Code\Session\SessionResolver} — the same resolver
     * `--resume` uses, over every kind and archived rows too. Nothing matching
     * is exit 1 (`not-found`: the store was asked); an ambiguous prefix is
     * exit 2 with the candidates listed, because the invocation itself cannot
     * be carried out as typed.
     */
    private static function session(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[0] ?? null;
        if ($verb === null) {
            return NonInteractive::failUsage('candy-code: ' . Lang::t('cli.session.no_action'), $args->outputFormat, self::sessionUsage());
        }
        if (!isset(self::SESSION_ACTION_FLAGS[$verb])) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.session.unknown_action', ['action' => $verb]),
                $args->outputFormat,
                self::sessionUsage(),
            );
        }

        foreach (\array_keys($args->subcommandFlags) as $flag) {
            if (!\in_array($flag, self::SESSION_ACTION_FLAGS[$verb], true)) {
                return NonInteractive::failUsage(
                    'candy-code: ' . Lang::t('cli.session.flag_does_not_apply', ['action' => $verb, 'flag' => $flag]),
                    $args->outputFormat,
                    self::SESSION_ACTION_FLAGS[$verb] === []
                        ? Lang::t('cli.session.takes_no_options', ['action' => $verb])
                        : Lang::t('cli.session.accepts', ['action' => $verb, 'flags' => \implode(', ', self::SESSION_ACTION_FLAGS[$verb])]),
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
    private static function sessionUsage(): string
    {
        return Lang::t('cli.session.usage');
    }

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
        // --force (ISS-079): act on the row even while another candy-code
        // window has it open. The gate is live-flock, not the stale-prone
        // pid file, so refusing without --force is never a false alarm about
        // a dead session; overriding is for the user who knows better than
        // the flock — a wedged window they intend to kill, say.
        'rename' => ['--force'],
        'delete' => ['--with-children', '--force'],
        'pin' => ['--force'],
        'unpin' => ['--force'],
        'archive' => ['--force'],
        'unarchive' => ['--force'],
    ];

    /**
     * `session list`: the user's own conversations (main and branch rows),
     * pinned first and newest first, 20 by default. `--children` adds the
     * sub-agent and background rows that hang under them, `--archived` adds
     * archived rows, `--all` is both, and `--limit N` changes the page size.
     *
     * The text row is `★ id updated kind turns spend provider/model name`,
     * with `[archived]` after an archived row's name and `spend` the session's
     * cached ledger figure — {@see \SugarCraft\Code\Host\SpendLedger::boundUsd()}
     * spelling, `≥$` when an unpriced model set the lower bound, an em dash when
     * no accounted call ever named the row; the JSON rows are
     * {@see \SugarCraft\Code\Session\SessionRow::toArray()} plus the
     * `spentUsd`/`spendUnpriced` pair, which carries every column the store
     * keeps (kind, parent, pinned, archived_at, turns,
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
                    'candy-code: ' . Lang::t('cli.session.bad_limit', ['limit' => $raw]),
                    $args->outputFormat,
                    Lang::t('cli.session.limit_usage'),
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
                // C-SES-1.a: the ledger-derived spend rides the camelCase keys a
                // script reads the one-shot envelope by (`spentUsd`,
                // `spendUnpriced`), beside the snake_case stored columns
                // `toArray()` already carries.
                static fn(SessionRow $r): array => $r->toArray() + [
                    'spentUsd' => $r->costUsd,
                    'spendUnpriced' => $r->costUnpriced,
                ],
                $rows,
            )]]);

            return NonInteractive::EXIT_OK;
        }

        if ($rows === []) {
            echo Lang::t('cli.session.none_stored') . "\n";

            return NonInteractive::EXIT_OK;
        }

        $idWidth = 0;
        foreach ($rows as $row) {
            $idWidth = \max($idWidth, \strlen($row->id));
        }
        foreach ($rows as $row) {
            \printf(
                "%s %-{$idWidth}s  %-19s  %-10s  %5s  %-9s  %-24s  %s\n",
                $row->pinned ? '★' : ' ',
                $row->id,
                $row->updatedAt,
                $row->kind->value,
                $row->turns . 't',
                // C-SES-1.a: the session's cached ledger spend, spelled exactly
                // as the tracker's readout spells it (SpendLedger::boundUsd, so
                // an unpriced session reads as a LOWER BOUND `≥$…`), and an em
                // dash when no accounted call ever named this row — null is
                // "never measured", distinct from a measured zero.
                $row->costUsd === null
                    ? Lang::t('cli.session.list.spend_none')
                    : SpendLedger::boundUsd($row->costUnpriced, $row->costUsd),
                $row->provider . '/' . $row->model,
                self::sessionLabel($row) . ($row->archived() ? ' ' . Lang::t('cli.session.archived_tag') : ''),
            );
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `session show <target>`: the session's row, then its transcript as
     * Markdown ({@see Exporter::toMarkdown()}, the
     * markdown formatter behind both `/share` and `/export`, so hidden rows
     * stay hidden). Under
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
        echo Lang::t('cli.session.show.id', ['id' => $row->id]) . "\n";
        echo ($row->parentId !== null
            ? Lang::t('cli.session.show.kind_child', ['kind' => $row->kind->value, 'parent' => $row->parentId])
            : Lang::t('cli.session.show.kind', ['kind' => $row->kind->value])) . "\n";
        echo Lang::t('cli.session.show.model', ['model' => $row->provider . '/' . $row->model]) . "\n";
        echo Lang::t('cli.session.show.updated', ['updated' => $row->updatedAt, 'turns' => $row->turns]) . "\n";
        if ($row->pinned || $row->archived()) {
            echo Lang::t('cli.session.show.flags', ['flags' => \implode(', ', \array_filter([
                $row->pinned ? Lang::t('cli.session.show.pinned') : null,
                $row->archived() ? Lang::t('cli.session.show.archived', ['at' => (string) $row->archivedAt]) : null,
            ]))]) . "\n";
        }
        echo "\n" . ($messages === [] ? Lang::t('cli.session.show.no_transcript') . "\n" : \rtrim(Exporter::toMarkdown($messages)) . "\n");

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
                'candy-code: ' . Lang::t('cli.session.rename.no_title'),
                $args->outputFormat,
                Lang::t('cli.session.rename.usage'),
            );
        }

        $store = Bootstrap::sessionStore();
        $row = self::resolveSessionTarget('rename', $args, $store);
        if (\is_int($row)) {
            return $row;
        }
        $refusal = self::refuseLiveLockedSession($row, $store, $args);
        if ($refusal !== null) {
            return $refusal;
        }

        $store->renameSession($row->id, $title, TitleSource::User);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['renamed' => $row->id, 'name' => $title]]);
        } else {
            echo Lang::t('cli.session.rename.done', ['id' => $row->id, 'title' => $title]) . "\n";
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `session delete <target> [--with-children]`. Sub-agent children always
     * go with their parent; branch and background children are detached and
     * kept unless `--with-children` deletes every descendant
     * ({@see \SugarCraft\Code\Session\SessionStore::deleteSession()}).
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
        $refusal = self::refuseLiveLockedSession($row, $store, $args);
        if ($refusal !== null) {
            return $refusal;
        }

        $deleted = $store->deleteSession($row->id, isset($args->subcommandFlags['--with-children']));

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['deleted' => $row->id, 'deletedIds' => $deleted]]);
        } else {
            $others = \count($deleted) - 1;
            echo ($others > 0
                ? Lang::t('cli.session.delete.done_with_children', ['id' => $row->id, 'count' => $others])
                : Lang::t('cli.session.delete.done', ['id' => $row->id])) . "\n";
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
        $refusal = self::refuseLiveLockedSession($row, $store, $args);
        if ($refusal !== null) {
            return $refusal;
        }

        $changed = match ($verb) {
            'pin' => !$row->pinned && $store->setPinned($row->id, true),
            'unpin' => $row->pinned && $store->setPinned($row->id, false),
            'archive' => $store->archive($row->id),
            'unarchive' => $store->unarchive($row->id),
        };
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['session' => $row->id, 'action' => $verb, 'changed' => $changed]]);
        } else {
            $id = ['id' => $row->id];
            echo match ($verb) {
                'pin' => $changed ? Lang::t('cli.session.flag.pinned', $id) : Lang::t('cli.session.flag.already_pinned', $id),
                'unpin' => $changed ? Lang::t('cli.session.flag.unpinned', $id) : Lang::t('cli.session.flag.already_unpinned', $id),
                'archive' => $changed ? Lang::t('cli.session.flag.archived', $id) : Lang::t('cli.session.flag.already_archived', $id),
                'unarchive' => $changed ? Lang::t('cli.session.flag.unarchived', $id) : Lang::t('cli.session.flag.already_unarchived', $id),
            } . "\n";
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
                'candy-code: ' . Lang::t('cli.session.no_target', ['action' => $verb]),
                $args->outputFormat,
                Lang::t('cli.session.target_usage', ['action' => $verb]),
            );
        }

        $matches = SessionResolver::matches($store, $target);
        if (\count($matches) > 1) {
            $shown = \array_slice($matches, 0, 10);

            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.session.ambiguous', ['action' => $verb, 'target' => $target, 'count' => \count($matches)]),
                $args->outputFormat,
                Lang::t('cli.session.candidates', ['candidates' => \implode(', ', \array_map(
                    static fn(SessionRow $r): string => $r->id . ' (' . self::sessionLabel($r) . ')',
                    $shown,
                )) . (\count($matches) > \count($shown) ? ', …' : '')]),
            );
        }

        if ($matches === []) {
            // EXIT 1, NOT 2, and the distinction is the documented one: the
            // store WAS opened and queried, so something was attempted. Exit 2
            // means nothing ran — which is what a MISSING target above means,
            // and is why the two branches of "the target went wrong" report
            // differently.
            \fwrite(\STDERR, 'candy-code: ' . Lang::t('cli.session.not_found', ['target' => $target]) . "\n");
            if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
                self::emitDocument([
                    'result' => null,
                    'error' => ['type' => 'not-found', 'message' => Lang::t('cli.session.not_found_message', ['target' => $target])],
                ]);
            }

            return NonInteractive::EXIT_FAILURE;
        }

        return $matches[0];
    }

    /**
     * The ISS-079 gate: a row another live candy-code window holds is not
     * this verb's to rename, flag or delete — the window is still writing the
     * row, so whatever this run reports as done its next save can silently
     * undo, and a deleted row comes back as a ghost through saveTranscript()'s
     * recreate arm with half its history pointed at the void. The probe is
     * {@see EnhancedSessionStore::liveLockHolder()}, which ASKS the flock
     * rather than reading the pid file, so a lock left by a dead process
     * never refuses anything. `--force` skips the question entirely.
     *
     * The refusal rides the usage channel (exit 2, `usage` error document),
     * not the not-found channel: the command as given cannot be honoured —
     * the mutation is refused before it is attempted, which is exactly what
     * exit 2's "nothing was attempted" states about the destructive half.
     * A dedicated `locked` error.type would need README.md's error contract
     * lines (closeout-owned this wave); that promotion is a DOC-ROW, not a
     * reason to ship silence.
     *
     * @return int|null the exit code already reported, or null when the row
     *                  is free to act on
     */
    private static function refuseLiveLockedSession(SessionRow $row, EnhancedSessionStore $store, ParsedArgs $args): ?int
    {
        if (isset($args->subcommandFlags['--force'])) {
            return null;
        }

        $holder = $store->liveLockHolder($row->id);
        if ($holder === null) {
            return null;
        }

        return NonInteractive::failUsage(
            'candy-code: ' . Lang::t('cli.session.locked', ['id' => self::sessionLabel($row), 'pid' => $holder]),
            $args->outputFormat,
            Lang::t('cli.session.locked_hint'),
        );
    }

    private static function rejectSessionOperand(string $verb, string $operand, ParsedArgs $args): int
    {
        return NonInteractive::failUsage(
            'candy-code: ' . Lang::t('cli.session.unexpected_operand', ['action' => $verb, 'operand' => $operand]),
            $args->outputFormat,
            self::sessionUsage(),
        );
    }

    /** A row's name, or `(unnamed)` — raw storage, printed to a terminal the user owns. */
    private static function sessionLabel(SessionRow $row): string
    {
        return $row->name ?? Lang::t('cli.session.unnamed');
    }

    // ---------------------------------------------------------------------
    // mcp
    // ---------------------------------------------------------------------

    /**
     * The `bundles` verb: `list` reads the tree, `install` stages a bundle
     * into the user root — from a path, a git URL, or (since C-EXT-4) one
     * catalog entry `plugin@marketplace` — and the C-EXT-4 family adds
     * `marketplace add|list|remove|update`, `update [bundle]`, `search <q>`
     * and `recommend`. All answer without a provider, an API key or a
     * terminal, like every sibling in this class.
     */
    private static function bundles(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[0] ?? null;
        if ($verb === 'list') {
            return self::bundlesList($args);
        }
        if ($verb === 'install') {
            return self::bundlesInstall($args);
        }
        if ($verb === 'marketplace') {
            return self::bundlesMarketplace($args);
        }
        if ($verb === 'update') {
            return self::bundlesUpdate($args);
        }
        if ($verb === 'search') {
            return self::bundlesSearch($args);
        }
        if ($verb === 'recommend') {
            return self::bundlesRecommend($args);
        }

        return NonInteractive::failUsage(
            $verb === null
                ? 'candy-code: ' . Lang::t('cli.bundles.no_action')
                : 'candy-code: ' . Lang::t('cli.bundles.unknown_action', ['action' => $verb]),
            $args->outputFormat,
            Lang::t('cli.bundles.usage'),
        );
    }

    /**
     * `bundles list` — everything PLG-1's reader sees from this directory:
     * name, version, tier, a count per surface, and the two declined
     * surfaces spelled out. The discovery is read FRESH here rather than
     * through the memoized app feed: a one-shot CLI answer must reflect the
     * disk as it is now, and warnings/refusals go to this process's stderr,
     * not into a launch-notice store no headless reader will ever drain.
     */
    private static function bundlesList(ParsedArgs $args): int
    {
        $configured = Bootstrap::readUserConfig()[BundleDiscovery::SETTING_KEY] ?? [];
        $paths = \array_values(\array_filter(\is_array($configured) ? $configured : [], 'is_string'));
        $discovery = new BundleDiscovery((string) ($args->root ?? ''), $paths);
        $bundles = $discovery->discover();

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            $rows = [];

            foreach ($bundles as $bundle) {
                $rows[] = [
                    'name' => $bundle->name,
                    'version' => $bundle->version,
                    'tier' => $bundle->tier,
                    'dir' => $bundle->dir,
                    'skills' => \count($bundle->skillsPaths()),
                    'commands' => \count($bundle->commandsPaths()),
                    'agents' => \count($bundle->agentsPaths()),
                    // Declined surfaces: counted honestly, never started.
                    'hooks' => \count($bundle->hooksPaths()),
                    'mcp' => $bundle->mcpFile() !== null,
                ];
            }

            echo \json_encode(
                ['bundles' => $rows, 'warnings' => $discovery->warnings(), 'refused' => \array_values($discovery->refusedDirectories())],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ) . "\n";

            return NonInteractive::EXIT_OK;
        }

        if ($bundles === []) {
            echo Lang::t('cli.bundles.none') . "\n";

            return NonInteractive::EXIT_OK;
        }

        echo Lang::t('cli.bundles.header') . "\n";

        foreach ($bundles as $bundle) {
            \printf(
                "%-18s %-9s %-7s %-7s %-9s %-7s %-6s %s\n",
                $bundle->name,
                $bundle->version === '' ? '-' : $bundle->version,
                $bundle->tier,
                (string) \count($bundle->skillsPaths()),
                (string) \count($bundle->commandsPaths()),
                (string) \count($bundle->agentsPaths()),
                \count($bundle->hooksPaths()) > 0 ? 'yes' : '-',
                $bundle->mcpFile() !== null ? 'yes' : '-',
            );
        }

        echo Lang::t('cli.bundles.declined') . "\n";

        foreach ($discovery->warnings() as $warning) {
            self::bundlesLine($warning);
        }

        foreach ($discovery->refusedDirectories() as $reason) {
            self::bundlesLine($reason);
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * The one prefixed stderr line every `bundles` diagnostic rides, shared
     * by the listing's echoes and the installer's refusals so a CI gate
     * reading this verb's bytes sees one shape.
     */
    private static function bundlesLine(string $message): void
    {
        \fwrite(\STDERR, 'candy-code: ' . $message . "\n");
    }

    /**
     * Wall-clock ceiling for `bundles install`'s git clone. A ceiling on
     * ONE named child the user asked for, escalated through
     * {@see BoundedShutdown} with its process group — not a blanket timeout
     * on anything model-facing (the E646 line).
     */
    private const BUNDLE_CLONE_TIMEOUT_SECONDS = 120.0;

    /**
     * The repo-local git variables removed from the clone's environment —
     * the same discipline {@see \SugarCraft\Code\MCP\GitCommandHandlers}
     * holds: an inherited `GIT_DIR` must never redirect a clone the user
     * asked for into somebody else's object store.
     */
    private const BUNDLE_INSTALL_GIT_STRIP = [
        'GIT_DIR',
        'GIT_WORK_TREE',
        'GIT_INDEX_FILE',
        'GIT_OBJECT_DIRECTORY',
        'GIT_ALTERNATE_OBJECT_DIRECTORIES',
        'GIT_COMMON_DIR',
    ];

    /**
     * Test seam for the clone child, the {@see self::$loginSpawn} idiom:
     * `$fn($url, $scratch)` returns `['ok' => bool, 'detail' => string]`.
     * The default arms a `setsid -w`-wrapped shallow clone, drains both
     * pipes until exit (never single-shot), and kills the WHOLE group on
     * expiry so git-transport children die with it.
     *
     * @var (callable(string, string): array{ok: bool, detail: string})|null
     */
    private static ?\Closure $bundlesInstallSpawn = null;

    /**
     * `bundles install <path|git-url|plugin@marketplace>` — stage a bundle
     * into `~/.candy-code/bundles/`, the ONE directory this verb writes to
     * (owned-dest law: {@see HomeDirectory::owned()} or refusal).
     *
     * A directory source must carry a manifest and is copied minus every
     * `.git` directory — a bundle ships content, not the vendor's history.
     * A git URL is cloned shallowly into a scratch directory INSIDE the
     * bundle root, validated for a manifest, and only then renamed into
     * place; failures remove the scratch. Since C-EXT-7 the clone sheds
     * its own `<scratch>/.git` through the same no-follow walk the failure
     * exits use, so BOTH arms land content-only trees. A name beginning
     * with a dot is refused: `BundleDiscovery` scans the root with a glob
     * that never matches a dot-entry, so a dot-named install would sit in
     * the root invisible to every later
     * load — a loud refusal beats an install that never arrives. An
     * existing install of the same name is refused, never overwritten:
     * silently replacing what the session loaded this morning is not what
     * an install means.
     */
    private static function bundlesInstall(ParsedArgs $args): int
    {
        $source = $args->subcommandArgs[1] ?? null;
        if ($source === null || $source === '') {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.bundles.install.needs_source'),
                $args->outputFormat,
                Lang::t('cli.bundles.usage'),
            );
        }

        $home = HomeDirectory::owned();
        if ($home === null) {
            self::bundlesLine(Lang::t('cli.bundles.install.no_home'));

            return NonInteractive::EXIT_FAILURE;
        }

        $root = $home . '/.candy-code/bundles';
        if (!is_dir($root) && !@\mkdir($root, 0700, true) && !is_dir($root)) {
            self::bundlesLine(Lang::t('cli.bundles.install.no_home'));

            return NonInteractive::EXIT_FAILURE;
        }

        if (is_dir($source) && realpath($source) === realpath($root)) {
            // Installing the bundle root into itself is not an install.
            self::bundlesLine(Lang::t('cli.bundles.install.not_a_source', ['source' => $source]));

            return NonInteractive::EXIT_FAILURE;
        }

        if (\preg_match('/^([A-Za-z0-9._-]+)@([A-Za-z0-9._-]+)$/', $source, $at) === 1) {
            // C-EXT-4: one safe segment, an at-sign, one more — that is the
            // whole `plugin@marketplace` shape. A scp-style git URL (the
            // only other string carrying an at-sign that `install` ever
            // sees) has a colon and a slash in its tail, so it cannot
            // collide with this grammar and still falls to the clone arm.
            return self::bundlesInstallFromMarketplace($args, $at[1], $at[2], $root);
        }

        $scratch = null;
        if (is_dir($source)) {
            $scratch = $root . '/.install-' . \uniqid('', true);
            $stage = self::copyBundleTree($source, $scratch);
            if ($stage['ok'] !== true) {
                self::bundlesLine($stage['link'] === ''
                    ? Lang::t('cli.bundles.install.read_failed', ['source' => $source])
                    : Lang::t('cli.bundles.install.link_refused', ['source' => $source, 'link' => $stage['link']]));
                self::removeTree($scratch);

                return NonInteractive::EXIT_FAILURE;
            }
            $staged = $scratch;
        } elseif (self::looksLikeGitUrl($source)) {
            if (ProcessContainment::locateOnPath('git') === '') {
                self::bundlesLine(Lang::t('cli.bundles.install.missing_git', ['source' => $source]));

                return NonInteractive::EXIT_FAILURE;
            }

            $scratch = $root . '/.install-' . \uniqid('', true);
            $spawn = self::$bundlesInstallSpawn ?? self::bundlesCloneDefault();
            $run = $spawn($source, $scratch);
            if ($run['ok'] !== true || !is_dir($scratch)) {
                self::bundlesLine(Lang::t('cli.bundles.install.clone_failed', ['source' => $source, 'detail' => $run['detail']]));
                self::removeTree($scratch);

                return NonInteractive::EXIT_FAILURE;
            }
            $staged = $scratch;
        } else {
            self::bundlesLine(Lang::t('cli.bundles.install.not_a_source', ['source' => $source]));

            return NonInteractive::EXIT_FAILURE;
        }

        if (!Bundle::hasManifest($staged)) {
            self::bundlesLine(Lang::t('cli.bundles.install.not_bundle', ['source' => $source]));
            self::removeTree($scratch);

            return NonInteractive::EXIT_FAILURE;
        }

        // C-EXT-7: the clone arm received a `.git` the path arm's copy never
        // carried. Shed it with the no-follow walk the failure exits use,
        // before the rename, so whichever arm staged the tree, the install
        // lands content-only. On the path arm this is a no-op by shape —
        // copyBundleTree skipped every `.git` entry on the way in.
        self::removeTree($staged . '/.git');

        $manifest = Bundle::readManifest($staged);
        $name = (string) ($manifest['name'] ?? '') !== ''
            ? (string) $manifest['name']
            : \basename(\rtrim(\str_replace(['\\', '/'], '/', $source), '/'));
        if (\preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || $name === '.' || $name === '..') {
            self::bundlesLine(Lang::t('cli.bundles.install.invalid_name', ['name' => $name]));
            self::removeTree($scratch);

            return NonInteractive::EXIT_FAILURE;
        }
        if (\str_starts_with($name, '.')) {
            // C-EXT-7: the segment regex above admits a leading dot, but
            // BundleDiscovery's container glob never matches one, so such an
            // install would sit in the root invisible to every later load.
            // Refuse it instead of writing a bundle that cannot arrive.
            self::bundlesLine(Lang::t('cli.bundles.install.dot_name', ['name' => $name]));
            self::removeTree($scratch);

            return NonInteractive::EXIT_FAILURE;
        }

        $dest = $root . '/' . $name;
        if (\file_exists($dest)) {
            self::bundlesLine(Lang::t('cli.bundles.install.exists', ['name' => $name, 'dest' => $dest]));
            self::removeTree($scratch);

            return NonInteractive::EXIT_FAILURE;
        }

        if (!@\rename($staged, $dest)) {
            // Cross-device or non-directory root: fall back to the same
            // copy used for path sources, then drop the scratch.
            $fallback = self::copyBundleTree($staged, $dest);
            if ($fallback['ok'] !== true) {
                self::bundlesLine($fallback['link'] === ''
                    ? Lang::t('cli.bundles.install.read_failed', ['source' => $source])
                    : Lang::t('cli.bundles.install.link_refused', ['source' => $source, 'link' => $fallback['link']]));
                self::removeTree($dest);
                self::removeTree($scratch);

                return NonInteractive::EXIT_FAILURE;
            }
            self::removeTree($scratch);
        }

        echo Lang::t('cli.bundles.installed', ['name' => $name, 'dest' => $dest]) . "\n";

        return NonInteractive::EXIT_OK;
    }

    /**
     * The shared usage door for the C-EXT-4 verb family: the prefixed
     * refusal, the format-aware exit, and the current usage line — one
     * envelope literal so the stderr census counts ONE new emitter shape
     * for the whole family.
     */
    private static function bundlesUsage(ParsedArgs $args, string $key, array $params = []): int
    {
        return NonInteractive::failUsage(
            'candy-code: ' . Lang::t($key, $params),
            $args->outputFormat,
            Lang::t('cli.bundles.usage'),
        );
    }

    /**
     * `bundles marketplace <verb>` dispatcher.
     */
    private static function bundlesMarketplace(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[1] ?? null;

        return match ($verb) {
            'add' => self::bundlesMarketplaceAdd($args),
            'list' => self::bundlesMarketplaceList($args),
            'remove' => self::bundlesMarketplaceRemove($args),
            'update' => self::bundlesMarketplaceUpdate($args),
            default => self::bundlesUsage($args, $verb === null
                ? 'cli.bundles.marketplace.needs_action'
                : 'cli.bundles.marketplace.unknown_action', $verb === null ? [] : ['action' => $verb]),
        };
    }

    /**
     * `bundles marketplace add <git-url|path>` — take a marketplace
     * checkout (clone a repo into a scratch under the bundle root, or use
     * the operator's directory as given), require it to READ as a
     * marketplace (C-EXT-4.a's parser is the validator — a catalog that
     * does not parse was never offered), pin the 40-hex commit it stands at
     * (no pin, no add: the install gate refuses an unpinned row anyway, so
     * accepting one here would only be slower lying), register the row, and
     * for a remote arm shed its `.git` and land the content in the private
     * checkout directory the registry made at add time. A path row is the
     * operator's explicit word — the checkout IS the directory, copied
     * nowhere and deleted never.
     */
    private static function bundlesMarketplaceAdd(ParsedArgs $args): int
    {
        $source = $args->subcommandArgs[2] ?? null;
        if ($source === null || $source === '') {
            return self::bundlesUsage($args, 'cli.bundles.marketplace.needs_source');
        }

        $home = HomeDirectory::owned();
        if ($home === null) {
            self::bundlesLine(Lang::t('cli.bundles.install.no_home'));

            return NonInteractive::EXIT_FAILURE;
        }

        $root = $home . '/.candy-code/bundles';
        if (!is_dir($root) && !@\mkdir($root, 0700, true) && !is_dir($root)) {
            self::bundlesLine(Lang::t('cli.bundles.install.no_home'));

            return NonInteractive::EXIT_FAILURE;
        }

        $scratch = null;

        if (is_dir($source)) {
            $sha = self::bundlesGitSha($source);
            $marketplace = $sha === '' ? null : Marketplace::read($source);

            if ($marketplace === null) {
                self::bundlesLine($sha === ''
                    ? Lang::t('cli.bundles.marketplace.no_pin', ['source' => $source])
                    : Lang::t('cli.bundles.marketplace.not_marketplace', ['source' => $source]));

                return NonInteractive::EXIT_FAILURE;
            }

            $sourceType = MarketplaceEntry::SOURCE_PATH;
            $name = $marketplace->name;
        } elseif (self::looksLikeGitUrl($source)) {
            if (ProcessContainment::locateOnPath('git') === '') {
                self::bundlesLine(Lang::t('cli.bundles.install.missing_git', ['source' => $source]));

                return NonInteractive::EXIT_FAILURE;
            }

            $scratch = $root . '/.mkt-' . \uniqid('', true);
            $spawn = self::$bundlesInstallSpawn ?? self::bundlesCloneDefault();
            $run = $spawn($source, $scratch);

            if ($run['ok'] !== true || !is_dir($scratch)) {
                self::bundlesLine(Lang::t('cli.bundles.install.clone_failed', ['source' => $source, 'detail' => $run['detail']]));
                self::removeTree($scratch);

                return NonInteractive::EXIT_FAILURE;
            }

            $sha = self::bundlesGitSha($scratch);
            $marketplace = $sha === '' ? null : Marketplace::read($scratch);

            if ($marketplace === null) {
                self::bundlesLine($sha === ''
                    ? Lang::t('cli.bundles.marketplace.no_pin', ['source' => $source])
                    : Lang::t('cli.bundles.marketplace.not_marketplace', ['source' => $source]));
                self::removeTree($scratch);

                return NonInteractive::EXIT_FAILURE;
            }

            $sourceType = \str_starts_with($source, 'https://')
                ? MarketplaceEntry::SOURCE_URL
                : MarketplaceEntry::SOURCE_GIT;
            $name = $marketplace->name;
        } else {
            self::bundlesLine(Lang::t('cli.bundles.install.not_a_source', ['source' => $source]));

            return NonInteractive::EXIT_FAILURE;
        }

        $registry = MarketplaceRegistry::loadDefault();

        if ($registry->has($name)) {
            self::bundlesLine(Lang::t('cli.bundles.marketplace.exists', ['name' => $name]));

            if ($scratch !== null) {
                self::removeTree($scratch);
            }

            return NonInteractive::EXIT_FAILURE;
        }

        $refusal = $registry->add($name, $sourceType, $source, $sha);

        if ($refusal !== null) {
            self::bundlesLine($refusal);

            if ($scratch !== null) {
                self::removeTree($scratch);
            }

            return NonInteractive::EXIT_FAILURE;
        }

        if ($sourceType !== MarketplaceEntry::SOURCE_PATH) {
            self::removeTree($scratch . '/.git');
            $dest = $registry->checkoutDir($name);

            if ($dest === null) {
                // add() vouched the checkout directory and it is gone: the
                // row cannot serve installs from a path this process will
                // not name. Roll the registration back rather than leave a
                // marketplace that reads as added but never opens.
                self::bundlesLine(Lang::t('cli.bundles.marketplace.checkout_lost', ['name' => $name]));
                $registry->remove($name);
                self::removeTree($scratch);

                return NonInteractive::EXIT_FAILURE;
            }

            if (!@\rename($scratch, $dest)) {
                $fallback = self::copyBundleTree($scratch, $dest);

                if ($fallback['ok'] !== true) {
                    self::bundlesLine($fallback['link'] === ''
                        ? Lang::t('cli.bundles.install.read_failed', ['source' => $source])
                        : Lang::t('cli.bundles.install.link_refused', ['source' => $source, 'link' => $fallback['link']]));
                    $registry->remove($name);
                    self::removeTree($dest);
                    self::removeTree($scratch);

                    return NonInteractive::EXIT_FAILURE;
                }

                self::removeTree($scratch);
            }
        }

        echo Lang::t('cli.bundles.marketplace.added', [
            'name' => $name,
            'sha' => $sha,
            'plugins' => (string) \count($marketplace->entries),
        ]) . "\n";

        return NonInteractive::EXIT_OK;
    }

    /**
     * `bundles marketplace list` — the registration file, one row per
     * marketplace, in registration order. Reads what the registry holds;
     * catalogs are NOT opened here, so the listing answers even when one
     * checkout went unreadable (a `marketplace update` or re-add is the
     * repair, and `install` is the surface that refuses per entry).
     */
    private static function bundlesMarketplaceList(ParsedArgs $args): int
    {
        $registry = MarketplaceRegistry::loadDefault();

        foreach ($registry->loadErrors() as $error) {
            self::bundlesLine($error);
        }

        $names = $registry->names();

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            $rows = [];

            foreach ($names as $name) {
                $row = $registry->record($name);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            echo \json_encode(
                ['marketplaces' => $rows],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ) . "\n";

            return NonInteractive::EXIT_OK;
        }

        if ($names === []) {
            echo Lang::t('cli.bundles.marketplace.none') . "\n";

            return NonInteractive::EXIT_OK;
        }

        echo Lang::t('cli.bundles.marketplace.list_header') . "\n";

        foreach ($names as $name) {
            $row = $registry->record($name) ?? [];
            \printf(
                "%-18s %-8s %-12s %s\n",
                $name,
                (string) ($row['sourceType'] ?? '-'),
                \substr((string) ($row['sha'] ?? '-'), 0, 12),
                (string) ($row['source'] ?? '-'),
            );
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `bundles marketplace remove <name>` — retire the registration. The
     * checkout directory stays (deleting is not this verb's job, and an
     * installed bundle's recorded source still names it); what leaves is
     * the registry row, so the refuse-by-default gate starts answering for
     * every entry that marketplace offered.
     */
    private static function bundlesMarketplaceRemove(ParsedArgs $args): int
    {
        $name = $args->subcommandArgs[2] ?? null;
        if ($name === null || $name === '') {
            return self::bundlesUsage($args, 'cli.bundles.marketplace.needs_name');
        }

        $refusal = MarketplaceRegistry::loadDefault()->remove($name);

        if ($refusal !== null) {
            self::bundlesLine($refusal);

            return NonInteractive::EXIT_FAILURE;
        }

        echo Lang::t('cli.bundles.marketplace.removed', ['name' => $name]) . "\n";

        return NonInteractive::EXIT_OK;
    }

    /**
     * `bundles marketplace update [<name>]` — re-clone each remote row's
     * source, diff the catalog against what the old checkout offered, swap
     * the checkout (aside-first, same-directory renames only, the old tree
     * removed by the no-follow walk only after the new one landed), and
     * move the pin to the new commit. A path row is skipped loudly: its
     * "update" is whatever the operator's directory already is. The pin
     * never moves to a commit whose catalog fails to parse, and a catalog
     * that renamed itself is refused — the row's name is the identity every
     * `BundleSource` record keys on.
     */
    private static function bundlesMarketplaceUpdate(ParsedArgs $args): int
    {
        $wanted = $args->subcommandArgs[2] ?? null;
        $registry = MarketplaceRegistry::loadDefault();

        foreach ($registry->loadErrors() as $error) {
            self::bundlesLine($error);
        }

        if ($wanted !== null && $wanted !== '') {
            if (!$registry->has($wanted)) {
                self::bundlesLine(Lang::t('cli.bundles.marketplace.not_added', ['name' => $wanted]));

                return NonInteractive::EXIT_FAILURE;
            }

            $names = [$wanted];
        } else {
            $names = $registry->names();
        }

        $status = NonInteractive::EXIT_OK;

        foreach ($names as $name) {
            $row = $registry->record($name);

            if ($row === null) {
                continue;
            }

            if ($row['sourceType'] === MarketplaceEntry::SOURCE_PATH) {
                self::bundlesLine(Lang::t('cli.bundles.marketplace.update_path_skipped', ['name' => $name]));

                continue;
            }

            $checkout = $registry->checkoutDir($name);

            if ($checkout === null) {
                self::bundlesLine(Lang::t('cli.bundles.marketplace.checkout_lost', ['name' => $name]));
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            if (ProcessContainment::locateOnPath('git') === '') {
                self::bundlesLine(Lang::t('cli.bundles.install.missing_git', ['source' => (string) $row['source']]));
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            // Scratch beside the checkout it will replace: the swap is then
            // two same-directory renames, the atomic shape the risk line in
            // this step's design demanded (never a cross-device copy of a
            // half-live tree).
            $scratch = \dirname($checkout) . '/.mkt-' . \uniqid('', true);
            $spawn = self::$bundlesInstallSpawn ?? self::bundlesCloneDefault();
            $run = $spawn((string) $row['source'], $scratch);

            if ($run['ok'] !== true || !is_dir($scratch)) {
                self::bundlesLine(Lang::t('cli.bundles.install.clone_failed', ['source' => (string) $row['source'], 'detail' => $run['detail']]));
                self::removeTree($scratch);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            $sha = self::bundlesGitSha($scratch);
            $marketplace = $sha === '' ? null : Marketplace::read($scratch);

            if ($marketplace === null) {
                self::bundlesLine($sha === ''
                    ? Lang::t('cli.bundles.marketplace.no_pin', ['source' => (string) $row['source']])
                    : Lang::t('cli.bundles.marketplace.not_marketplace', ['source' => (string) $row['source']]));
                self::removeTree($scratch);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            if ($marketplace->name !== $name) {
                self::bundlesLine(Lang::t('cli.bundles.marketplace.renamed', ['from' => $name, 'to' => $marketplace->name]));
                self::removeTree($scratch);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            $old = $registry->marketplace($name);
            $oldNames = [];

            foreach ($old === null ? [] : $old->entries as $entry) {
                $oldNames[$entry->name] = true;
            }

            $newNames = [];

            foreach ($marketplace->entries as $entry) {
                $newNames[$entry->name] = true;
            }

            foreach (\array_keys(\array_diff_key($newNames, $oldNames)) as $arrived) {
                echo Lang::t('cli.bundles.marketplace.entry_added', ['plugin' => (string) $arrived]) . "\n";
            }

            foreach (\array_keys(\array_diff_key($oldNames, $newNames)) as $gone) {
                echo Lang::t('cli.bundles.marketplace.entry_removed', ['plugin' => (string) $gone]) . "\n";
            }

            self::removeTree($scratch . '/.git');

            $aside = \dirname($checkout) . '/.swap-' . \uniqid('', true);

            if (is_dir($checkout) && !@\rename($checkout, $aside)) {
                self::bundlesLine(Lang::t('cli.bundles.update.swap_failed', ['name' => $name]));
                self::removeTree($scratch);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            if (!@\rename($scratch, $checkout)) {
                if (is_dir($aside)) {
                    @\rename($aside, $checkout); // the old checkout comes back; a marketplace is never left absent
                }

                self::bundlesLine(Lang::t('cli.bundles.update.swap_failed', ['name' => $name]));
                self::removeTree($scratch);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            if (is_dir($aside)) {
                self::removeTree($aside);
            }

            $refusal = $registry->repin($name, $sha);

            if ($refusal !== null) {
                // Loud by shape: the tree moved and the pin did not, so the
                // operator's registry file still names the old commit.
                self::bundlesLine($refusal);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            echo Lang::t('cli.bundles.marketplace.updated', ['name' => $name, 'sha' => $sha]) . "\n";
        }

        return $status;
    }

    /**
     * The full commit sha a checkout points at, read from git's own
     * reference files — no child process, no PATH dependence. `.git/HEAD`
     * either IS the sha (detached checkout), names a `refs/...` legible in
     * the loose ref file, or falls to a packed-refs line. The ref name is
     * regex-bound to `refs/` before it is ever concatenated into a path, so
     * a crafted HEAD cannot aim this read outside `.git/refs`. Anything
     * other than one full 40-hex sha answers '' — the marketplace pin floor
     * has no partial shape.
     */
    private static function bundlesGitSha(string $dir): string
    {
        $head = @\file_get_contents($dir . '/.git/HEAD');

        if (!\is_string($head)) {
            return '';
        }

        $head = \trim($head);

        if (\preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
            return $head;
        }

        if (\preg_match('#^ref: (refs/[A-Za-z0-9._/-]+)$#', $head, $ref) !== 1 || \str_contains($ref[1], '..')) {
            return '';
        }

        $loose = @\file_get_contents($dir . '/.git/' . $ref[1]);

        if (\is_string($loose) && \preg_match('/^[0-9a-f]{40}$/', \trim($loose)) === 1) {
            return \trim($loose);
        }

        $packed = @\file_get_contents($dir . '/.git/packed-refs');

        if (!\is_string($packed)) {
            return '';
        }

        $needle = ' ' . $ref[1];

        foreach (\explode("\n", $packed) as $line) {
            $line = \trim($line);

            if (\str_ends_with($line, $needle)) {
                $sha = \substr($line, 0, -\strlen($needle));

                if (\preg_match('/^[0-9a-f]{40}$/', $sha) === 1) {
                    return $sha;
                }
            }
        }

        return '';
    }

    /**
     * The installed bundle names in the user bundle root: one scandir of
     * NAMES only (no read of any bundle's content), dot entries excluded —
     * a leading dot is exactly what `BundleDiscovery`'s glob never matches,
     * and it is what the scratch and swap directories this verb family
     * parks in the root are made of, so neither update nor recommend can
     * ever see one as a bundle.
     *
     * @return list<string>
     */
    private static function bundlesBundleRootNames(string $root): array
    {
        $entries = @\scandir($root);

        if ($entries === false) {
            return [];
        }

        $names = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || \str_starts_with($entry, '.')) {
                continue;
            }

            if (is_dir($root . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * `bundles install <plugin>@<marketplace>` — the gated path from the
     * C-EXT-4.a contract: the marketplace must be ADDED, its row must carry
     * the pin, and the entry must be IN the catalog (the gate is
     * {@see MarketplaceRegistry::installGate()}, refused-by-default, and
     * this arm prints its sentence rather than inventing a softer one).
     * The materialized tree goes through the same manifest/name/exists
     * doors as every other install; on landing, a {@see BundleSource}
     * record is written INTO the bundle so `bundles update` can find its
     * provenance later. A record that cannot be written rolls the install
     * back — an unrecorded bundle is a bundle update will never reach.
     */
    private static function bundlesInstallFromMarketplace(ParsedArgs $args, string $entryName, string $marketplaceName, string $root): int
    {
        $registry = MarketplaceRegistry::loadDefault();
        $marketplace = $registry->marketplace($marketplaceName);

        if ($marketplace === null) {
            self::bundlesLine(Lang::t('cli.bundles.marketplace.not_added', ['name' => $marketplaceName]));

            return NonInteractive::EXIT_FAILURE;
        }

        $gate = $registry->installGate($marketplace, $entryName);

        if ($gate !== null) {
            self::bundlesLine($gate);

            return NonInteractive::EXIT_FAILURE;
        }

        $entry = $registry->installEntry($marketplace, $entryName);

        if ($entry === null) {
            // Gate passed and the entry still is not there: the catalog
            // moved between the two reads. Refuse; do not chase.
            self::bundlesLine(Lang::t('cli.bundles.install.not_a_source', ['source' => $entryName . '@' . $marketplaceName]));

            return NonInteractive::EXIT_FAILURE;
        }

        $staged = self::bundlesStageEntry($entry, $root, $marketplaceName);

        if ($staged === null) {
            return NonInteractive::EXIT_FAILURE; // bundlesStageEntry printed its line
        }

        if (!Bundle::hasManifest($staged)) {
            self::bundlesLine(Lang::t('cli.bundles.install.not_bundle', ['source' => $entryName . '@' . $marketplaceName]));
            self::removeTree($staged);

            return NonInteractive::EXIT_FAILURE;
        }

        $manifest = Bundle::readManifest($staged);
        $name = (string) ($manifest['name'] ?? '') !== '' ? (string) $manifest['name'] : $entry->name;

        if (\preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || $name === '.' || $name === '..') {
            self::bundlesLine(Lang::t('cli.bundles.install.invalid_name', ['name' => $name]));
            self::removeTree($staged);

            return NonInteractive::EXIT_FAILURE;
        }

        if (\str_starts_with($name, '.')) {
            self::bundlesLine(Lang::t('cli.bundles.install.dot_name', ['name' => $name]));
            self::removeTree($staged);

            return NonInteractive::EXIT_FAILURE;
        }

        $dest = $root . '/' . $name;

        if (\file_exists($dest)) {
            self::bundlesLine(Lang::t('cli.bundles.install.exists', ['name' => $name, 'dest' => $dest]));
            self::removeTree($staged);

            return NonInteractive::EXIT_FAILURE;
        }

        if (!@\rename($staged, $dest)) {
            $fallback = self::copyBundleTree($staged, $dest);

            if ($fallback['ok'] !== true) {
                self::bundlesLine($fallback['link'] === ''
                    ? Lang::t('cli.bundles.install.read_failed', ['source' => $entryName . '@' . $marketplaceName])
                    : Lang::t('cli.bundles.install.link_refused', ['source' => $entryName . '@' . $marketplaceName, 'link' => $fallback['link']]));
                self::removeTree($dest);
                self::removeTree($staged);

                return NonInteractive::EXIT_FAILURE;
            }

            self::removeTree($staged);
        }

        $recordRefusal = BundleSource::fromEntry($entry, $marketplaceName)->write($dest);

        if ($recordRefusal !== null) {
            self::bundlesLine($recordRefusal);
            self::removeTree($dest);

            return NonInteractive::EXIT_FAILURE;
        }

        echo Lang::t('cli.bundles.installed', ['name' => $name, 'dest' => $dest]) . "\n";
        echo Lang::t('cli.bundles.install.via_marketplace', [
            'name' => $name,
            'marketplace' => $marketplaceName,
            'sha' => $entry->sha,
        ]) . "\n";

        return NonInteractive::EXIT_OK;
    }

    /**
     * Materialize one catalog entry into a fresh scratch under the bundle
     * root. A path row copies through C-SEC-1's hardened walker (the link
     * refusal rides with it; the source itself was already containment-
     * resolved inside the checkout by C-EXT-4.a's parser); a remote row
     * clones through the SAME spawn seam `install` uses — there is no
     * second clone site and no new child. The clone sheds its `.git`
     * before landing. Returns the staged directory, which the CALLER owns
     * (rename into place or remove), or null after printing the refusal.
     */
    private static function bundlesStageEntry(MarketplaceEntry $entry, string $root, string $marketplaceName): ?string
    {
        $label = $entry->name . '@' . $marketplaceName;

        if ($entry->sourceType === MarketplaceEntry::SOURCE_PATH) {
            $scratch = $root . '/.install-' . \uniqid('', true);
            $stage = self::copyBundleTree($entry->path, $scratch);

            if ($stage['ok'] !== true) {
                self::bundlesLine($stage['link'] === ''
                    ? Lang::t('cli.bundles.install.read_failed', ['source' => $label])
                    : Lang::t('cli.bundles.install.link_refused', ['source' => $label, 'link' => $stage['link']]));
                self::removeTree($scratch);

                return null;
            }

            return $scratch;
        }

        if (ProcessContainment::locateOnPath('git') === '') {
            self::bundlesLine(Lang::t('cli.bundles.install.missing_git', ['source' => $label]));

            return null;
        }

        $url = $entry->sourceType === MarketplaceEntry::SOURCE_GITHUB
            ? 'https://github.com/' . $entry->repo . '.git'
            : $entry->url;

        $scratch = $root . '/.install-' . \uniqid('', true);
        $spawn = self::$bundlesInstallSpawn ?? self::bundlesCloneDefault();
        $run = $spawn($url, $scratch);

        if ($run['ok'] !== true || !is_dir($scratch)) {
            self::bundlesLine(Lang::t('cli.bundles.install.clone_failed', ['source' => $label, 'detail' => $run['detail']]));
            self::removeTree($scratch);

            return null;
        }

        self::removeTree($scratch . '/.git');

        return $scratch;
    }

    /**
     * `bundles update [<bundle>]` — re-materialize every installed bundle
     * that carries a {@see BundleSource} record from its recorded
     * marketplace's CURRENT pinned entry, and swap the new tree in:
     * old directory aside first (same-directory rename), staged directory
     * landed, provenance record re-written, only THEN the aside removed by
     * the no-follow walk. A landing that fails mid-way restores the aside:
     * an update never leaves a bundle absent. A bundle with no record, or
     * one whose marketplace is gone, is refused per name when named and
     * skipped with a line when the root is scanned — update never invents
     * a source for what install did not record.
     */
    private static function bundlesUpdate(ParsedArgs $args): int
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            self::bundlesLine(Lang::t('cli.bundles.install.no_home'));

            return NonInteractive::EXIT_FAILURE;
        }

        $root = $home . '/.candy-code/bundles';

        if (!is_dir($root)) {
            echo Lang::t('cli.bundles.update.nothing') . "\n";

            return NonInteractive::EXIT_OK;
        }

        $wanted = $args->subcommandArgs[1] ?? null;
        $named = $wanted !== null && $wanted !== '';

        if ($named) {
            if (!is_dir($root . '/' . $wanted)) {
                self::bundlesLine(Lang::t('cli.bundles.update.unknown', ['name' => (string) $wanted]));

                return NonInteractive::EXIT_FAILURE;
            }

            $names = [(string) $wanted];
        } else {
            $names = self::bundlesBundleRootNames($root);
        }

        $registry = MarketplaceRegistry::loadDefault();
        $status = NonInteractive::EXIT_OK;

        foreach ($names as $name) {
            $dir = $root . '/' . $name;
            $record = BundleSource::read($dir, $root);

            if ($record === null) {
                self::bundlesLine(Lang::t('cli.bundles.update.no_record', ['name' => $name]));

                if ($named) {
                    $status = NonInteractive::EXIT_FAILURE;
                }

                continue;
            }

            $marketplace = $registry->marketplace($record->marketplace);

            if ($marketplace === null) {
                self::bundlesLine(Lang::t('cli.bundles.update.marketplace_gone', [
                    'name' => $name,
                    'marketplace' => $record->marketplace,
                ]));

                if ($named) {
                    $status = NonInteractive::EXIT_FAILURE;
                }

                continue;
            }

            $gate = $registry->installGate($marketplace, $record->name);

            if ($gate !== null) {
                self::bundlesLine($gate);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            $fresh = $registry->installEntry($marketplace, $record->name);

            if ($fresh === null) {
                self::bundlesLine(Lang::t('cli.bundles.install.not_a_source', ['source' => $record->name . '@' . $record->marketplace]));
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            $staged = self::bundlesStageEntry($fresh, $root, $record->marketplace);

            if ($staged === null) {
                $status = NonInteractive::EXIT_FAILURE; // bundlesStageEntry printed its line

                continue;
            }

            if (!Bundle::hasManifest($staged)) {
                self::bundlesLine(Lang::t('cli.bundles.install.not_bundle', ['source' => $name]));
                self::removeTree($staged);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            $manifest = Bundle::readManifest($staged);
            $newName = (string) ($manifest['name'] ?? '');

            if ($newName !== '' && $newName !== $name) {
                // The upstream renamed itself. Re-keying the install would
                // strand every setting that names the old bundle, so the
                // update stops at the door, staged tree discarded.
                self::bundlesLine(Lang::t('cli.bundles.update.renamed', ['from' => $name, 'to' => $newName]));
                self::removeTree($staged);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            $aside = $root . '/.swap-' . \uniqid('', true);

            if (!@\rename($dir, $aside)) {
                self::bundlesLine(Lang::t('cli.bundles.update.swap_failed', ['name' => $name]));
                self::removeTree($staged);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            if (!@\rename($staged, $dir)) {
                @\rename($aside, $dir); // the old tree comes back; a bundle is never left absent

                self::bundlesLine(Lang::t('cli.bundles.update.swap_failed', ['name' => $name]));
                self::removeTree($staged);
                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            $write = BundleSource::fromEntry($fresh, $record->marketplace)->write($dir);

            if ($write !== null) {
                self::bundlesLine($write);
                self::removeTree($dir);

                if (!@\rename($aside, $dir)) {
                    // The only exit that can say less than it means: the
                    // fresh tree is gone and the old one did not return.
                    self::bundlesLine(Lang::t('cli.bundles.update.swap_failed', ['name' => $name]));
                }

                $status = NonInteractive::EXIT_FAILURE;

                continue;
            }

            self::removeTree($aside); // the old copy leaves LAST, no-follow walk

            echo Lang::t('cli.bundles.update.done', ['name' => $name, 'sha' => $fresh->sha]) . "\n";
        }

        return $status;
    }

    /**
     * `bundles search <q>` — one case-insensitive substring over name,
     * source key, description, category and keywords of every entry of
     * every ADDED marketplace. An empty catalog answers nothing, which is
     * the same law as everywhere else in this family: search never dials a
     * remote, so what you cannot have added you cannot find here.
     */
    private static function bundlesSearch(ParsedArgs $args): int
    {
        $query = $args->subcommandArgs[1] ?? null;

        if ($query === null || $query === '') {
            return self::bundlesUsage($args, 'cli.bundles.search.needs_query');
        }

        $needle = \strtolower($query);
        $registry = MarketplaceRegistry::loadDefault();

        foreach ($registry->loadErrors() as $error) {
            self::bundlesLine($error);
        }

        $results = [];

        foreach ($registry->names() as $marketplaceName) {
            $marketplace = $registry->marketplace($marketplaceName);

            if ($marketplace === null) {
                continue;
            }

            foreach ($marketplace->entries as $entry) {
                $haystack = \strtolower(\implode(' ', [
                    $entry->name,
                    $entry->sourceKey(),
                    $entry->description,
                    $entry->category,
                    \implode(' ', $entry->keywords),
                ]));

                if (\str_contains($haystack, $needle)) {
                    $results[] = [
                        'marketplace' => $marketplaceName,
                        'name' => $entry->name,
                        'source' => $entry->sourceKey(),
                        'version' => $entry->version,
                        'description' => $entry->description,
                    ];
                }
            }
        }

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            echo \json_encode(
                ['query' => $query, 'results' => $results],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ) . "\n";

            return NonInteractive::EXIT_OK;
        }

        if ($results === []) {
            echo Lang::t('cli.bundles.search.none', ['query' => $query]) . "\n";

            return NonInteractive::EXIT_OK;
        }

        foreach ($results as $row) {
            \printf(
                "%-18s %-18s %-9s %s\n",
                $row['name'],
                $row['marketplace'],
                $row['version'] === '' ? '-' : $row['version'],
                $row['description'] === '' ? '-' : $row['description'],
            );
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `bundles recommend` — C-EXT-4.a's scorer over the joined catalog of
     * every ADDED marketplace, minus what the user already has installed,
     * for the project root this process was pointed at. Reads only: the
     * answer is a suggestion line, and the install is the verb that writes.
     */
    private static function bundlesRecommend(ParsedArgs $args): int
    {
        $root = (string) ($args->root ?? '') !== '' ? (string) $args->root : (string) \getcwd();
        $registry = MarketplaceRegistry::loadDefault();

        foreach ($registry->loadErrors() as $error) {
            self::bundlesLine($error);
        }

        $catalog = [];
        $offeredBy = [];

        foreach ($registry->names() as $marketplaceName) {
            $marketplace = $registry->marketplace($marketplaceName);

            if ($marketplace === null) {
                continue;
            }

            foreach ($marketplace->entries as $entry) {
                if (isset($offeredBy[$entry->name])) {
                    continue; // first offering wins, registration order
                }

                $offeredBy[$entry->name] = $marketplaceName;
                $catalog[] = $entry;
            }
        }

        $home = HomeDirectory::owned();
        $installed = $home === null ? [] : self::bundlesBundleRootNames($home . '/.candy-code/bundles');

        $recommended = (new BundleRecommender($root, $catalog, $installed))->recommend();

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            $rows = [];

            foreach ($recommended as $entry) {
                $rows[] = [
                    'name' => $entry->name,
                    'marketplace' => $offeredBy[$entry->name] ?? '',
                    'category' => $entry->category,
                    'keywords' => $entry->keywords,
                ];
            }

            echo \json_encode(
                ['project_root' => $root, 'recommendations' => $rows],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ) . "\n";

            return NonInteractive::EXIT_OK;
        }

        if ($recommended === []) {
            echo Lang::t('cli.bundles.recommend.none') . "\n";

            return NonInteractive::EXIT_OK;
        }

        foreach ($recommended as $entry) {
            \printf(
                "%-18s %-18s %-9s %s\n",
                $entry->name,
                $offeredBy[$entry->name] ?? '-',
                $entry->category === '' ? '-' : $entry->category,
                $entry->description === '' ? '-' : $entry->description,
            );
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * The default clone: argv-array `git -c protocol.ext.allow=never -c
     * core.symlinks=false clone --quiet --depth 1 --no-tags` (no shell: URL
     * quoting is argv's job, and `--` ends option parsing so a URL beginning
     * with `-` stays a URL), wrapped by {@see ProcessContainment::spawnSpec()}
     * so the clone leads its own session and dies in its own group.
     *
     * INT-M1: the first `-c` leg IS the transport door, and it is part of the
     * argv on purpose. {@see self::looksLikeGitUrl()} admits any string ending
     * in `.git`, so the shape `ext::sh -c …x.git` reaches this spawn as what
     * git parses as a remote helper URL. Modern git refuses the ext transport
     * for user-initiated fetches by default, but a command-line `-c` outranks
     * both an inherited `GIT_ALLOW_PROTOCOL` and any older git's defaults —
     * the refusal then belongs to THIS spawn, not to the version and not to
     * the operator's environment.
     *
     * PLG-1: the second `-c` leg is the symlink belt. `core.symlinks=false`
     * makes git check symbolic links out as plain working-tree files instead
     * of live links, so the scratch can never hold a link for the cleanup
     * walk to follow or for a later load to read through. The cleanup walk
     * refuses to follow links regardless ({@see self::removeTree()}); this
     * belt just keeps the honest shape out of the tree in the first place.
     *
     * @return array{ok: bool, detail: string}
     */
    private static function bundlesCloneDefault(): \Closure
    {
        return static function (string $url, string $scratch): array {
            // `@`: with no wrapper a failed exec raises E_WARNING from
            // proc_open() itself; the answer below is by return value.
            $process = @\proc_open(
                ProcessContainment::spawnSpec(['git', '-c', 'protocol.ext.allow=never', '-c', 'core.symlinks=false', 'clone', '--quiet', '--depth', '1', '--no-tags', '--', $url, $scratch]),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                self::bundlesChildEnv(),
            );

            if (!\is_resource($process)) {
                return ['ok' => false, 'detail' => 'git could not be launched'];
            }

            \fclose($pipes[0]);
            \stream_set_blocking($pipes[1], false);
            \stream_set_blocking($pipes[2], false);

            $out = '';
            $err = '';
            $open = [$pipes[1], $pipes[2]];
            $deadline = \hrtime(true) / 1_000_000_000 + self::BUNDLE_CLONE_TIMEOUT_SECONDS;
            $timedOut = false;

            while ($open !== []) {
                $now = \hrtime(true) / 1_000_000_000;
                if ($now >= $deadline) {
                    $timedOut = true;

                    break;
                }

                $read = $open;
                $write = null;
                $except = null;
                $slice = (int) \max(0, \min(0.05, $deadline - $now));
                if (\stream_select($read, $write, $except, $slice) === false) {
                    break; // signal-interrupted: treat as done reading
                }

                foreach ($read as $index => $stream) {
                    $chunk = \fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        if (\feof($stream)) {
                            \fclose($stream);
                            unset($open[$index]);
                        }

                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
            }

            foreach ($open as $stream) {
                \fclose($stream);
            }

            if ($timedOut) {
                BoundedShutdown::terminateAndAwaitExit($process, groupPid: ProcessContainment::groupId($process));
                \proc_close($process);

                return ['ok' => false, 'detail' => Lang::t('cli.bundles.install.clone_timeout', ['source' => $url, 'seconds' => '120'])];
            }

            // Poll-then-reap: the pipes have both reached EOF (or select
            // died); proc_close waits and carries the verdict.
            $code = \proc_close($process);
            if ($code !== 0) {
                $tail = \trim($err) !== '' ? \trim($err) : (\trim($out) !== '' ? \trim($out) : 'exit ' . $code);

                return ['ok' => false, 'detail' => $tail];
            }

            return ['ok' => true, 'detail' => ''];
        };
    }

    /**
     * The clone's environment: the contained base with the repo-local git
     * variables stripped and a HOME git can read its config from.
     *
     * @return array<string, string>
     */
    private static function bundlesChildEnv(): array
    {
        $env = ProcessContainment::env();
        foreach (self::BUNDLE_INSTALL_GIT_STRIP as $name) {
            unset($env[$name]);
        }

        // git needs HOME to read ~/.gitconfig at all; the clone never
        // writes there (auth prompts are refused by the environment, not
        // answered from it), so a world-writable stand-in is honest.
        if ((string) ($env['HOME'] ?? '') === '') {
            $env['HOME'] = '/tmp';
        }

        return $env;
    }

    /**
     * scp-style (`user@host:path`), scheme-style, or a bare `.git` path —
     * the shapes `git clone` accepts. Deliberately strict: anything else
     * that is not a directory is refused, because the alternative to a
     * refusal here is handing an arbitrary string to a network client.
     *
     * INT-M1: the bare-`.git` arm is a broad net — it would let a remote
     * helper URL like `ext::sh -c …x.git` through. That is survivable only
     * because the clone itself pins `-c protocol.ext.allow=never` on the
     * argv (see {@see self::bundlesCloneDefault()}), so the transport git
     * would use to obey such a URL is refused at spawn no matter how wide
     * this matcher casts. Tightening this predicate instead would reject
     * legitimate `…/whatever.git` paths, so the belt lives at the spawn.
     */
    private static function looksLikeGitUrl(string $source): bool
    {
        return \preg_match('#^(?:https?|ssh|git)://#i', $source) === 1
            || \preg_match('#^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:\S+$#', $source) === 1
            || \str_ends_with($source, '.git');
    }

    /**
     * Recursive copy that skips every `.git` directory: a bundle ships
     * content, not the vendor's repository history.
     *
     * PLG-1-P3: the walk refuses — naming the first link it meets — rather
     * than copying through one. `\is_dir()` and `\copy()` both FOLLOW a
     * symlink, so a permissive copy would read whatever the bundle points
     * at outside itself (an installed bundle is loaded as configuration,
     * so smuggling an escaping link in is a content-forgery channel) and a
     * self-referential link would send this recursion round in circles.
     * The loaders downstream already refuse escaping links at read time;
     * refusing here stops the escape at the door instead, and terminates
     * the cyclic shape by construction.
     *
     * @return array{ok: bool, link: string} `link` is the path of the
     *                                       refused symlink, or '' when
     *                                       the failure was an ordinary
     *                                       I/O one (or there was none)
     */
    private static function copyBundleTree(string $from, string $to): array
    {
        if (!@\mkdir($to, 0700, true) && !\is_dir($to)) {
            return ['ok' => false, 'link' => ''];
        }

        $entries = @\scandir($from);
        if ($entries === false) {
            return ['ok' => false, 'link' => ''];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($entry === '.git') {
                continue;
            }

            $src = $from . '/' . $entry;
            $dst = $to . '/' . $entry;

            if (\is_link($src)) {
                return ['ok' => false, 'link' => $src];
            }

            if (\is_dir($src)) {
                $sub = self::copyBundleTree($src, $dst);
                if ($sub['ok'] !== true) {
                    return $sub;
                }

                continue;
            }

            if (!@\copy($src, $dst)) {
                return ['ok' => false, 'link' => ''];
            }
        }

        return ['ok' => true, 'link' => ''];
    }

    /**
     * Best-effort scratch cleanup: this verb creates only inside its own
     * root, so removing is bounded by paths it minted.
     *
     * PLG-1-P1: the bound is a path-prefix bound, which `\is_dir()` alone
     * does not give — `is_dir()` FOLLOWS symlinks, so a scratch holding
     * `x -> ../../..` sent this walker through the door of whatever the
     * link resolved to, deleting it on the way back out. A real clone
     * failure with such a link in the scratch emptied the user's home
     * directory. Every link is therefore unlinked AS a link and never
     * descended, whatever it points at.
     */
    private static function removeTree(string $path): void
    {
        if (\is_link($path)) {
            @\unlink($path);

            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        $entries = @\scandir($path);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $child = $path . '/' . $entry;
                    if (\is_link($child)) {
                        @\unlink($child);

                        continue;
                    }

                    \is_dir($child) ? self::removeTree($child) : @\unlink($child);
                }
            }
        }

        @\rmdir($path);
    }

    /**
     * `candy-code mcp list` — what `.mcp.json` declares, and whether this
     * launch would start it —, since E701, `candy-code mcp auth login`,
     * the interactive OAuth flow handed off whole to {@see self::mcpAuth()}
     * at the verb gate below, and, since E710, `candy-code mcp import`,
     * the foreign-config translator that prints only by default — its sole
     * exception is the C-MCP-4.d `--write user` door — handed to
     * {@see self::mcpImport()}.
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
                    ? 'candy-code: ' . Lang::t('cli.mcp.no_action')
                    : 'candy-code: ' . Lang::t('cli.mcp.unknown_action', ['action' => $verb]),
                $args->outputFormat,
                Lang::t('cli.mcp.usage'),
            );
        }

        $inventory = Bootstrap::mcpServerInventory($args->root);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            if ($inventory['error'] !== null) {
                // THE SAME EXIT CODE THE TEXT ARM GIVES for the same install
                // state. Returning 0 here because "the question was answered"
                // made `candy-code mcp list --output-format json || fail` a CI
                // gate that never fires while `candy-code mcp list` next to it
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
                $server['wirePrefix'] = \SugarCraft\Code\Tools\McpToolBridge::wireServerPrefix($server['name']);
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
            case Bootstrap::MCP_DISABLED:
                // ISS-093(a): its own sentence, because the absent sentence
                // is a LIE here — the file may exist and name servers; the
                // launch switch does.
                echo Lang::t('cli.mcp.list.disabled', ['path' => $inventory['path']]) . "\n";

                return NonInteractive::EXIT_OK;

            case Bootstrap::MCP_ABSENT:
                echo Lang::t('cli.mcp.list.absent', ['file' => Bootstrap::MCP_CONFIG_FILENAME, 'path' => $inventory['path']]) . "\n";

                return NonInteractive::EXIT_OK;

            case Bootstrap::MCP_OUTSIDE_TREE:
                echo Lang::t('cli.mcp.list.outside', ['path' => $inventory['path']]) . "\n";

                return NonInteractive::EXIT_OK;

            case Bootstrap::MCP_UNTRUSTED:
                echo Lang::t('cli.mcp.list.untrusted', ['path' => $inventory['path'], 'config' => Bootstrap::userConfigPath()]) . "\n";

                return NonInteractive::EXIT_OK;
        }

        if ($inventory['error'] !== null) {
            \fwrite(\STDERR, 'candy-code: ' . $inventory['path'] . ' ' . $inventory['error'] . "\n");

            // Exit 1: the file was found, trusted and READ, and reading it is
            // what failed. Nothing about a retry helps, but something did run —
            // the same reading `session delete <unknown id>` gets.
            return NonInteractive::EXIT_FAILURE;
        }

        if ($inventory['servers'] === []) {
            echo Lang::t('cli.mcp.list.empty', ['path' => $inventory['path']]) . "\n";
            // N-2: 'declares no servers' is only the whole truth when the
            // skipped rows are said too — an all-typo file must not read clean.
            self::warnSkippedMcpEntries($inventory['skipped']);

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
            $wirePrefix = \SugarCraft\Code\Tools\McpToolBridge::wireServerPrefix($server['name']);
            if ($wirePrefix === \SugarCraft\Code\Tools\McpToolBridge::NAME_PREFIX . $server['name'] . '__') {
                continue;
            }
            echo Lang::t('cli.mcp.list.wire_name', ['server' => $server['name'], 'prefix' => $wirePrefix]) . "\n";
        }

        self::warnSkippedMcpEntries($inventory['skipped']);

        return NonInteractive::EXIT_OK;
    }

    /**
     * N-2: name, one STDERR line each, the entries
     * {@see Bootstrap::mcpServerInventory()} could not treat as servers. The
     * entry rows carry the launch's own ISS-091 sentence, so list and launch
     * read identically; the table above stays the answer to "what is declared"
     * while a typo finally costs a line instead of vanishing.
     */
    private static function warnSkippedMcpEntries(array $skipped): void
    {
        foreach ($skipped as $entry) {
            \fwrite(\STDERR, 'candy-code: ' . Lang::t('cli.mcp.list.skipped', ['entry' => $entry]) . "\n");
        }
    }

    /**
     * `candy-code mcp trust` — approve this project's `.mcp.json` as it is now
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
                Lang::t('cli.mcp.trust.unexpected_operand', ['operand' => $args->subcommandArgs[1]]),
                $args->outputFormat,
                Lang::t('cli.mcp.trust.usage'),
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
            case Bootstrap::MCP_DISABLED:
                // trustProjectMcp() refuses every status outside
                // {UNTRUSTED, TRUSTED} before touching the writers, so this
                // row is the whole story: nothing was granted, and the grant
                // is not the blocked step — the switch is.
                echo Lang::t('cli.mcp.trust.disabled', ['path' => $report['path']]) . "\n";

                return NonInteractive::EXIT_OK;

            case Bootstrap::MCP_ABSENT:
                echo Lang::t('cli.mcp.trust.absent', ['file' => Bootstrap::MCP_CONFIG_FILENAME, 'path' => $report['path']]) . "\n";

                return NonInteractive::EXIT_OK;

            case Bootstrap::MCP_OUTSIDE_TREE:
                echo Lang::t('cli.mcp.trust.outside', ['path' => $report['path']]) . "\n";

                return NonInteractive::EXIT_OK;
        }

        // ONE stderr site for the three ways an approval can fail to land: the
        // file could not be read, the grant could not be written, or the
        // record could not be. Each leaves the launch refusing what it refused.
        $failure = match (true) {
            $report['error'] !== null && !$report['granted'] => $report['path'] . ' ' . $report['error'],
            !$report['granted'] => Lang::t('cli.mcp.trust.not_granted', ['root' => $report['root'], 'config' => Bootstrap::userConfigPath()]),
            !$report['recorded'] => Lang::t('cli.mcp.trust.not_recorded', ['error' => (string) $report['error']]),
            default => null,
        };

        if ($failure === null || $report['granted']) {
            echo Lang::t('cli.mcp.trust.heading', ['path' => $report['path']]) . "\n";
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
                \printf("  %-{$width}s  %-9s  %s\n", $name, 'invalid', Lang::t('cli.mcp.trust.malformed'));
            }
        }

        if ($failure !== null) {
            \fwrite(\STDERR, 'candy-code: ' . $failure . "\n");

            return NonInteractive::EXIT_FAILURE;
        }

        echo (\count($report['servers']) === 1
            ? Lang::t('cli.mcp.trust.recorded.one', ['count' => 1])
            : Lang::t('cli.mcp.trust.recorded.many', ['count' => \count($report['servers'])])) . "\n";

        return NonInteractive::EXIT_OK;
    }

    /**
     * `candy-code mcp auth login <server> [token-url] [authorize-url] [registration-url] [-- --timeout N]`
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
     * a post-`--` operand: `candy-code mcp auth login <server> -- --timeout
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
                    ? 'candy-code: ' . Lang::t('cli.mcp.auth.no_action')
                    : 'candy-code: ' . Lang::t('cli.mcp.auth.unknown_action', ['action' => $action]),
                $args->outputFormat,
                Lang::t('cli.mcp.auth.usage'),
            );
        }

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.mcp.auth.interactive'),
                $args->outputFormat,
                Lang::t('cli.mcp.auth.interactive_hint'),
            );
        }

        $operands = [];
        $timeout = \SugarCraft\Code\MCP\OAuthLoopbackFlow::DEFAULT_TIMEOUT_SECONDS;
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
                    'candy-code: ' . Lang::t('cli.mcp.auth.bad_timeout'),
                    $args->outputFormat,
                    Lang::t('cli.mcp.auth.usage'),
                );
            }
            $timeout = (float) $value;
            $i++;
        }

        $serverUrl = $operands[0] ?? null;
        if ($serverUrl === null) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.mcp.auth.no_server'),
                $args->outputFormat,
                Lang::t('cli.mcp.auth.usage'),
            );
        }

        // The flow owns every echo of the browser leg and returns the exit
        // code; the store is the same file `mcp auth add` writes, so a
        // login's entry attaches exactly like an add's does (E695 path).
        $flow = new \SugarCraft\Code\MCP\OAuthLoopbackFlow(
            \SugarCraft\Code\MCP\McpAuthStore::create()->oauth(),
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
     * The dialects `candy-code mcp import` can read — the keys of
     * {@see \SugarCraft\Code\MCP\McpForeignTranslate::CONTAINERS}. Derived
     * from that map rather than re-typed here, so a third dialect added to
     * the translator is completable and dispatchable the moment it is
     * importable, and this door can never advertise a word nothing can answer.
     *
     * @return list<string>
     */
    private static function importSources(): array
    {
        return \array_keys(\SugarCraft\Code\MCP\McpForeignTranslate::CONTAINERS);
    }

    /**
     * The one usage line this verb's five doors all print.
     */
    private static function importUsage(): string
    {
        return Lang::t('cli.mcp.import.usage');
    }

    /**
     * `candy-code mcp import claude|opencode|claude-project <path>` — E710's
     * foreign-config translator, and since C-MCP-4.d the plan-§6.1 ruling's
     * single named write door.
     *
     * THE PRINT-ONLY LAW (and the exception). Without `--write`, this verb
     * reads one file and PRINTS the translated block on stdout; it never
     * touches the filesystem again. An importer that always wrote the
     * destination would decide where the operator's config lives — project
     * root vs `--root` vs a scratch clone are three different answers a human
     * picks — and a wrong decision silently swallowed is worse than a paste.
     * stdout carries ONLY the document (and the JSON envelope under
     * `--output-format json`), so
     * `candy-code mcp import opencode ~/.config/opencode.json > .mcp.json`
     * is exact; every note about WHAT the translation did rides stderr, where
     * a redirect cannot corrupt it. THE EXCEPTION, spelled: `--write user`
     * appends the translated entries into the operator's OWN user-tier file
     * `~/.candy-code/mcp.json` — owned-home gate first, collisions refusing
     * the whole write, project tier never {@see mcpImportWriteUser()}. The
     * ruling that lifted the blanket ban (§6.1: user tier only, collisions
     * refused) trades the paste ceremony for the user tier's own posture,
     * which the write names on stderr so the operator sees what they opted
     * into; the DEFAULT path — no flag, any other tier — keeps every byte of
     * the old law.
     *
     * The renames themselves are not spelled here: they are
     * {@see \SugarCraft\Code\MCP\McpForeignTranslate}, the same table
     * `McpClient::startServer()` reads foreign spellings through since E708.
     * Importer and loader share one vocabulary by construction — a second
     * hand-typed mapping would be a second answer waiting to drift.
     *
     * Exit codes are the five-way convention this class documents: `2` for
     * the operand doors (no source, unknown source, no path, extra operand,
     * unreadable file — and, since C-MCP-4.d, the two `--write` doors: a tier
     * other than `user`, or an unowned home with nothing to write — nothing
     * was attempted), `1` for a file that was read and then refused (not
     * JSON, not a config of the named dialect, a malformed entry — or a
     * `--write user` whose existing file cannot be read or collides with the
     * entries, which is refused with NOTHING changed, the same reading as
     * `mcp list` on a trusted-but-unparseable `.mcp.json`), `0` for the
     * printed document even when it came with notes — with `--write user`,
     * only after the write itself published.
     */
    private static function mcpImport(ParsedArgs $args): int
    {
        $source = $args->subcommandArgs[1] ?? null;
        if ($source === null) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.mcp.import.no_source'),
                $args->outputFormat,
                self::importUsage(),
            );
        }
        if (!\in_array($source, self::importSources(), true)) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.mcp.import.unknown_source', ['source' => $source]),
                $args->outputFormat,
                Lang::t('cli.mcp.import.valid_sources', ['sources' => \implode(', ', self::importSources())]),
            );
        }
        $path = $args->subcommandArgs[2] ?? null;
        if ($path === null) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.mcp.import.no_file'),
                $args->outputFormat,
                self::importUsage(),
            );
        }
        $extra = $args->subcommandArgs[3] ?? null;
        if ($extra !== null) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.mcp.import.unexpected_operand', ['operand' => $extra]),
                $args->outputFormat,
                self::importUsage(),
            );
        }

        // C-MCP-4.d: the print-only law's one named exception, opt-in by
        // spelling. The parser checked the SHAPE (a value followed `--write`);
        // which tier is honoured is judged HERE — anything other than `user`
        // is refused before a byte is read, because the project tier is never
        // written and no future tier should be honoured by accident.
        $writeTier = $args->subcommandFlags['--write'] ?? null;
        if ($writeTier !== null && $writeTier !== 'user') {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.mcp.import.write_unknown_tier', ['tier' => (string) $writeTier]),
                $args->outputFormat,
                Lang::t('cli.mcp.import.write_usage'),
            );
        }
        $userPath = '';
        if ($writeTier === 'user') {
            // The gate is the loader's own answer, not a second spelling:
            // `McpDecision::mcpUserConfigPath()` returns '' exactly when
            // HomeDirectory::owned() refuses the home, and an unowned home has
            // no writable user tier. Judged BEFORE the read so an invocation
            // that can never write does not spend its exit 2 on a file it was
            // never going to act on.
            $userPath = McpDecision::mcpUserConfigPath();
            if ($userPath === '') {
                return NonInteractive::failUsage(
                    'candy-code: ' . Lang::t('cli.mcp.import.write_home_unowned'),
                    $args->outputFormat,
                    self::importUsage(),
                );
            }
        }

        // One read, and the operator's own argv named the path — the same
        // CALLER_SUPPLIED posture as every other CLI file operand (`--config`
        // above, `session delete`'s store). The bytes are a document to
        // translate, never code to execute: nothing here reaches `buildServer`
        // or spawns anything; on the print path the servers only START after
        // the operator pastes the block into `.mcp.json` and trusts the root,
        // and on the `--write user` path only after the write below publishes
        // them into the user tier — in both cases the read itself executes
        // nothing.
        $raw = @file_get_contents($path);  // BARE spelling — the \-prefixed form is invisible to the read-path census scanner
        if ($raw === false) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.mcp.import.unreadable', ['path' => $path]),
                $args->outputFormat,
                Lang::t('cli.mcp.import.unreadable_hint'),
            );
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                throw new \RuntimeException(Lang::t('cli.mcp.import.not_an_object'));
            }
            // The root rides along for the ONE nested dialect; the flat
            // translators ignore it. Same ambient default the nothing-written
            // note already takes: the launch directory names which project's
            // block `claude-project` should read unless `--root` says else.
            $translated = \SugarCraft\Code\MCP\McpForeignTranslate::translateDocument(
                $source,
                $decoded,
                \rtrim($args->root ?? getcwd(), '/'),
            );
        } catch (\JsonException $e) {
            return self::mcpImportFailure($path, Lang::t('cli.mcp.import.invalid_json', ['error' => $e->getMessage()]), $args);
        } catch (\RuntimeException $e) {
            return self::mcpImportFailure($path, $e->getMessage(), $args);
        }

        if ($writeTier === 'user') {
            // The document translated; now the opted-to write. Every refusal
            // the write can earn exits 1 with NO document on stdout — the
            // print-after-paste contract survives the new door.
            return self::mcpImportWriteUser($args, $source, $path, $translated, $userPath);
        }

        self::mcpImportDocument($args, $source, $path, $translated, null);

        // Notes after the document, all of them on stderr: the translation
        // earned its exit 0 by printing, and the account of what it renamed
        // must not contaminate the bytes a pipe or redirect carries.
        foreach ($translated['notes'] as $note) {
            self::mcpImportLine($note);
        }
        self::mcpImportLine(Lang::t('cli.mcp.import.nothing_written', [
            // Same ambient default mcpServerInventory() takes for a null
            // `--root`: the launch directory names where the block belongs.
            'file' => \rtrim($args->root ?? getcwd(), '/') . '/' . Bootstrap::MCP_CONFIG_FILENAME,
            'config' => Bootstrap::userConfigPath(),
        ]));

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
        \fwrite(\STDERR, 'candy-code: mcp import: ' . $line . "\n");
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
     * stdout's half of a successful import: the translated document, bare
     * (the redirect-exact contract of the print path) or inside the JSON
     * envelope. `$written` is the `--write user` path's one addition — the
     * print path passes null and its bytes are exactly the ones E710 pinned,
     * so one shared emitter cannot fork the two paths apart later.
     *
     * @param array{servers: array<string, array<string, mixed>>, notes: list<string>} $translated
     */
    private static function mcpImportDocument(ParsedArgs $args, string $source, string $path, array $translated, ?string $written): void
    {
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            $result = [
                'source' => $source,
                'path' => $path,
                'mcpServers' => $translated['servers'],
                'notes' => $translated['notes'],
            ];
            if ($written !== null) {
                $result['written'] = $written;
            }
            self::emitDocument(['result' => $result]);
        } else {
            echo \json_encode(
                ['mcpServers' => $translated['servers']],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ) . "\n";
        }
    }

    /**
     * C-MCP-4.d — `mcp import ... --write user`, the print-only law's single
     * named exception, per the plan §6.1 ruling (user tier only, collisions
     * refused). Lives OUTSIDE the mcpImport body on purpose: the structural
     * pin that keeps the verb's print path write-shape-free scans mcpImport
     * itself, and this is the one door the ruling cut in that law — named,
     * greppable, and nowhere else.
     *
     * WHERE it writes: the user tier `~/.candy-code/mcp.json`, at the path
     * {@see McpDecision::mcpUserConfigPath()} answers — a non-empty answer
     * exists only because HomeDirectory::owned() vouched for the home, so no
     * bytes of this verb ever land in a home another uid could have planted.
     * HOW: {@see \SugarCraft\Code\Support\AtomicFileWriter}'s temp-then-rename
     * with mode 0600 settled before the publish, exactly how McpTrustPins
     * records trust. The PROJECT tier is never written from here or anywhere:
     * `--write project` is refused at the door above, and a checkout is never
     * a side effect of translating someone else's file.
     *
     * COLLISIONS REFUSE THE WHOLE WRITE. A server name the existing file
     * already declares is not merged, not renamed, not overwritten — the
     * operator decides which answer wins, by editing, not by whatever an
     * append-ordering rule silently chose. The refusal names every colliding
     * server and leaves the file byte-identical: the check runs before
     * anything is published. An existing file that is not readable JSON with
     * an `mcpServers` object is refused, never repaired — the posture `mcp
     * list` and `login`'s merge-read already keep toward a corrupt config.
     *
     * THE RISK, stated because it is why the flag must be typed on purpose:
     * entries written at the user tier start at EVERY future launch from
     * EVERY directory, with no per-repo ceremony and no trust prompt per
     * project. That is the tier's existing posture — the loader already reads
     * this file at every launch — and importing a foreign server into it
     * hands that posture to servers the operator may have meant for one
     * checkout. The stderr note says so on the success path; docs/MCP.md
     * states it where the law lives.
     *
     * @param array{servers: array<string, array<string, mixed>>, notes: list<string>} $translated
     */
    private static function mcpImportWriteUser(ParsedArgs $args, string $source, string $path, array $translated, string $userPath): int
    {
        $existing = null;
        if (\is_file($userPath)) {
            // BARE spelling — the census row for this site says OWNED_HOME:
            // the path is HomeDirectory::owned()'s answer, never the argv's.
            $existingRaw = @file_get_contents($userPath);
            if ($existingRaw === false) {
                return self::mcpImportFailure($userPath, Lang::t('cli.mcp.import.existing_unreadable'), $args);
            }
            try {
                $decodedExisting = \json_decode($existingRaw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decodedExisting = null;
            }
            if (!\is_array($decodedExisting) || !\is_array($decodedExisting['mcpServers'] ?? null)) {
                return self::mcpImportFailure($userPath, Lang::t('cli.mcp.import.existing_unreadable'), $args);
            }
            $existing = $decodedExisting;
        }

        /** @var array<string, mixed> $existingServers */
        $existingServers = $existing['mcpServers'] ?? [];
        $collisions = \array_keys(\array_intersect_key($translated['servers'], $existingServers));
        if ($collisions !== []) {
            \sort($collisions, \SORT_STRING);

            return self::mcpImportFailure(
                $userPath,
                Lang::t('cli.mcp.import.collisions', ['names' => \implode(', ', $collisions)]),
                $args,
            );
        }

        // The merge only runs collision-free, so `$existing + appended`
        // cannot shadow a name: the file's own bytes keep their order and
        // every other key it carries; the translated block rides in at the
        // tail of `mcpServers`, in this port's canonical shape.
        $document = $existing ?? ['mcpServers' => []];
        $document['mcpServers'] = $existingServers + $translated['servers'];
        $json = \json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            // A decoded document re-encoding false is the invalid-UTF-8 class
            // json_decode can admit via \u escapes; the answer is the same
            // refusal, not a truncate-and-hope.
            return self::mcpImportFailure($userPath, Lang::t('cli.mcp.import.write_failed', ['error' => \json_last_error_msg()]), $args);
        }

        try {
            \SugarCraft\Code\Support\AtomicFileWriter::write($userPath, $json . "\n", 0600);
        } catch (\Throwable $e) {
            // Atomic by construction: the rename never happened, so the claim
            // "the file was not changed" is the writer's guarantee, not hope.
            return self::mcpImportFailure($userPath, Lang::t('cli.mcp.import.write_failed', ['error' => $e->getMessage()]), $args);
        }

        self::mcpImportDocument($args, $source, $path, $translated, $userPath);
        foreach ($translated['notes'] as $note) {
            self::mcpImportLine($note);
        }
        // The written sentence REPLACES the print path's nothing-was-written
        // one, and states the risk rather than burying it: what was written,
        // where, and that it now starts everywhere.
        self::mcpImportLine(Lang::t('cli.mcp.import.written', [
            'count' => \count($translated['servers']),
            'file' => $userPath,
        ]));

        return NonInteractive::EXIT_OK;
    }

    /**
     * `candy-code completion bash|zsh|fish` — a completion script on stdout,
     * for `eval "$(candy-code completion bash)"` or redirection into the
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
                    ? 'candy-code: ' . Lang::t('cli.completion.no_shell')
                    : 'candy-code: ' . Lang::t('cli.completion.unsupported_shell', ['shell' => $shell]),
                $args->outputFormat,
                Lang::t('cli.completion.usage', ['shells' => \implode('|', self::SHELLS)]),
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
     * {@see \SugarCraft\Code\Permissions\PermissionMode::cases()}, derived at generation time rather than
     * written out so a mode added to the enum cannot go un-completable. The three generators below translate
     * that one column into three genuinely different dialects rather than
     * sharing a script — see {@see completion()}.
     *
     * `desc` is the `lang/en.php` key of the option's one-line description,
     * resolved through {@see Lang::t()} when a script is generated.
     *
     * @var array<string, array{short: string|null, value: string|null, desc: string}>
     */
    private const OPTIONS = [
        '--prompt' => ['short' => '-p', 'value' => 'text', 'desc' => 'cli.completion.option.prompt'],
        '--output-format' => ['short' => null, 'value' => 'format', 'desc' => 'cli.completion.option.output_format'],
        '--root' => ['short' => null, 'value' => 'dir', 'desc' => 'cli.completion.option.root'],
        '--config' => ['short' => null, 'value' => 'file', 'desc' => 'cli.completion.option.config'],
        '--model' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.model'],
        '--permission-mode' => ['short' => null, 'value' => 'mode', 'desc' => 'cli.completion.option.mode'],
        '--max-cost' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.max_cost'],
        '--max-turns' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.max_turns'],
        '--json-schema' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.json_schema'],
        '--append-system-prompt' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.append_system_prompt'],
        '--continue' => ['short' => '-c', 'value' => null, 'desc' => 'cli.completion.option.continue'],
        '--resume' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.resume'],
        '--help' => ['short' => '-h', 'value' => null, 'desc' => 'cli.completion.option.help'],
        '--version' => ['short' => '-v', 'value' => null, 'desc' => 'cli.completion.option.version'],
    ];

    /**
     * The one-line summary each subcommand gets in the two shells that have
     * somewhere to put one (zsh's `_describe`, fish's `-d`). bash's `compgen
     * -W` takes bare words only, which is why its script shows names alone.
     * Each value is the `lang/en.php` key of that summary.
     *
     * @var array<string, string>
     */
    private const SUBCOMMAND_DESCRIPTIONS = [
        'run' => 'cli.completion.verb.run',
        'acp' => 'cli.completion.verb.acp',
        'attach' => 'cli.completion.verb.attach',
        'bundles' => 'cli.completion.verb.bundles',
        'completion' => 'cli.completion.verb.completion',
        'doctor' => 'cli.completion.verb.doctor',
        'login' => 'cli.completion.verb.login',
        'mcp' => 'cli.completion.verb.mcp',
        'models' => 'cli.completion.verb.models',
        'self-update' => 'cli.completion.verb.self_update',
        'serve' => 'cli.completion.verb.serve',
        'session' => 'cli.completion.verb.session',
    ];

    /**
     * Each subcommand's own operands, for the nested completion the three
     * scripts all offer after the verb.
     *
     * @var array<string, list<string>>
     */
    private const SUBCOMMAND_ACTIONS = [
        'session' => ['list', 'show', 'rename', 'delete', 'pin', 'unpin', 'archive', 'unarchive'],
        'bundles' => ['list', 'install', 'marketplace', 'update', 'search', 'recommend'],
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
            static fn (\SugarCraft\Code\Permissions\PermissionMode $m): string => $m->value,
            \SugarCraft\Code\Permissions\PermissionMode::cases(),
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

        return "# bash completion for candy-code. Install with:\n"
            . "#   eval \"\$(candy-code completion bash)\"\n"
            . "_candy_code() {\n"
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
            . "complete -F _candy_code candy-code\n";
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
            $desc = \str_replace(['[', ']'], ['\\[', '\\]'], self::zshDescription(Lang::t($spec['desc'])));
            $specs[] = "        '" . $exclusion . $flag . '[' . $desc . ']' . $tail . "'";
            if ($spec['short'] !== null) {
                $specs[] = "        '(" . $spec['short'] . ' ' . $flag . ')' . $spec['short']
                    . '[' . $desc . ']' . $tail . "'";
            }
        }

        $verbs = [];
        foreach (self::SUBCOMMAND_DESCRIPTIONS as $verb => $desc) {
            $verbs[] = "        '" . $verb . ':' . self::zshDescription(Lang::t($desc)) . "'";
        }

        $actionCases = '';
        foreach (self::SUBCOMMAND_ACTIONS as $verb => $actions) {
            $actionCases .= \sprintf(
                "                %s) _values 'action' %s ;;\n",
                $verb,
                \implode(' ', $actions),
            );
        }

        return "#compdef candy-code\n"
            . "# zsh completion for candy-code. Install it somewhere on \$fpath as\n"
            . "# _candy-code, e.g. candy-code completion zsh > \"\${fpath[1]}/_candy-code\"\n"
            . "_candy_code() {\n"
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
            . "        subcommand) _describe -t commands 'candy-code subcommand' subcommands ;;\n"
            . "        args)\n"
            . "            case \$words[1] in\n"
            . $actionCases
            . "            esac\n"
            . "            ;;\n"
            . "    esac\n"
            . "}\n"
            . "\n"
            . "_candy_code \"\$@\"\n";
    }

    /**
     * A description made safe for the single-quoted zsh word it is spliced
     * into: a quote would end the word (and an `_arguments` spec escapes its
     * brackets on top, see {@see zshCompletion()}). The English catalogue
     * carries neither, so this changes no byte of the English script; it is
     * here so a translation cannot break the user's shell completion.
     */
    private static function zshDescription(string $desc): string
    {
        return \str_replace("'", "'\\''", $desc);
    }

    /**
     * fish has no COMPREPLY equivalent and no dispatch function: completion is
     * declarative, one `complete -c` line per rule, gated by `-n` conditions.
     * So this is a third real dialect rather than a relabelling — note that
     * the blanket `complete -c candy-code -f` turns file completion OFF (the
     * first argument is a verb, not a path) and `-F` turns it back on for
     * exactly the two options that take one.
     */
    private static function fishCompletion(): string
    {
        $lines = [
            '# fish completion for candy-code. Install with:',
            '#   candy-code completion fish > ~/.config/fish/completions/candy-code.fish',
            '',
            '# The first argument is a subcommand, not a path.',
            'complete -c candy-code -f',
            '',
        ];

        foreach (self::SUBCOMMAND_DESCRIPTIONS as $verb => $desc) {
            $lines[] = \sprintf(
                'complete -c candy-code -n "__fish_use_subcommand" -a %s -d %s',
                \escapeshellarg($verb),
                \escapeshellarg(Lang::t($desc)),
            );
        }
        $lines[] = '';

        foreach (self::OPTIONS as $flag => $spec) {
            $line = 'complete -c candy-code -l ' . \substr($flag, 2);
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
            $lines[] = $line . ' -d ' . \escapeshellarg(Lang::t($spec['desc']));
        }
        $lines[] = '';

        foreach (self::SUBCOMMAND_ACTIONS as $verb => $actions) {
            $lines[] = \sprintf(
                'complete -c candy-code -n %s -a %s -d %s',
                \escapeshellarg('__fish_seen_subcommand_from ' . $verb),
                \escapeshellarg(\implode(' ', $actions)),
                \escapeshellarg(Lang::t('cli.completion.verb_argument', ['verb' => $verb])),
            );
        }
        $lines[] = '';

        return \implode("\n", $lines);
    }
}

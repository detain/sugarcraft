<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\Sandbox\SandboxFactory;
use SugarCraft\Code\Tools\Sandbox\Seatbelt;

/**
 * The install checks behind `candy-code doctor` AND the in-session `/doctor`
 * command — one roster, read two ways.
 *
 * The two surfaces answer the same question from different places: the CLI
 * runs before any session exists and prints a line per check
 * ({@see \SugarCraft\Code\Cli\Subcommands::doctor()}), while `/doctor` runs
 * inside a live session and renders the same rows as a transcript table
 * ({@see \SugarCraft\Code\Host\Commands\DoctorHostCommand}). A second
 * implementation of the probes would fork those verdicts — the CLI calling the
 * install sound while the session reports a FAIL — so the roster, every probe
 * and the per-probe catch live HERE, and each caller only FORMATS what
 * {@see run()} returns.
 *
 * Two laws every probe keeps:
 *   - READ-ONLY. Nothing here writes a config, prunes a session or touches the
 *     store; the `session store` probe opens an in-memory DSN, which is why
 *     {@see self::SESSION_STORE_PROBE_DSN} is a scheme rather than a path.
 *   - NO NETWORK. The `provider` probe asks {@see ProviderFactory} which
 *     credentials EXIST; it never dials. A report that hangs cannot diagnose a
 *     hang.
 *
 * A row is always `OK`|`WARN`|`FAIL` plus a one-line `detail`. FAIL belongs to
 * the checks that say the program itself cannot run; a WARN is "configured in
 * a way that will surprise you", which is why a warning never fails either
 * surface.
 */
final class DoctorChecks
{

    /**
     * Every check, run in roster order, each one wrapped individually.
     *
     * The catch lives HERE rather than in either caller, so the CLI and
     * `/doctor` cannot diverge on what a broken reader means: this report
     * exists for the broken install, so a throw from any one reader has to
     * become a reported line rather than take the whole report down with it —
     * an unreadable config file makes {@see Bootstrap::permissionGate()} throw
     * `PermissionConfigException`, and that is precisely the diagnosis the
     * operator ran doctor to get.
     *
     * @return list<array{label: string, status: string, detail: string}>
     */
    public static function run(?string $root): array
    {
        $checks = [];
        foreach (self::probes($root) as $label => $probe) {
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

        return $checks;
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
     * Closures rather than values so {@see run()} can wrap each one: a
     * pre-computed array would evaluate every reader before the try/catch and
     * so let the first throw kill the whole report.
     *
     * @return array<string, \Closure(): array{status: string, detail: string}>
     */
    public static function probes(?string $root): array
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

            'settings keys' => static function () use ($root): array {
                // ISS-029: the tolerant reader dropped a misspelled key
                // without a word, so a config full of `permisionMode` used to
                // read OK here. The audit names every top-level key no reader
                // consumes from its file, guesses near-miss spellings, and
                // flags known keys whose value falls back to its default.
                $rows = LayeredSettings::keyAuditRows(\dirname(Bootstrap::userConfigPath()), $root);

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
                    // C-SEC-7.a split the console default by surface, so the
                    // probe resolves all three paths a launch can fall back to.
                    $headless = Bootstrap::permissionGate(
                        consoleSurface: Bootstrap::PERMISSION_SURFACE_HEADLESS,
                        announceBypassMode: false,
                    );
                    $tui = Bootstrap::permissionGate(interactive: true, announceBypassMode: false);
                    $background = Bootstrap::permissionGate(announceBypassMode: false);
                } catch (PermissionConfigException $e) {
                    return ['status' => 'FAIL', 'detail' => $e->getMessage()];
                }

                // With nothing configured the paths start in different modes
                // (decision D5, as C-SEC-7.a split it), and a one-word answer
                // would describe only one of them.
                return ['status' => 'OK', 'detail' => $tui->mode() === $headless->mode() && $headless->mode() === $background->mode()
                    ? Lang::t('cli.doctor.mode', ['mode' => $headless->mode()->value])
                    : Lang::t('cli.doctor.mode_split', [
                        'tui' => $tui->mode()->value,
                        'headless' => $headless->mode()->value,
                        'background' => $background->mode()->value,
                    ])];
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

            'mcp config' => static function () use ($root): array {
                $inventory = Bootstrap::mcpServerInventory($root);

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
     * The `sandbox` doctor row: the three facts someone typing
     * "sandboxing is not working" needs, each answered through the SAME code
     * the Bash tool uses, never a second opinion —
     *   1. what `bashSandbox` is configured to;
     *   2. where the jail binary resolves (`bwrap` on Linux, `sandbox-exec`
     *      on macOS — whichever {@see SandboxFactory::fromSetting()} picks);
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
     * program itself cannot run — the exit convention `Subcommands::doctor()` applies.
     *
     * On Windows no jail exists, so the row stays at the vocabulary verdict
     * there; on Linux and macOS alike the probe runs the backend the factory
     * would hand Bash, which is the only answer that matters.
     *
     * $jail, $sysctlPath and $osFamily are test seams: injecting them makes
     * every verdict arm reachable on ANY host — Linux, macOS or not, bwrap
     * or not — without the live row (all three defaulted) ever lying about
     * the real one. The live row still spawns exactly what Bash would spawn,
     * once per process, which is the whole point of asking through Bash.
     *
     * @param ?\Closure(): ?string $jail
     */
    private static function sandboxProbe(?\Closure $jail = null, ?string $sysctlPath = null, string $osFamily = \PHP_OS_FAMILY): array
    {
        $configured = SandboxFactory::fromSetting(Bootstrap::readUserConfig()['bashSandbox'] ?? null, $osFamily);
        $mode = $configured?->mode() ?? SandboxBackend::MODE_OFF;

        if ($osFamily === 'Windows') {
            // The jail cannot exist here. OK when nothing was configured —
            // off is honest on every OS — WARN when something was, because
            // that setting will never take effect on this host. (macOS has
            // its own jail since C-TOOL-4.b and falls through to the probe.)
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

        // The jail is exercised even while `off`: "off, and a working jail is
        // sitting right there" is a different sentence from "off, and it
        // would not start anyway", and the first one tells the user the fix
        // is a setting rather than an install.
        $sandbox = $configured ?? SandboxFactory::fromSetting(true, $osFamily);
        $binary = $sandbox->resolvedBinary();
        $refusal = ($jail ?? static fn (): ?string => (new Bash(sandbox: $sandbox))->sandboxRefusal())();
        // The row names the binary, not the backend brand: on Linux this is
        // the `bwrap` the sentence has always printed, on macOS `sandbox-exec`.
        $binaryLabel = basename($binary !== '' ? $binary : ($sandbox instanceof Seatbelt ? Seatbelt::SYSTEM_BINARY : 'bwrap'));
        $jailReport = static fn (): string => Lang::t($binary === ''
            ? 'cli.doctor.sandbox_binary_absent'
            : 'cli.doctor.sandbox_binary', ['backend' => $binaryLabel, 'path' => $binary]);

        if ($configured === null) {
            return [
                'status' => 'OK',
                'detail' => Lang::t($refusal === null
                    ? 'cli.doctor.sandbox_off'
                    : 'cli.doctor.sandbox_off_blocked', [
                    'bwrap' => $jailReport(),
                    'reason' => (string) $refusal,
                ]),
            ];
        }

        if ($refusal === null) {
            // Reaching this arm with a jail that starts means the binary
            // resolved — the backends' probe() answers 'not found' first —
            // so the path form is the only one printed here.
            return [
                'status' => 'OK',
                'detail' => Lang::t('cli.doctor.sandbox_ready', [
                    'mode' => $mode,
                    'bwrap' => $jailReport(),
                ]),
            ];
        }

        // The remedy arms split on the one host fact that turns "install
        // bubblewrap" into bad advice: when the kernel restricts unprivileged
        // user namespaces, bwrap is probably already installed and a fresh
        // install changes nothing — the fix is the AppArmor profile, with the
        // sysctl as the blunt fallback. An unreadable sysctl (null) is not
        // "permissive": absence answers unknown, and the generic remedy stays
        // honest for the missing-binary case it also covers. macOS has no
        // such story — sandbox-exec ships with the OS, so its remedy names
        // the binary instead of a package.
        $appArmor = $sandbox instanceof Bubblewrap
            ? Bubblewrap::appArmorRestrictsUserns($sysctlPath ?? Bubblewrap::APPARMOR_RESTRICT_USERNS_PATH)
            : null;

        return [
            'status' => 'WARN',
            'detail' => Lang::t('cli.doctor.sandbox_refused', [
                'mode' => $mode,
                'reason' => $refusal,
                'remedy' => $appArmor === true
                    ? Lang::t('cli.doctor.sandbox_remedy_apparmor')
                    : ($sandbox instanceof Bubblewrap
                        ? Lang::t('cli.doctor.sandbox_remedy_generic')
                        : Lang::t('cli.doctor.sandbox_remedy_seatbelt')),
            ]),
        ];
    }
}

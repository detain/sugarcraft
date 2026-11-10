<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Bundles\BundleDiscovery;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Commands\UsageCommand;
use SugarCraft\Code\Config\ClaudeSettingsImport;
use SugarCraft\Code\Config\ProviderKeyStore;
use SugarCraft\Code\Host\Commands\UsageHostCommand;
use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Host\TitleService;
use SugarCraft\Code\LSP\LspInstaller;
use SugarCraft\Code\LSP\LspReleases;
use SugarCraft\Code\MCP\Serve\McpServeHost;
use SugarCraft\Code\MCP\Serve\McpServeRoster;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\Gc\GcInventory;
use SugarCraft\Code\Session\Gc\GcOrphanCensus;
use SugarCraft\Code\Session\Gc\GcSessionSizes;
use SugarCraft\Code\Session\Gc\GcStoreFiles;
use SugarCraft\Code\Session\Gc\GcVacuum;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Bundles\BundleRecommender;
use SugarCraft\Code\Bundles\BundleSource;
use SugarCraft\Code\Bundles\Marketplace;
use SugarCraft\Code\Bundles\MarketplaceEntry;
use SugarCraft\Code\Bundles\MarketplaceRegistry;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\Keychain;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Session\Import\ImportFormat;
use SugarCraft\Code\Session\Import\ImportRefusedException;
use SugarCraft\Code\Session\Import\SessionImportLedger;
use SugarCraft\Code\Session\Import\SessionImporter;
use SugarCraft\Code\Session\SessionLock;
use SugarCraft\Code\Session\SessionSearchIndex;
use SugarCraft\Code\Session\SessionQuery;
use SugarCraft\Code\Session\SessionResolver;
use SugarCraft\Code\Session\SessionRow;
use SugarCraft\Code\Session\TitleSource;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Support\SqliteConnect;
use SugarCraft\Code\Util\Exporter;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Tty;

/**
 * The real CLI subcommands — `mcp list`, `session list|show|rename|delete|
 * pin|unpin|archive|unarchive`, `models`, `doctor`, `bundles list|install`,
 * `serve`, `self-update`, `usage` and `completion bash|zsh|fish` (Phase 4 item 6; the
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
 * TUI" bug of Phase 0 item 3, reopened by a new verb. Nothing in
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
            'gc'         => self::gc($args),
            'models'     => self::models($args),
            'self-update' => SelfUpdate::run($args),
            'login'      => self::login($args),
            'lsp'        => self::lsp($args),
            'session'    => self::session($args),
            'serve'      => Serve::run($args),
            'attach'     => Attach::run($args),
            'acp'        => Acp::run($args),
            'bundles'    => self::bundles($args),
            'mcp'        => self::mcp($args),
            'import'     => self::importClaude($args),
            'completion' => self::completion($args),
            'usage'      => self::usage($args),
            'workflow'   => self::workflow($args),
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
     * The checks themselves are {@see DoctorChecks}', shared with the
     * in-session `/doctor`, which renders the same rows as a table; this
     * method only formats what {@see DoctorChecks::run()} returns and turns a
     * FAIL into a non-zero exit.
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

        // Every probe, and each one's individual catch, are DoctorChecks', so the
        // in-session `/doctor` reports exactly what this prints.
        $checks = DoctorChecks::run($args->root);

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
     * Read one key from stdin and persist it through
     * {@see ProviderKeyStore::store()}. The store merges onto the file's
     * EXISTING `providers` map by hand, because writeUserConfig() merges
     * shallow at the top level and replacing
     * the whole `providers` key would silently drop every other named provider.
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
     * All of that lives behind the store's door as of C-TUI-5.b; login used
     * to carry its own copy.
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
                    // C-SEC-8.f review NIT: a refused paste is the size
                    // bound firing — "try again" cannot help, so the
                    // sentence names the bound instead.
                    'refused' => Lang::t('cli.login.keychain.refused', ['provider' => $type]),
                    default => Lang::t('cli.login.keychain.failed', ['provider' => $type]),
                } . "\n";

                return NonInteractive::EXIT_FAILURE;
            }
            // From here the ONLY thing this method knows about the secret is
            // its reference — the settle re-read compares against this word,
            // and an unsupported family never even reaches the store call.
            $key = '${keychain:' . $type . '}';
        }

        // The persist half — merge, type guard, write, settle re-read — is
        // ProviderKeyStore's door (C-TUI-5.d); login was its duplicate. The
        // store answers null on a settled write and the finished translated
        // refusal sentence otherwise, which is exactly what this echo wants.
        $refusal = ProviderKeyStore::store($type, $key);
        if ($refusal !== null) {
            echo $refusal . "\n";

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
            'import' => self::sessionImport($args),
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
     * The store every `session` verb opens, through the funnel this file
     * already runs when the file will not open at all.
     *
     * A corrupt `session.db` used to escape as an uncaught `PDOException` at
     * exit 255 with a stack trace (CC-QA-1): the constructor's first
     * `PRAGMA` is what "file is not a database" bites on, and no verb between
     * `Bootstrap::sessionStore()` and the terminal guarded it. The store's
     * own constructor keeps that loud failure — a library caller must still
     * see a corrupt ledger — so the funnel is the verb's job, and this is the
     * one door all six store-touching verbs walk through. The shape is the
     * `usage` verb's own law ({@see self::usage()}): a store that will not
     * open states the reason it gave and exits 1. EXIT_FAILURE, not 2, per
     * the documented distinction ({@see self::resolveSessionTarget()}): the
     * verb attempted something and the ledger could not answer.
     *
     * `PDOException` is the precise target — every failure this path can see
     * (not-a-database, unwritable directory, locked past the busy timeout)
     * surfaces as one; anything else is a bug and stays loud.
     *
     * The notice reads its own catalogue line, `cli.session.store_unavailable`
     * — the CC-QA-1 wave's LANG REQUEST, minted when the closeout lang window
     * opened: this failure stated plainly plus the hint to run
     * `candy-code doctor` for the diagnosis. The JSON arm carries a degraded
     * RESULT document (the `usage` shape) rather than a new `error.type`,
     * because that promotion would owe README.md's error-contract lines —
     * the same law {@see self::refuseLiveLockedSession()} records as a
     * DOC-ROW. Under the
     * text format stdout is left for the answer that will not come; the
     * notice rides stderr like every sibling failure here.
     *
     * @return EnhancedSessionStore|int the open store, or the exit code
     *                                 already reported
     */
    private static function sessionStoreForVerbs(ParsedArgs $args): EnhancedSessionStore|int
    {
        try {
            return Bootstrap::sessionStore();
        } catch (\PDOException $e) {
            $notice = Lang::t('cli.session.store_unavailable', ['error' => $e->getMessage()]);

            \fwrite(\STDERR, 'candy-code: ' . $notice . "\n");
            if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
                self::emitDocument([
                    'result' => ['opened' => false, 'reason' => $notice],
                ]);
            }

            return NonInteractive::EXIT_FAILURE;
        }
    }

    /**
     * `gc` — storage inspection and reclaim over the session ledger
     * (gc-cli-1106 B1).
     *
     * The action door mirrors {@see self::session()}: no action is a usage
     * error, an unknown action is a usage error, and a flag the action does
     * not speak is refused rather than silently ignored. `sessions` and
     * `analyze` landed with B2 (the per-session byte fold and its analysis
     * surface); `purge` is the one later rung of the same verb — TODAY it
     * falls through this unknown-action door, which is the honest answer
     * for an action the store tools do not implement yet.
     *
     * Nothing here prunes: every read runs over a read-only connection, and
     * the only writes the verb performs are the VACUUM rewrites its own
     * `--force` door admits ("diagnostics NEVER prune" — the same law
     * {@see \SugarCraft\Code\Support\DoctorChecks} records for the probe).
     */
    private static function gc(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[0] ?? null;
        if ($verb === null) {
            return NonInteractive::failUsage('candy-code: ' . Lang::t('cli.gc.no_action'), $args->outputFormat, self::gcUsage());
        }
        if (!isset(self::GC_ACTION_FLAGS[$verb])) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.unknown_action', ['action' => $verb]),
                $args->outputFormat,
                self::gcUsage(),
            );
        }

        foreach (\array_keys($args->subcommandFlags) as $flag) {
            if (!\in_array($flag, self::GC_ACTION_FLAGS[$verb], true)) {
                return NonInteractive::failUsage(
                    'candy-code: ' . Lang::t('cli.gc.flag_does_not_apply', ['action' => $verb, 'flag' => $flag]),
                    $args->outputFormat,
                    Lang::t('cli.gc.accepts', ['action' => $verb, 'flags' => \implode(', ', self::GC_ACTION_FLAGS[$verb])]),
                );
            }
        }

        return match ($verb) {
            'status' => self::gcStatus($args),
            'sessions' => self::gcSessions($args),
            'analyze' => self::gcAnalyze($args),
            'purge' => self::gcPurge($args),
            default => self::gcVacuum($args),
        };
    }

    /**
     * One line per verb, for every `gc` usage error.
     */
    private static function gcUsage(): string
    {
        return Lang::t('cli.gc.usage');
    }

    /**
     * `gc status`: a full read-only census of the session store — file and
     * free-page bytes, per-table bytes, session counts and ages, the storage
     * families beside the database, and the retention regime as configured.
     *
     * The JSON layout is {@see GcInventory::collect()}'s own snake_case
     * shape, passed through untouched under the schema-1 envelope; the text
     * render adds bars so the biggest table is visible at a glance.
     */
    private static function gcStatus(ParsedArgs $args): int
    {
        $spelled = isset($args->subcommandFlags['--format'])
            ? (string) $args->subcommandFlags['--format']
            : NonInteractive::FORMAT_TEXT;
        if ($spelled !== NonInteractive::FORMAT_TEXT && $spelled !== NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.bad_format', ['format' => $spelled]),
                $args->outputFormat,
                self::gcUsage(),
            );
        }
        $asJson = $spelled === NonInteractive::FORMAT_JSON || $args->outputFormat === NonInteractive::FORMAT_JSON;

        try {
            $inventory = (new GcInventory(self::gcStorePath()))->collect(Bootstrap::sessionRetentionDays());
        } catch (\PDOException $e) {
            return self::gcStoreUnavailable($args, $asJson, 'status', $e->getMessage());
        }

        if ($asJson) {
            self::emitDocument(['verb' => 'gc', 'action' => 'status', 'result' => $inventory]);

            return NonInteractive::EXIT_OK;
        }

        echo 'candy-code gc status', \PHP_EOL;
        echo '  ', $inventory['db_path'], \PHP_EOL;
        echo Lang::t('cli.gc.files_line', [
            'db' => self::gcFormatBytes((int) $inventory['files']['db']),
            'wal' => self::gcFormatBytes((int) $inventory['files']['wal']),
            'shm' => self::gcFormatBytes((int) $inventory['files']['shm']),
            'total' => self::gcFormatBytes((int) $inventory['files']['total']),
        ]), \PHP_EOL;
        $pragma = $inventory['pragma'];
        echo \sprintf(
            'journal %s · auto_vacuum %s · reclaimable %s',
            (string) $pragma['journal_mode'],
            (string) $pragma['auto_vacuum_label'],
            self::gcFormatBytes((int) $inventory['reclaimable_bytes']),
        ), \PHP_EOL;

        echo Lang::t('cli.gc.tables_header'), \PHP_EOL;
        $largest = 0;
        foreach ($inventory['tables'] as $table) {
            $largest = \max($largest, (int) $table['bytes']);
        }
        foreach ($inventory['tables'] as $table) {
            $name = (string) $table['name'];
            if (!$table['present']) {
                echo \sprintf('  %-24s  %s', $name, Lang::t('cli.gc.row_absent')), \PHP_EOL;

                continue;
            }

            echo \sprintf(
                '  %-24s  %6d rows  %10s  %s',
                $name,
                (int) $table['rows'],
                self::gcFormatBytes((int) $table['bytes']),
                self::gcBar((int) $table['bytes'], $largest, (int) $inventory['bar_cells']),
            ), \PHP_EOL;
        }

        echo Lang::t('cli.gc.sessions_header'), \PHP_EOL;
        $counts = $inventory['sessions'];
        echo Lang::t('cli.gc.counts', [
            'total' => (string) $counts['total'],
            'live' => (string) $counts['live'],
            'archived' => (string) $counts['archived'],
            'subagents' => (string) $counts['subagents'],
            'pinned' => (string) $counts['pinned'],
            'named' => (string) $counts['named'],
        ]), \PHP_EOL;
        if ($counts['oldest_created_at'] !== null) {
            echo Lang::t('cli.gc.when', [
                'oldest' => \date('Y-m-d H:i', (int) $counts['oldest_created_at']),
                'newest' => \date('Y-m-d H:i', (int) $counts['newest_created_at']),
            ]), \PHP_EOL;
        }
        $buckets = $counts['age_buckets'];
        echo Lang::t('cli.gc.age_line', [
            'le7' => (string) $buckets['le7'],
            'd8_30' => (string) $buckets['d8_30'],
            'd31_90' => (string) $buckets['d31_90'],
            'gt90' => (string) $buckets['gt90'],
            'unknown' => (string) $buckets['unknown'],
        ]), \PHP_EOL;
        echo (int) $inventory['retention_days'] > 0
            ? Lang::t('cli.gc.retention_days', ['days' => (string) $inventory['retention_days']])
            : Lang::t('cli.gc.retention_off'), \PHP_EOL;

        echo Lang::t('cli.gc.storage_header'), \PHP_EOL;
        foreach ($inventory['storage'] as $family => $entry) {
            $line = \sprintf(
                '  %-14s  %10s  %d files',
                (string) $family,
                self::gcFormatBytes((int) $entry['bytes']),
                (int) $entry['files'],
            );
            if (isset($entry['lock_files'])) {
                $line .= \sprintf('  (locks %d live / %d stale)', (int) $entry['lock_live'], (int) $entry['lock_stale']);
            }
            if ((int) $entry['links_skipped'] > 0) {
                $line .= \sprintf('  (%d links skipped)', (int) $entry['links_skipped']);
            }
            echo $line, \PHP_EOL;
        }

        if ($inventory['flags']['events_exceed_transcripts']) {
            $bytesByName = [];
            foreach ($inventory['tables'] as $table) {
                $bytesByName[(string) $table['name']] = (int) $table['bytes'];
            }
            echo Lang::t('cli.gc.events_flag', [
                'events' => self::gcFormatBytes($bytesByName['session_events'] ?? 0),
                'transcripts' => self::gcFormatBytes($bytesByName['session_transcripts'] ?? 0),
            ]), \PHP_EOL;
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `gc sessions`: every session's REAL folded bytes (gc-cli-1106 B2).
     *
     * The fold ({@see GcSessionSizes}) attributes DB rows, transcript
     * journals and shadow-repo bytes to the session that owns them, using
     * the SAME column rosters the status census reads — so
     * `Σ rows.db_bytes + unattributed + fts residual + tombstones` equals
     * the status table-census total and the two actions reconcile (pinned
     * by test). Doors, chosen to mirror the session verb:
     *
     * - archived sessions are HIDDEN by default and `--archived` INCLUDES
     *   them (exactly the `candy-code session list --archived` semantics —
     *   the janitor shows what it can currently reclaim-by-decision; the
     *   user's kept archives appear only on request);
     * - `--children` RESTRICTS the listing to `parent_id IS NOT NULL`
     *   subagent rows (the requested sub-agent audit surface);
     * - `--sort size` (default) is descending bytes, `age` is OLDEST first
     *   (the ocgc convention — the janitor asks what has been around
     *   longest), `name` is alphabetical on the shown title with the id as
     *   tie-break so the order is total;
     * - `--limit N` keeps the first N rows of the SORTED list (0/all unset
     *   means everything); a non-positive-integer spelling is a usage
     *   error, never a silent clamp.
     *
     * The share percentage is over the DB side only: shown rows' db bytes
     * against the whole table census — on-disk files live outside the
     * SQLite numbers that percentage describes. Strictly read-only: one
     * shared `mode=ro` connect arm, zero writes, zero pruning.
     */
    private static function gcSessions(ParsedArgs $args): int
    {
        $spelled = isset($args->subcommandFlags['--format']) ? (string) $args->subcommandFlags['--format'] : NonInteractive::FORMAT_TEXT;
        if ($spelled !== NonInteractive::FORMAT_TEXT && $spelled !== NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage('candy-code: ' . Lang::t('cli.gc.bad_format', ['format' => $spelled]), $args->outputFormat, self::gcUsage());
        }
        $asJson = $spelled === NonInteractive::FORMAT_JSON || $args->outputFormat === NonInteractive::FORMAT_JSON;

        $sort = isset($args->subcommandFlags['--sort']) ? \strtolower((string) $args->subcommandFlags['--sort']) : 'size';
        if (!\in_array($sort, ['size', 'age', 'name'], true)) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.bad_sort', ['sort' => (string) $args->subcommandFlags['--sort']]),
                $args->outputFormat,
                self::gcUsage(),
            );
        }
        $limitSpelled = isset($args->subcommandFlags['--limit']) ? (string) $args->subcommandFlags['--limit'] : '0';
        if (\preg_match('/^(0|[1-9]\d{0,8})$/', $limitSpelled) !== 1) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.bad_limit', ['limit' => $limitSpelled]),
                $args->outputFormat,
                self::gcUsage(),
            );
        }
        $limit = (int) $limitSpelled;
        $includeArchived = isset($args->subcommandFlags['--archived']);
        $onlyChildren = isset($args->subcommandFlags['--children']);

        $storePath = self::gcStorePath();
        try {
            $inventory = new GcInventory($storePath);
            $census = $inventory->collect(Bootstrap::sessionRetentionDays());
            $fold = (new GcSessionSizes($inventory->openReadOnly(), \dirname($storePath)))->fold();
        } catch (\PDOException $e) {
            return self::gcStoreUnavailable($args, $asJson, 'sessions', $e->getMessage());
        }

        $rows = [];
        foreach (self::gcDisplayRows($fold['sessions']) as $row) {
            if (!$includeArchived && $row['archived']) {
                continue;
            }
            if ($onlyChildren && $row['parent_id'] === null) {
                continue;
            }
            $rows[] = $row;
        }
        $compare = match ($sort) {
            'age' => static fn (array $a, array $b): int
                => ($a['created_epoch'] ?? \PHP_INT_MAX) <=> ($b['created_epoch'] ?? \PHP_INT_MAX)
                    ?: \strcmp((string) $a['id'], (string) $b['id']),
            'name' => static fn (array $a, array $b): int
                => \strcasecmp((string) $a['title'], (string) $b['title'])
                    ?: \strcmp((string) $a['id'], (string) $b['id']),
            default => self::gcCompareBySize(...),
        };
        \usort($rows, $compare);
        $shown = $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;

        $dbBytes = $journalBytes = $shadowBytes = 0;
        foreach ($shown as $row) {
            $dbBytes += (int) $row['db_bytes'];
            $journalBytes += (int) $row['journal_bytes'];
            $shadowBytes += (int) $row['shadow_bytes'];
        }
        $censusBytes = 0;
        foreach ($census['tables'] as $table) {
            $censusBytes += (int) $table['bytes'];
        }
        $totalBytes = $dbBytes + $journalBytes + $shadowBytes;
        $share = $censusBytes > 0 ? \round(100.0 * $dbBytes / $censusBytes, 1) : 0.0;
        $now = \time();

        if ($asJson) {
            $document = [];
            foreach ($shown as $row) {
                $document[] = [
                    'id' => (string) $row['id'],
                    'kind' => (string) $row['kind'],
                    'parent_id' => $row['parent_id'],
                    'pinned' => (bool) $row['pinned'],
                    'archived' => (bool) $row['archived'],
                    'created_at' => $row['created_at'],
                    'title' => (string) $row['title'],
                    'db_bytes' => (int) $row['db_bytes'],
                    'journal_bytes' => (int) $row['journal_bytes'],
                    'shadow_bytes' => (int) $row['shadow_bytes'],
                    'shadow_dir_count' => (int) $row['shadow_dir_count'],
                    'total_bytes' => (int) $row['total_bytes'],
                ];
            }
            self::emitDocument([
                'verb' => 'gc',
                'action' => 'sessions',
                'result' => [
                    'sort' => $sort,
                    'limit' => $limit,
                    'include_archived' => $includeArchived,
                    'only_children' => $onlyChildren,
                    'shown' => \count($shown),
                    'session_count' => \count($rows),
                    'rows' => $document,
                    'totals' => [
                        'db_bytes' => $dbBytes,
                        'journal_bytes' => $journalBytes,
                        'shadow_bytes' => $shadowBytes,
                        'total_bytes' => $totalBytes,
                        'census_table_bytes' => $censusBytes,
                        'db_share_percent' => $share,
                    ],
                    'fold' => [
                        'attributed_db_bytes' => (int) $fold['attributed_db_bytes'],
                        'unattributed_db_bytes' => (int) $fold['unattributed_db_bytes'],
                        'fts_attributed_bytes' => (int) $fold['fts_attributed_bytes'],
                        'fts_residual_bytes' => (int) $fold['fts_residual_bytes'],
                        'tombstone_bytes' => (int) $fold['tombstone_bytes'],
                    ],
                ],
            ]);

            return NonInteractive::EXIT_OK;
        }

        echo 'candy-code gc sessions', \PHP_EOL;
        echo \sprintf('%-12s  %-8s  %-8s  %-40s  %s', 'ID', 'FLAGS', 'AGE', 'TITLE', 'SIZE  (db / on-disk)'), \PHP_EOL;
        foreach ($shown as $row) {
            $cells = self::gcRowCells($row, $now);
            echo Lang::t('cli.gc.sessions_flags', [
                'id' => $cells['id'],
                'flags' => $cells['flags'],
                'age' => $cells['age'],
                'title' => $cells['title'],
                'size' => $cells['size'],
                'db' => self::gcFormatBytes((int) $row['db_bytes']),
                'disk' => self::gcFormatBytes((int) $row['journal_bytes'] + (int) $row['shadow_bytes']),
            ]), \PHP_EOL;
        }
        echo Lang::t('cli.gc.sessions_total', [
            'shown' => (string) \count($shown),
            'count' => (string) \count($rows),
            'size' => self::gcFormatBytes($totalBytes),
            'db' => self::gcFormatBytes($dbBytes),
            'disk' => self::gcFormatBytes($journalBytes + $shadowBytes),
            'share' => \number_format($share, 1),
        ]), \PHP_EOL;

        return NonInteractive::EXIT_OK;
    }

    /**
     * Decorate fold rows with the display fields every gc table needs:
     * the shown title and the epoch the age cell reads. ONE decoration
     * path for both gcSessions and gcAnalyze — no second copy.
     *
     * @param  list<array<string,mixed>> $foldRows
     * @return list<array<string,mixed>>
     */
    private static function gcDisplayRows(array $foldRows): array
    {
        $rows = [];
        foreach ($foldRows as $row) {
            $row['title'] = self::gcSessionTitle($row);
            $row['created_epoch'] = self::gcEpoch($row['created_at']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The size comparator (biggest first, id tiebreak) shared by the
     * gcSessions default sort and the gcAnalyze top-N ordering.
     */
    private static function gcCompareBySize(array $a, array $b): int
    {
        return (int) $b['total_bytes'] <=> (int) $a['total_bytes']
            ?: \strcmp((string) $a['id'], (string) $b['id']);
    }

    /**
     * The fixed-width cells both tables print: clipped id (12), kind and
     * flag stars, coarse age, clipped title (40), size column.
     *
     * @param  array<string,mixed> $row a gcDisplayRows()-decorated row
     * @return array{id:string,flags:string,age:string,title:string,size:string}
     */
    private static function gcRowCells(array $row, int $now): array
    {
        $flags = $row['parent_id'] === null ? 'root' : 'sub';
        if ($row['pinned']) {
            $flags .= ' *';
        }
        if ($row['archived']) {
            $flags .= ' A';
        }

        return [
            'id' => \str_pad(\mb_strimwidth((string) $row['id'], 0, 12, ''), 12),
            'flags' => \str_pad($flags, 8),
            'age' => \str_pad(self::gcHumanAge($row['created_epoch'], $now), 8, ' ', STR_PAD_LEFT),
            'title' => \str_pad(\mb_strimwidth((string) $row['title'], 0, 40, '…'), 40),
            'size' => \str_pad(self::gcFormatBytes((int) $row['total_bytes']), 10, ' ', STR_PAD_LEFT),
        ];
    }

    /**
     * The shown title of a session: its name when it carries one, else the
     * first line of the stored preview (never the last message body — the
     * fold never reads message payloads beyond LENGTH()).
     */
    private static function gcSessionTitle(array $row): string
    {
        $name = \trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $preview = \trim((string) ($row['last_preview'] ?? ''));

        return \trim(\explode("\n", $preview)[0] ?? '');
    }

    /**
     * Epoch from whatever a legacy row carries: an int/numeric string stays
     * itself, a DATETIME string parses, anything unparseable is null (age
     * then reads '-'). No timezone games: strtotime's answer is the display.
     */
    private static function gcEpoch(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $text = (string) $value;
        if (\ctype_digit($text)) {
            return (int) $text;
        }
        $parsed = \strtotime($text);

        return $parsed === false ? null : $parsed;
    }

    /**
     * Coarse age cell in the status histogram's vocabulary: d/h/m/s.
     */
    private static function gcHumanAge(?int $createdAt, int $now): string
    {
        if ($createdAt === null) {
            return '-';
        }
        $seconds = \max(0, $now - $createdAt);
        if ($seconds >= 86400) {
            return \intdiv($seconds, 86400) . 'd';
        }
        if ($seconds >= 3600) {
            return \intdiv($seconds, 3600) . 'h';
        }
        if ($seconds >= 60) {
            return \intdiv($seconds, 60) . 'm';
        }

        return $seconds . 's';
    }

    /**
     * `gc analyze`: the janitor overview — top sessions by real bytes,
     * a labeled growth rate, per-table and per-storage bars, the
     * root-vs-subagent split, and the ORPHAN census (report-only).
     *
     * Deliberate asymmetry with `gc sessions`: analyze shows EVERY
     * session, archives included — a janitor deciding what to purge must
     * see the whole floor, and the archived flag is right there in the
     * FLAGS column. The growth line measures ONLY the bytes the
     * usage_records and session_events tables carry (the labeled scope)
     * and needs at least two distinct active days, or it says honestly
     * that growth is not measurable. The orphan sections mirror the
     * tombstone law of {@see GcOrphanCensus}: a row whose session died
     * inside the grace window is NOT-yet-orphan. Every section prints
     * COUNT + BYTES so `gc purge` (the last rung) has its target
     * surface defined here; cleanup itself NEVER happens in this verb —
     * diagnostics prune nothing. The teams rows are REPORT-ONLY by owner
     * ruling (gc-cli-1106 4b): the count is shown, no cleanup is
     * promised, and `--clean-teams` deliberately does not exist — the
     * action door refuses it like any flag `analyze` does not speak.
     */
    private static function gcAnalyze(ParsedArgs $args): int
    {
        $spelled = isset($args->subcommandFlags['--format']) ? (string) $args->subcommandFlags['--format'] : NonInteractive::FORMAT_TEXT;
        if ($spelled !== NonInteractive::FORMAT_TEXT && $spelled !== NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage('candy-code: ' . Lang::t('cli.gc.bad_format', ['format' => $spelled]), $args->outputFormat, self::gcUsage());
        }
        $asJson = $spelled === NonInteractive::FORMAT_JSON || $args->outputFormat === NonInteractive::FORMAT_JSON;

        $topSpelled = isset($args->subcommandFlags['--top']) ? (string) $args->subcommandFlags['--top'] : '10';
        if (\preg_match('/^[1-9]\d{0,8}$/', $topSpelled) !== 1) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.bad_top', ['top' => $topSpelled]),
                $args->outputFormat,
                self::gcUsage(),
            );
        }
        $top = (int) $topSpelled;

        $storePath = self::gcStorePath();
        $now = \time();
        try {
            $inventory = new GcInventory($storePath);
            $census = $inventory->collect(Bootstrap::sessionRetentionDays());
            $connection = $inventory->openReadOnly();
            $fold = (new GcSessionSizes($connection, \dirname($storePath)))->fold();
            $orphans = (new GcOrphanCensus($connection, \dirname($storePath)))->census($now);
        } catch (\PDOException $e) {
            return self::gcStoreUnavailable($args, $asJson, 'analyze', $e->getMessage());
        }

        $rows = self::gcDisplayRows($fold['sessions']);
        \usort($rows, self::gcCompareBySize(...));

        $dbBytes = $journalBytes = $shadowBytes = $grandTotal = 0;
        $rootCount = $subagentCount = 0;
        $rootBytes = $subagentBytes = 0;
        foreach ($rows as $row) {
            $dbBytes += (int) $row['db_bytes'];
            $journalBytes += (int) $row['journal_bytes'];
            $shadowBytes += (int) $row['shadow_bytes'];
            $grandTotal += (int) $row['total_bytes'];
            if ($row['parent_id'] === null) {
                ++$rootCount;
                $rootBytes += (int) $row['db_bytes'];
            } else {
                ++$subagentCount;
                $subagentBytes += (int) $row['db_bytes'];
            }
        }
        $censusBytes = 0;
        $tableBytes = [];
        foreach ($census['tables'] as $table) {
            $censusBytes += (int) $table['bytes'];
            $tableBytes[(string) $table['name']] = (int) $table['bytes'];
        }
        $share = $censusBytes > 0 ? \round(100.0 * $dbBytes / $censusBytes, 1) : 0.0;
        $topRows = \array_slice($rows, 0, $top);
        $teams = $census['storage']['teams'] ?? null;

        // Growth is measured on the ledger tables whose rows accrue per
        // activity (usage_records + session_events) — NOT the whole file,
        // which a single VACUUM reset would make meaningless. The scope
        // travels in the output line so nobody misreads the rate.
        $growth = null;
        $growthScope = ($tableBytes['usage_records'] ?? 0) + ($tableBytes['session_events'] ?? 0);
        if (GcInventory::tablePresent($connection, 'usage_records')) {
            $span = $connection->query('SELECT MIN(ts), MAX(ts), COUNT(DISTINCT DATE(ts, \'unixepoch\')) FROM "usage_records"')->fetch(\PDO::FETCH_NUM);
            $activeDays = $span === false ? 0 : (int) $span[2];
            if ($span !== false && $span[0] !== null && $activeDays >= 2) {
                $growth = [
                    'measured_bytes' => $growthScope,
                    'active_days' => $activeDays,
                    'min_ts' => (int) $span[0],
                    'max_ts' => (int) $span[1],
                    'rate_bytes_per_day' => (int) \round($growthScope / $activeDays),
                ];
            }
        }

        if ($asJson) {
            $document = [];
            foreach ($topRows as $row) {
                $entry = [
                    'id' => (string) $row['id'],
                    'kind' => (string) $row['kind'],
                    'parent_id' => $row['parent_id'],
                    'pinned' => (bool) $row['pinned'],
                    'archived' => (bool) $row['archived'],
                    'created_at' => $row['created_at'],
                    'title' => (string) $row['title'],
                    'db_bytes' => (int) $row['db_bytes'],
                    'journal_bytes' => (int) $row['journal_bytes'],
                    'shadow_bytes' => (int) $row['shadow_bytes'],
                    'shadow_dir_count' => (int) $row['shadow_dir_count'],
                    'total_bytes' => (int) $row['total_bytes'],
                ];
                $entry['percent'] = $grandTotal > 0 ? \round(100.0 * (int) $row['total_bytes'] / $grandTotal, 1) : 0.0;
                $document[] = $entry;
            }
            self::emitDocument([
                'verb' => 'gc',
                'action' => 'analyze',
                'result' => [
                    'top_requested' => $top,
                    'session_count' => \count($rows),
                    'grand_total_bytes' => $grandTotal,
                    'top' => $document,
                    'totals' => [
                        'db_bytes' => $dbBytes,
                        'journal_bytes' => $journalBytes,
                        'shadow_bytes' => $shadowBytes,
                        'total_bytes' => $grandTotal,
                        'census_table_bytes' => $censusBytes,
                        'db_share_percent' => $share,
                    ],
                    'growth' => $growth,
                    'tables' => $census['tables'],
                    'storage' => $census['storage'],
                    'kinds' => [
                        'root' => ['count' => $rootCount, 'bytes' => $rootBytes],
                        'subagent' => ['count' => $subagentCount, 'bytes' => $subagentBytes],
                    ],
                    'orphans' => $orphans + [
                        'teams' => [
                            'count' => (int) ($teams['files'] ?? 0),
                            'bytes' => (int) ($teams['bytes'] ?? 0),
                        ],
                    ],
                    'fold' => [
                        'attributed_db_bytes' => (int) $fold['attributed_db_bytes'],
                        'unattributed_db_bytes' => (int) $fold['unattributed_db_bytes'],
                        'fts_attributed_bytes' => (int) $fold['fts_attributed_bytes'],
                        'fts_residual_bytes' => (int) $fold['fts_residual_bytes'],
                        'tombstone_bytes' => (int) $fold['tombstone_bytes'],
                    ],
                ],
            ]);

            return NonInteractive::EXIT_OK;
        }

        echo 'candy-code gc analyze', \PHP_EOL;
        echo Lang::t('cli.gc.analyze_top', [
            'top' => (string) \count($topRows),
            'count' => (string) \count($rows),
            'total' => self::gcFormatBytes($grandTotal),
        ]), \PHP_EOL;
        echo \sprintf('%-12s  %-8s  %-8s  %-40s  %10s  %6s', 'ID', 'FLAGS', 'AGE', 'TITLE', 'SIZE', 'SHARE'), \PHP_EOL;
        foreach ($topRows as $row) {
            $cells = self::gcRowCells($row, $now);
            echo Lang::t('cli.gc.analyze_flags', [
                'id' => $cells['id'],
                'flags' => $cells['flags'],
                'age' => $cells['age'],
                'title' => $cells['title'],
                'size' => $cells['size'],
                'percent' => \number_format($grandTotal > 0 ? 100.0 * (int) $row['total_bytes'] / $grandTotal : 0.0, 1),
            ]), \PHP_EOL;
        }

        echo \PHP_EOL;
        if ($growth !== null) {
            echo Lang::t('cli.gc.analyze_growth', [
                'scope' => 'usage_records + session_events',
                'rate' => self::gcFormatBytes((int) $growth['rate_bytes_per_day']),
                'days' => (string) $growth['active_days'],
                'window' => \date('Y-m-d', (int) $growth['min_ts']) . ' .. ' . \date('Y-m-d', (int) $growth['max_ts']),
            ]), \PHP_EOL;
        } else {
            echo Lang::t('cli.gc.analyze_growth_none'), \PHP_EOL;
        }

        echo Lang::t('cli.gc.analyze_tables_header'), \PHP_EOL;
        $largestTable = 0;
        foreach ($census['tables'] as $table) {
            $largestTable = \max($largestTable, (int) $table['bytes']);
        }
        foreach ($census['tables'] as $table) {
            if (!$table['present']) {
                continue;
            }
            echo \sprintf(
                '  %-24s  %10s  %s',
                (string) $table['name'],
                self::gcFormatBytes((int) $table['bytes']),
                self::gcBar((int) $table['bytes'], $largestTable, (int) $census['bar_cells']),
            ), \PHP_EOL;
        }

        echo Lang::t('cli.gc.analyze_storage_header'), \PHP_EOL;
        $largestFamily = 0;
        foreach ($census['storage'] as $family) {
            $largestFamily = \max($largestFamily, (int) $family['bytes']);
        }
        foreach ($census['storage'] as $name => $family) {
            if (!$family['present']) {
                continue;
            }
            echo \sprintf(
                '  %-14s  %10s  %s',
                (string) $name,
                self::gcFormatBytes((int) $family['bytes']),
                self::gcBar((int) $family['bytes'], $largestFamily, (int) $census['bar_cells']),
            ), \PHP_EOL;
        }

        echo Lang::t('cli.gc.analyze_kinds_header'), \PHP_EOL;
        echo Lang::t('cli.gc.analyze_kind_row', ['kind' => 'root', 'count' => (string) $rootCount, 'bytes' => self::gcFormatBytes($rootBytes)]), \PHP_EOL;
        echo Lang::t('cli.gc.analyze_kind_row', ['kind' => 'subagent', 'count' => (string) $subagentCount, 'bytes' => self::gcFormatBytes($subagentBytes)]), \PHP_EOL;

        echo Lang::t('cli.gc.analyze_orphans_header'), \PHP_EOL;
        $orphanRows = [
            'cli.gc.orphans_journals' => $orphans['journals'],
            'cli.gc.orphans_events' => $orphans['events'],
            'cli.gc.orphans_usage_records' => $orphans['usage_records'],
            'cli.gc.orphans_session_imports' => $orphans['session_imports'],
            'cli.gc.orphans_stale_locks' => $orphans['stale_locks'],
            'cli.gc.orphans_stale_shares' => $orphans['stale_shares'],
            'cli.gc.orphans_abandoned_shadows' => $orphans['abandoned_shadows'],
            'cli.gc.orphans_expired_tombstones' => $orphans['expired_tombstones'],
        ];
        foreach ($orphanRows as $key => $cell) {
            echo Lang::t($key, ['count' => (string) $cell['count'], 'bytes' => self::gcFormatBytes((int) $cell['bytes'])]), \PHP_EOL;
        }
        echo Lang::t('cli.gc.orphans_teams', [
            'count' => (string) (int) ($teams['files'] ?? 0),
            'bytes' => self::gcFormatBytes((int) ($teams['bytes'] ?? 0)),
        ]), \PHP_EOL;

        return NonInteractive::EXIT_OK;
    }

    /**
     * `gc vacuum`: reclaim free pages.
     *
     * The no-force shape is the report the user needs to decide: how many
     * bytes are free, what `auto_vacuum` is set to, and an ESTIMATE (labeled
     * as one) of the file size after a rewrite — computed as db bytes minus
     * the free pages, the dbstat-free heuristic. Without `--force` nothing is
     * rewritten, whatever flags came along, and the exit is 0: the report is
     * the answer that was asked for.
     *
     * With `--force` the rewrite goes through {@see GcVacuum}: a plain
     * `VACUUM`, or the one-time `auto_vacuum = INCREMENTAL` flip (which only
     * takes effect through a full rewrite — the disclosure line below states
     * exactly that), or — when the regime is already incremental — the cheap
     * `PRAGMA incremental_vacuum` drain. A rewrite wants about the file's own
     * size in transient space; the advisory is printed before the rewrite
     * and the attempt is never refused on the estimate alone. A store held
     * by a running session answers with the lock advice line whose holder
     * count is the flock ground truth — never a process-name guess.
     * `--check` together with `--force` is a usage error (exit 2): one spells
     * "only look", the other "only change", and silently preferring either
     * over the other would make the flag pair a coin toss.
     */
    private static function gcVacuum(ParsedArgs $args): int
    {
        $asJson = $args->outputFormat === NonInteractive::FORMAT_JSON;
        $check = isset($args->subcommandFlags['--check']);
        $incremental = isset($args->subcommandFlags['--incremental']);
        $force = isset($args->subcommandFlags['--force']) || isset($args->subcommandFlags['-f']);
        $dbPath = self::gcStorePath();

        if ($check && $force) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.vacuum.flags_conflict'),
                $args->outputFormat,
                self::gcUsage(),
            );
        }

        if (!$force) {
            try {
                $inventory = (new GcInventory($dbPath))->collect(Bootstrap::sessionRetentionDays());
            } catch (\PDOException $e) {
                return self::gcStoreUnavailable($args, $asJson, 'vacuum', $e->getMessage());
            }

            $reclaimable = (int) $inventory['reclaimable_bytes'];
            $estimate = \max(0, (int) $inventory['files']['db'] - $reclaimable);
            $redFlag = (bool) $inventory['flags']['events_exceed_transcripts'];

            if ($asJson) {
                self::emitDocument(['verb' => 'gc', 'action' => 'vacuum', 'result' => [
                    'performed' => false,
                    'check' => $check,
                    'requested_mode' => $incremental ? 'incremental' : 'full',
                    'files' => $inventory['files'],
                    'reclaimable_bytes' => $reclaimable,
                    'auto_vacuum' => (int) $inventory['pragma']['auto_vacuum'],
                    'auto_vacuum_label' => (string) $inventory['pragma']['auto_vacuum_label'],
                    'freelist_count' => (int) $inventory['pragma']['freelist_count'],
                    'estimated_db_bytes_after' => $estimate,
                    'red_flag_events' => $redFlag,
                ]]);

                return NonInteractive::EXIT_OK;
            }

            echo 'candy-code gc vacuum', $incremental ? ' [--incremental]' : '', \PHP_EOL;
            echo Lang::t('cli.gc.vacuum.reclaimable', [
                'reclaimable' => self::gcFormatBytes($reclaimable),
                'auto_vacuum' => (string) $inventory['pragma']['auto_vacuum_label'],
                'estimate' => self::gcFormatBytes($estimate),
            ]), \PHP_EOL;
            if ($redFlag) {
                $bytesByName = [];
                foreach ($inventory['tables'] as $table) {
                    $bytesByName[(string) $table['name']] = (int) $table['bytes'];
                }
                echo Lang::t('cli.gc.events_flag', [
                    'events' => self::gcFormatBytes($bytesByName['session_events'] ?? 0),
                    'transcripts' => self::gcFormatBytes($bytesByName['session_transcripts'] ?? 0),
                ]), \PHP_EOL;
            }
            echo Lang::t('cli.gc.vacuum.no_rewrite'), \PHP_EOL;

            return NonInteractive::EXIT_OK;
        }

        $before = GcStoreFiles::sizes($dbPath);
        if (!$before['present']) {
            return self::gcStoreUnavailable($args, $asJson, 'vacuum', 'no such file: ' . $dbPath);
        }

        if (!$asJson) {
            \fwrite(\STDERR, 'candy-code: ' . Lang::t('cli.gc.vacuum.headroom', [
                'needed' => self::gcFormatBytes((int) $before['total']),
                'free' => self::gcFormatBytes(GcStoreFiles::freeSpace($dbPath)),
            ]) . "\n");
        }

        try {
            $report = (new GcVacuum($dbPath))->run($incremental);
        } catch (\PDOException $e) {
            $message = $e->getMessage();
            if (\stripos($message, 'database is locked') !== false || \stripos($message, 'busy') !== false) {
                $holders = self::gcLiveLockCount();
                $notice = Lang::t('cli.gc.vacuum.locked', ['holders' => (string) $holders]);
                \fwrite(\STDERR, 'candy-code: ' . $notice . "\n");
                if ($asJson) {
                    self::emitDocument(['verb' => 'gc', 'action' => 'vacuum', 'result' => [
                        'performed' => false,
                        'reason' => $notice,
                    ]]);
                }

                return NonInteractive::EXIT_FAILURE;
            }

            return self::gcStoreUnavailable($args, $asJson, 'vacuum', $message);
        }

        if ($asJson) {
            self::emitDocument(['verb' => 'gc', 'action' => 'vacuum', 'result' => $report]);

            return NonInteractive::EXIT_OK;
        }

        echo Lang::t('cli.gc.vacuum.done', [
            'mode' => (string) $report['mode'],
            'before' => self::gcFormatBytes((int) $report['before']['total']),
            'after' => self::gcFormatBytes((int) $report['after']['total']),
            'pages' => (string) $report['pages_released'],
        ]), \PHP_EOL;
        if ($report['flipped']) {
            echo Lang::t('cli.gc.vacuum.flipped', [
                'from' => (string) $report['auto_vacuum_before'],
                'to' => (string) $report['auto_vacuum_after'],
            ]), \PHP_EOL;
        }

        return NonInteractive::EXIT_OK;
    }

    /**
    /**
     * `gc purge` — the destructive rung of the ladder (gc-cli-1106 B3).
     *
     * Three verbs hide behind one action, and they are deliberately NOT
     * all-or-nothing together. FILTERS (--older-than, --session,
     * --subagents, --larger-than, --keep-latest, --archived) select a set
     * of whole sessions; STRIPS (--strip-events/_usage/_checkpoints/
     * _journal/_fts) hollow matched rows out but KEEP the session row;
     * CLEANS (--clean-orphan-journals/_events/_usage, --clean-locks,
     * --clean-shares, --clean-imports, --clean-shadows,
     * --clean-expired-tombstones) are store-wide and independent of any
     * filter — they always target exactly what GcOrphanCensus accuses,
     * never a re-derived rule (the census is the single source of truth
     * for orphanhood; purge only ever deletes rows/paths the SAME listers
     * on this connection just accused).
     *
     * Delete mode is precise: whole-session deletes fire when at least
     * one filter is set AND no strip flag is set (a strip present means
     * the caller wants the row hollowed, not removed — the row survives
     * every strip, pinned by tests). Named+pinned sessions are exempt
     * from deletes by default (ruling 3a) — the exemption predicate is
     * the INVERSE of the retention law in SessionStore::pruneSessions
     * (victim = unnamed-or-auto-titled AND unpinned; so exempt = pinned,
     * or a real name with a non-'auto' title source), and --include-named
     * overrides it. Strips DO act on exempt sessions; the exemption is
     * delete-only.
     *
     * Safety model, in order: (1) plan phase opens ONLY the read-only
     * connection and derives the full plan; --dry-run prints it and
     * leaves — a dry run never opens a read-write connection at all.
     * (2) machine callers must be explicit: JSON mode (--format json or
     * the global --output-format json) REQUIRES --dry-run or --force,
     * because an interactive confirm prompt has no meaning on a wire
     * consumer (exit 2 otherwise). (3) without --force the run CONFIRMS
     * at the tty, mirroring the settings-import prompt idiom — except
     * that decline AND non-tty refusal both return EXIT_OK per the spec
     * law 'success and decline exit 0' (the import path's EXIT_FAILURE
     * for a missing tty is a refusal-to-run; here it is a decline,
     * recorded deviation). (4) --force skips the confirm and crosses the
     * live-flock gate: without it, sessions whose lock is held right now
     * are REFUSED from both deletes and strips and named in the output
     * (same hazard family as deleting under a live writer — stripping a
     * mid-turn session's events would blind that turn; adjudicated
     * stricter than the spec's delete-only refusal); with --force they
     * are included behind a loud disclosure line.
     *
     * Execution phase: a read-write connection via the sanctioned
     * SqliteConnect arm, then the same plan derivation on it. Cleans run
     * FIRST in the fixed order (journals, events, usage, imports, expired
     * tombstones, locks, shares, shadows), then strips, then whole-
     * session deletes routed EXCLUSIVELY through EnhancedSessionStore::
     * deleteSession() — the house path whose tombstone insert, share
     * revoke, workspace-ref drop, blob de-intern and FTS-forget laws
     * ride it (purge NEVER mints a raw DELETE FROM sessions; the store's
     * own journal files survive that path, so purge unlinks the matched
     * journals itself afterwards). Each DB family is one logical
     * transaction; file unlinks happen AFTER their DB commit, are best-
     * effort, and swallow missing files. The store is opened with
     * prune: false — purge must never trigger the retention sweep the
     * session verbs run (spec law (h)). Whole-session deletes also reap
     * EXPIRED tombstones as a side effect (deleteSessionTrees self-reaps
     * them); the explicit --clean-expired-tombstones flag keeps that
     * reaping available to cleans-only runs and uses the census rule.
     *
     * Zero-op (`gc purge` with no filters, no strips, no cleans) is a
     * usage error: the spec text contradicts itself (its action section
     * sketches exit 1, its cross-cutting law routes every usage error
     * through failUsage exit 2); the orchestrator adjudicated EXIT 2 —
     * consistent with every other door on this verb. Vacuum is NOT part
     * of purge: after deletes the run only PRINTS the reclaim hint.
     *
     * @return int EXIT_OK plan printed/executed (or declined),
     *               EXIT_CONFIG usage errors and the JSON-mode door,
     *               EXIT_FAILURE missing/corrupt store and unresolved
     *               --session targets
     */
    private static function gcPurge(ParsedArgs $args): int
    {
        $spelledFormat = isset($args->subcommandFlags['--format'])
            ? (string) $args->subcommandFlags['--format']
            : NonInteractive::FORMAT_TEXT;
        if ($spelledFormat !== NonInteractive::FORMAT_TEXT && $spelledFormat !== NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.bad_format', ['format' => $spelledFormat]),
                $args->outputFormat,
                self::gcUsage()
            );
        }
        $asJson = $spelledFormat === NonInteractive::FORMAT_JSON
            || $args->outputFormat === NonInteractive::FORMAT_JSON;
        $dryRun = isset($args->subcommandFlags['--dry-run']) || isset($args->subcommandFlags['-n']);
        $force = isset($args->subcommandFlags['--force']) || isset($args->subcommandFlags['-f']);

        // Machine-driven JSON has nobody to answer the confirm prompt, so
        // it must arrive pre-decided: plan (--dry-run) or act (--force).
        if ($asJson && !$dryRun && !$force) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.purge.json_needs_mode'),
                $args->outputFormat,
                self::gcUsage()
            );
        }

        $filters = [];
        foreach (['--older-than', '--session', '--subagents', '--larger-than', '--keep-latest', '--archived'] as $flag) {
            if (isset($args->subcommandFlags[$flag])) {
                $filters[] = $flag;
            }
        }
        $strips = [];
        foreach (['--strip-events', '--strip-usage', '--strip-checkpoints', '--strip-journal', '--strip-fts'] as $flag) {
            if (isset($args->subcommandFlags[$flag])) {
                $strips[] = $flag;
            }
        }
        $cleans = [];
        foreach (['--clean-orphan-journals', '--clean-orphan-events', '--clean-orphan-usage', '--clean-locks', '--clean-shares', '--clean-imports', '--clean-shadows', '--clean-expired-tombstones'] as $flag) {
            if (isset($args->subcommandFlags[$flag])) {
                $cleans[] = $flag;
            }
        }

        // The zero-op door (see docblock: adjudicated exit 2).
        if ($filters === [] && $strips === [] && $cleans === []) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.gc.purge.zero_op'),
                $args->outputFormat,
                self::gcUsage()
            );
        }

        // Flag validation doors BEFORE any store touch — bad forms cost 2.
        $olderSeconds = null;
        if (in_array('--older-than', $filters, true)) {
            $value = (string) $args->subcommandFlags['--older-than'];
            if (\preg_match('/^(\d+)(m|h|d|w|mo)$/', $value, $match) !== 1) {
                return NonInteractive::failUsage(
                    'candy-code: ' . Lang::t('cli.gc.purge.bad_older_than', ['value' => $value]),
                    $args->outputFormat,
                    self::gcUsage()
                );
            }
            $units = ['m' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, 'mo' => 2592000];
            $olderSeconds = (int) $match[1] * $units[$match[2]];
        }
        $largerBytes = null;
        if (in_array('--larger-than', $filters, true)) {
            $value = (string) $args->subcommandFlags['--larger-than'];
            if (\preg_match('/^(\d+(?:\.\d+)?)(k|m|g|kb|mb|gb)$/i', $value, $match) !== 1) {
                return NonInteractive::failUsage(
                    'candy-code: ' . Lang::t('cli.gc.purge.bad_larger_than', ['value' => $value]),
                    $args->outputFormat,
                    self::gcUsage()
                );
            }
            $scales = ['k' => 1024, 'kb' => 1024, 'm' => 1048576, 'mb' => 1048576, 'g' => 1073741824, 'gb' => 1073741824];
            $largerBytes = (int) ((float) $match[1] * $scales[\strtolower($match[2])]);
        }
        $keepLatest = null;
        if (in_array('--keep-latest', $filters, true)) {
            $value = (string) $args->subcommandFlags['--keep-latest'];
            if (\preg_match('/^(0|[1-9]\d{0,8})$/', $value) !== 1) {
                return NonInteractive::failUsage(
                    'candy-code: ' . Lang::t('cli.gc.purge.bad_keep_latest', ['value' => $value]),
                    $args->outputFormat,
                    self::gcUsage()
                );
            }
            $keepLatest = (int) $value;
        }
        // Repeated --session flags arrive comma-JOINED into one string by
        // the parser (ParsedArgs::REPEATABLE_SUBCOMMAND_FLAGS): split,
        // trim, drop empties. `--session=abc,def` and two flags behave
        // identically.
        $tokens = [];
        if (in_array('--session', $filters, true)) {
            foreach (\explode(',', (string) $args->subcommandFlags['--session']) as $token) {
                $token = \trim($token);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }
        $deleteMode = $filters !== [] && $strips === [];
        $selectors = [
            'older' => $olderSeconds,
            'larger' => $largerBytes,
            'keep' => $keepLatest,
            'tokens' => $tokens,
            'subagents' => in_array('--subagents', $filters, true),
            'archived' => in_array('--archived', $filters, true),
            'includeNamed' => isset($args->subcommandFlags['--include-named']),
            'deleteMode' => $deleteMode,
            'strips' => $strips,
            'cleans' => $cleans,
        ];

        $storePath = self::gcStorePath();
        $configDir = \dirname($storePath);
        $now = \time();

        // ---- PLAN phase: read-only connection, always. --dry-run ends here.
        try {
            $inventory = new GcInventory($storePath);
            $census = $inventory->collect(Bootstrap::sessionRetentionDays());
            $plan = self::gcPurgePlan($inventory->openReadOnly(), $configDir, $now, $selectors, $args, $asJson);
        } catch (\PDOException $error) {
            return self::gcStoreUnavailable($args, $asJson, 'purge', $error->getMessage());
        }
        if (\is_int($plan)) {
            return $plan; // resolution door already reported (exit 1/2).
        }

        $totals = self::gcPurgeCensusTotals($plan['census'], $cleans);
        if ($dryRun) {
            self::gcPurgeReport($asJson, $plan, $cleans, $totals, true, false, (int) $census['reclaimable_bytes'], $now);
            return NonInteractive::EXIT_OK;
        }

        // ---- CONFIRM gate (mirror of the settings-import idiom, with the
        // recorded deviation: decline AND non-tty both exit 0 — on this
        // verb "I won't answer" IS a decline, and declines exit 0 by law).
        if (!$force) {
            self::gcPurgeReport($asJson, $plan, $cleans, $totals, false, false, (int) $census['reclaimable_bytes'], $now);
            if (!\is_resource(\STDIN) || !\stream_isatty(\STDIN)) {
                echo Lang::t('cli.gc.purge.confirm_required') . "\n";
                return NonInteractive::EXIT_OK;
            }
            echo Lang::t('cli.gc.purge.confirm_ask') . "\n";
            $answer = \strtolower(\trim((string) \fgets(\STDIN)));
            if ($answer !== 'y' && $answer !== 'yes') {
                echo Lang::t('cli.gc.purge.confirm_declined') . "\n";
                return NonInteractive::EXIT_OK;
            }
        }

        // ---- EXECUTE phase. The store route runs with prune: false — a
        // purge must never sweep retention on the side (spec law (h)) —
        // and owns its own connection; targeted family work runs on a
        // separate sanctioned-arm connection. The plan is re-derived on
        // read-write truth after the confirm, so nothing planned under a
        // race executes blind.
        try {
            $store = Bootstrap::sessionStore(prune: false);
            $rw = SqliteConnect::open($storePath);
            $plan = self::gcPurgePlan($rw, $configDir, $now, $selectors, $args, $asJson);
        } catch (\PDOException $error) {
            return self::gcStoreUnavailable($args, $asJson, 'purge', $error->getMessage());
        }
        if (\is_int($plan)) {
            return $plan;
        }

        $censusBefore = $plan['census'];
        // Under --force the plan-phase locked list records the locks this
        // run deliberately crosses; the execute re-truth overwrites
        // plan['locked'] afterwards, so capture the crossed set now.
        $crossed = $force ? $plan['locked'] : [];
        $lockedExecute = $plan['locked'];
        if ($force) {
            $lockedExecute = [];
        } else {
            // The store's own flock probe is the execution truth per id;
            // the plan-phase per-file probe already agreed with it
            // seconds ago, and non-force excludes either way.
            foreach ($plan['acted'] as $id) {
                if ($store->liveLockHolder($id) !== null && !\in_array($id, $lockedExecute, true)) {
                    $lockedExecute[] = $id;
                }
            }
        }
        $deleteIds = \array_values(\array_diff($plan['deleteIds'], $lockedExecute));
        $stripIds = \array_values(\array_diff($plan['stripIds'], $lockedExecute));

        $before = GcStoreFiles::sizes($storePath);
        $deleted = ['sessions' => 0, 'journal_files' => 0];
        $stripped = ['events_rows' => 0, 'usage_rows' => 0, 'checkpoint_rows' => 0, 'journal_files' => 0, 'search_rows' => 0];
        $cleaned = ['journal_files' => 0, 'events_rows' => 0, 'usage_rows' => 0, 'lock_files' => 0, 'share_rows' => 0, 'import_rows' => 0, 'shadow_dirs' => 0, 'tombstone_rows' => 0];
        $releasedFileBytes = 0;

        // Cleans run FIRST, in the fixed order, against fresh accusations
        // on the read-write connection (GcOrphanCensus is the sole
        // accuser — purge deletes exactly what its listers return).
        $accuser = new GcOrphanCensus($rw, $configDir);
        if (in_array('--clean-orphan-journals', $cleans, true)) {
            foreach ($accuser->orphanJournalPaths($now) as $paths) {
                foreach ($paths as $path) {
                    $size = \is_file($path) && !\is_link($path) ? (int) \filesize($path) : 0;
                    if (@\unlink($path)) {
                        ++$cleaned['journal_files'];
                        $releasedFileBytes += $size;
                    }
                }
            }
        }
        foreach ([['--clean-orphan-events', 'session_events', 'events_rows'], ['--clean-orphan-usage', 'usage_records', 'usage_rows'], ['--clean-imports', 'session_imports', 'import_rows']] as [$flag, $table, $tally]) {
            if (!in_array($flag, $cleans, true)) {
                continue;
            }
            $target = $accuser->orphanRowTarget($table, $now);
            if ($target === null) {
                continue; // table absent or family not accusable
            }
            $rw->beginTransaction();
            try {
                $statement = $rw->prepare('DELETE FROM "' . $table . '" WHERE ' . $target[0]);
                $statement->execute($target[1]);
                $cleaned[$tally] += $statement->rowCount();
                $rw->commit();
            } catch (\PDOException $error) {
                $rw->rollBack();

                return self::gcStoreUnavailable($args, $asJson, 'purge', $error->getMessage());
            }
        }
        if (in_array('--clean-expired-tombstones', $cleans, true)) {
            $target = $accuser->expiredTombstoneTarget($now);
            if ($target !== null) {
                $rw->beginTransaction();
                try {
                    $statement = $rw->prepare('DELETE FROM "session_tombstones" WHERE ' . $target[0]);
                    $statement->execute($target[1]);
                    $cleaned['tombstone_rows'] += $statement->rowCount();
                    $rw->commit();
                } catch (\PDOException $error) {
                    $rw->rollBack();

                    return self::gcStoreUnavailable($args, $asJson, 'purge', $error->getMessage());
                }
            }
        }
        if (in_array('--clean-locks', $cleans, true)) {
            // Re-probe each file immediately before unlinking it: a holder
            // can appear between listing and acting (GcInventory law).
            foreach ($accuser->listStaleLockPaths() as $path) {
                if (self::gcLockIsHeld($path)) {
                    continue;
                }
                $size = \is_file($path) && !\is_link($path) ? (int) \filesize($path) : 0;
                if (@\unlink($path)) {
                    ++$cleaned['lock_files'];
                    $releasedFileBytes += $size;
                }
            }
        }
        if (in_array('--clean-shares', $cleans, true)) {
            $cleaned['share_rows'] += ShareStore::atPath($storePath)->purgeInertRows();
        }
        if (in_array('--clean-shadows', $cleans, true)) {
            foreach ($accuser->abandonedShadowDirectories($now) as $shadow) {
                $sweep = self::gcPurgeRemoveDirectory((string) $shadow['path']);
                // Report face: an accused-but-empty dir is rmdir'd above yet
                // tallies 0 here; the census face counts every accused dir.
                $cleaned['shadow_dirs'] += $sweep[0] > 0 ? 1 : 0;
                $releasedFileBytes += $sweep[1];
            }
        }

        // Strips next: targeted deletes on the matched set, one logical
        // transaction per family (house chunk shape of 400 placeholders).
        if ($stripIds !== []) {
            if (in_array('--strip-events', $strips, true)) {
                $rw->beginTransaction();
                try {
                    $stripped['events_rows'] += self::gcPurgeChunkedDelete($rw, 'session_events', $stripIds);
                    $rw->commit();
                } catch (\PDOException $error) {
                    $rw->rollBack();

                    return self::gcStoreUnavailable($args, $asJson, 'purge', $error->getMessage());
                }
            }
            if (in_array('--strip-usage', $strips, true)) {
                $rw->beginTransaction();
                try {
                    $stripped['usage_rows'] += self::gcPurgeChunkedDelete($rw, 'usage_records', $stripIds);
                    $rw->commit();
                } catch (\PDOException $error) {
                    $rw->rollBack();

                    return self::gcStoreUnavailable($args, $asJson, 'purge', $error->getMessage());
                }
            }
            if (in_array('--strip-checkpoints', $strips, true)) {
                foreach ($stripIds as $id) {
                    $stripped['checkpoint_rows'] += $store->stripSessionCheckpoints($id);
                }
            }
            if (in_array('--strip-journal', $strips, true)) {
                $journalPaths = GcSessionSizes::scanJournalPaths($configDir);
                foreach ($stripIds as $id) {
                    foreach ($journalPaths[$id] ?? [] as $path) {
                        $size = \is_file($path) && !\is_link($path) ? (int) \filesize($path) : 0;
                        if (@\unlink($path)) {
                            ++$stripped['journal_files'];
                            $releasedFileBytes += $size;
                        }
                    }
                }
            }
            if (in_array('--strip-fts', $strips, true)) {
                // Anchor-table count first (the public forget route reports
                // nothing); then ride the house route, outside any
                // transaction — the index latches itself on failure.
                $stripped['search_rows'] += self::gcPurgeCountSessions($rw, 'session_message_rows', $stripIds);
                SessionSearchIndex::create($rw)->forgetSessions($stripIds);
            }
        }

        // Whole-session deletes LAST, one public store call each — the
        // house path's cascade/revoke/tombstone/blob/FTS laws ride it.
        $deletedJournalFor = [];
        if ($deleteIds !== []) {
            $journalPaths = GcSessionSizes::scanJournalPaths($configDir);
            foreach ($deleteIds as $id) {
                $deletedJournalFor[$id] = $journalPaths[$id] ?? [];
            }
            foreach ($deleteIds as $id) {
                $deleted['sessions'] += \count($store->deleteSession($id));
            }
            // deleteSessionTrees never unlinks transcript mirrors (that is
            // WHY the orphan-journals census exists); purge deletes them
            // for the sessions it removed — best-effort, post-commit.
            foreach ($deletedJournalFor as $paths) {
                foreach ($paths as $path) {
                    $size = \is_file($path) && !\is_link($path) ? (int) \filesize($path) : 0;
                    if (@\unlink($path)) {
                        ++$deleted['journal_files'];
                        $releasedFileBytes += $size;
                    }
                }
            }
        }

        $after = GcStoreFiles::sizes($storePath);
        $plan['locked'] = $lockedExecute;
        $plan['deleteIds'] = $deleteIds;
        $plan['stripIds'] = $stripIds;
        $plan['acted'] = \array_values(\array_unique(\array_merge($deleteIds, $stripIds)));
        $totals = self::gcPurgeCensusTotals($censusBefore, $cleans);
        $freed = \max(0, ((int) $before['total'] - (int) $after['total'])) + $releasedFileBytes;
        self::gcPurgeReport($asJson, $plan, $cleans, $totals, false, true, GcInventory::reclaimableBytes($rw), $now, [
            'deleted' => $deleted,
            'stripped' => $stripped,
            'cleaned' => $cleaned,
            'force_locked' => $crossed,
            'bytes_before' => $before,
            'bytes_after' => $after,
            'freed_bytes' => $freed,
        ]);

        return NonInteractive::EXIT_OK;
    }

    /**
     * The shared purge plan, derived identically on the read-only plan
     * connection and the read-write execute connection so a dry run and
     * a real run never disagree about what WOULD happen.
     *
     * Fold rows lack title_source, so the exemption law (prune-predicate
     * inverse, see gcPurge docblock) rides one direct SELECT when the
     * column exists and degrades to the name-only leg when it does not
     * (older stores). Locked truth is the per-file flock probe; the
     * execute pass re-verifies through the store for its own acted ids.
     *
     * @param array<string,mixed> $selectors
     * @return array<string,mixed>|int plan, or the propagated door exit code
     */
    private static function gcPurgePlan(\PDO $connection, string $configDir, int $now, array $selectors, ParsedArgs $args, bool $asJson): array|int
    {
        $fold = (new GcSessionSizes($connection, $configDir))->fold();
        $rows = self::gcDisplayRows($fold['sessions']);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = $row;
        }

        $wanted = null;
        if ($selectors['tokens'] !== []) {
            $wanted = [];
            foreach ($selectors['tokens'] as $token) {
                $candidates = [];
                if (isset($byId[$token])) {
                    $candidates = [$token];
                } else {
                    foreach ($byId as $id => $row) {
                        if (\is_string($row['name']) && $row['name'] === $token) {
                            $candidates[] = $id;
                        }
                    }
                    if ($candidates === []) {
                        foreach (\array_keys($byId) as $id) {
                            if (\str_starts_with($id, $token)) {
                                $candidates[] = $id;
                            }
                        }
                    }
                }
                if (\count($candidates) > 1) {
                    // Same doors resolveSessionTarget shows for the session
                    // verb (the store-side resolver re-checks at execute).
                    return NonInteractive::failUsage(
                        'candy-code: ' . Lang::t('cli.session.ambiguous', ['action' => 'purge', 'target' => $token, 'count' => (string) \count($candidates)]),
                        $args->outputFormat,
                        Lang::t('cli.session.candidates', ['candidates' => self::gcPurgeCandidateList(\array_slice($candidates, 0, 10)) . (\count($candidates) > 10 ? ', …' : '')])
                    );
                }
                if ($candidates === []) {
                    \fwrite(\STDERR, 'candy-code: ' . Lang::t('cli.session.not_found', ['target' => $token]) . "\n");
                    if ($asJson) {
                        self::emitDocument([
                            'verb' => 'gc',
                            'action' => 'purge',
                            'result' => null,
                            'error' => ['type' => 'not-found', 'message' => Lang::t('cli.session.not_found_message', ['target' => $token])],
                        ]);
                    }

                    return NonInteractive::EXIT_FAILURE;
                }
                $wanted[$candidates[0]] = true;
            }
        }

        $matched = [];
        foreach ($byId as $id => $row) {
            if ($wanted !== null && !isset($wanted[$id])) {
                continue;
            }
            if ($selectors['subagents'] && $row['parent_id'] === null) {
                continue;
            }
            if ($selectors['archived'] && !$row['archived']) {
                continue;
            }
            if ($selectors['older'] !== null) {
                $epoch = $row['created_epoch'];
                if ($epoch === null || (int) $epoch > $now - (int) $selectors['older']) {
                    continue;
                }
            }
            if ($selectors['larger'] !== null && (int) $row['total_bytes'] < (int) $selectors['larger']) {
                continue;
            }
            $matched[$id] = $row;
        }
        if ($selectors['keep'] !== null) {
            // Keep the newest N of the matched set; they leave the acted
            // set entirely (deletes AND strips — ruling 4).
            $ordered = \array_values($matched);
            \usort($ordered, static function (array $left, array $right): int {
                $leftEpoch = $left['created_epoch'] === null ? -1 : (int) $left['created_epoch'];
                $rightEpoch = $right['created_epoch'] === null ? -1 : (int) $right['created_epoch'];
                return $rightEpoch <=> $leftEpoch;
            });
            foreach (\array_slice($ordered, 0, (int) $selectors['keep']) as $kept) {
                unset($matched[(string) $kept['id']]);
            }
        }

        $deleteIds = $selectors['deleteMode'] ? \array_keys($matched) : [];
        $stripIds = $selectors['strips'] !== [] ? \array_keys($matched) : [];

        $exemptions = [];
        if ($deleteIds !== [] && !$selectors['includeNamed']) {
            $exempt = self::gcPurgeExemptIds($connection);
            foreach ($deleteIds as $id) {
                if (isset($exempt[$id])) {
                    $exemptions[] = $id;
                }
            }
            $deleteIds = \array_values(\array_diff($deleteIds, $exemptions));
        }

        $locked = [];
        $locksDirectory = $configDir . '/' . SessionLock::DIRECTORY;
        foreach (\array_unique(\array_merge($deleteIds, $stripIds)) as $id) {
            if (\preg_match(GcSessionSizes::SESSION_ID_PATTERN, (string) $id) !== 1) {
                continue;
            }
            $path = $locksDirectory . '/' . $id . '.lock';
            if (\is_file($path) && !\is_link($path) && self::gcLockIsHeld($path)) {
                $locked[] = (string) $id;
            }
        }

        $plannedIds = \array_values(\array_unique(\array_merge($deleteIds, $stripIds)));
        $census = (new GcOrphanCensus($connection, $configDir))->census($now);

        return [
            'rows' => $matched,
            'deleteIds' => $deleteIds,
            'stripIds' => $stripIds,
            'acted' => $plannedIds,
            'exemptions' => $exemptions,
            'locked' => $locked,
            'census' => $census,
            'planned_bytes' => self::gcPurgePlannedBytes($connection, $configDir, $deleteIds, $stripIds, $selectors['strips']),
            'strip_stats' => self::gcPurgeStripStats($connection, $configDir, $stripIds, $selectors['strips']),
            'selectors' => $selectors,
        ];
    }

    /**
     * The named+pinned exemption set, derived as the INVERSE of the
     * retention victim law in SessionStore::pruneSessions so gc-status's
     * weaker name-only count can never widen what purge protects:
     * pinned rows and rows carrying a real (non-'auto') title are exempt.
     *
     * @return array<string,true>
     */
    private static function gcPurgeExemptIds(\PDO $connection): array
    {
        $exempt = [];
        if (!GcInventory::tablePresent($connection, 'sessions')) {
            return $exempt;
        }
        $columns = GcInventory::columnsOf($connection, 'sessions');
        // The retention victim law is (unnamed OR auto-titled) AND
        // unpinned; its inverse protects pinned rows and rows carrying a
        // REAL title. A NULL/'' title_source never satisfies the victim's
        // equality leg, so named + unknown-source rows are exempt — IFNULL
        // mirrors that reading exactly.
        $namedLeg = \in_array('title_source', $columns, true)
            ? "(name IS NOT NULL AND name <> '' AND IFNULL(title_source, '') <> 'auto')"
            : "(name IS NOT NULL AND name <> '')";
        $statement = $connection->prepare('SELECT id FROM sessions WHERE pinned <> 0 OR ' . $namedLeg);
        $statement->execute();
        while (($id = $statement->fetchColumn()) !== false) {
            $exempt[(string) $id] = true;
        }
        $statement->closeCursor();

        return $exempt;
    }

    /**
     * Bytes the matched-set work releases AS FILES right now: the journal
     * mirrors purge unlinks for the ids it deletes (the house delete path
     * leaves them behind) plus strip-journal targets. Deleted DB rows do
     * NOT shrink the file until a vacuum runs — their LENGTH bytes are
     * previewed separately through the fold/census families, and the
     * post-delete hint names exactly that gap.
     *
     * @param list<string> $deleteIds
     * @param list<string> $stripIds
     * @param list<string> $strips
     */
    private static function gcPurgePlannedBytes(\PDO $connection, string $configDir, array $deleteIds, array $stripIds, array $strips): int
    {
        $unlinkJournals = $deleteIds !== [] || \in_array('--strip-journal', $strips, true);
        if (!$unlinkJournals) {
            return 0;
        }
        $bytes = 0;
        $journalPaths = GcSessionSizes::scanJournalPaths($configDir);
        foreach (\array_unique(\array_merge($deleteIds, $stripIds)) as $id) {
            foreach ($journalPaths[$id] ?? [] as $path) {
                if (\is_file($path) && !\is_link($path)) {
                    $bytes += (int) \filesize($path);
                }
            }
        }

        return $bytes;
    }

    /**
     * Per-family previews for the selected strip flags: the row families
     * ride gcPurgeFamilyStats (the same LENGTH accounting as the inventory
     * rosters); journals ride the id-shaped scan (their bytes live in
     * files, not a table).
     *
     * @param list<string> $stripIds
     * @param list<string> $strips
     *
     * @return array<string,array{count:int,bytes:int}>
     */
    private static function gcPurgeStripStats(\PDO $connection, string $configDir, array $stripIds, array $strips): array
    {
        $preview = [];
        foreach ($strips as $flag) {
            $preview[$flag] = match ($flag) {
                '--strip-events' => self::gcPurgeFamilyStats($connection, 'session_events', $stripIds),
                '--strip-usage' => self::gcPurgeFamilyStats($connection, 'usage_records', $stripIds),
                '--strip-checkpoints' => self::gcPurgeFamilyStats($connection, 'checkpoints', $stripIds),
                '--strip-fts' => self::gcPurgeFamilyStats($connection, 'session_message_rows', $stripIds),
                default => ['count' => 0, 'bytes' => 0],
            };
        }
        if (\in_array('--strip-journal', $strips, true)) {
            $files = 0;
            $bytes = 0;
            $journalPaths = GcSessionSizes::scanJournalPaths($configDir);
            foreach ($stripIds as $id) {
                foreach ($journalPaths[$id] ?? [] as $path) {
                    if (\is_file($path) && !\is_link($path)) {
                        ++$files;
                        $bytes += (int) \filesize($path);
                    }
                }
            }
            $preview['--strip-journal'] = ['count' => $files, 'bytes' => $bytes];
        }

        return $preview;
    }

    /**
     * Rows and bytes a strip family holds for the given session ids —
     * the SAME LENGTH(...) accounting law GcInventory's rosters use, over
     * the matched ids only.
     *
     * @param list<string> $ids
     * @return array{count:int,bytes:int}
     */
    private static function gcPurgeFamilyStats(\PDO $connection, string $table, array $ids): array
    {
        if ($ids === [] || !GcInventory::tablePresent($connection, $table)) {
            return ['count' => 0, 'bytes' => 0];
        }
        $roster = GcInventory::TABLE_ROSTERS[$table] ?? [];
        $columns = \array_values(\array_intersect($roster, GcInventory::columnsOf($connection, $table)));
        $byteExpr = $columns === []
            ? '0'
            : \implode(' + ', \array_map(static fn(string $column): string => 'COALESCE(SUM(LENGTH("' . $column . '")), 0)', $columns));
        $count = 0;
        $bytes = 0;
        foreach (\array_chunk($ids, 400) as $chunk) {
            $placeholders = \implode(', ', \array_fill(0, \count($chunk), '?'));
            $statement = $connection->prepare('SELECT COUNT(*), ' . $byteExpr . ' FROM "' . $table . '" WHERE "session_id" IN (' . $placeholders . ')');
            $statement->execute($chunk);
            $line = $statement->fetch(\PDO::FETCH_NUM);
            $statement->closeCursor();
            if ($line !== false) {
                $count += (int) $line[0];
                $bytes += (int) $line[1];
            }
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    /**
     * Count rows for the ids without claiming byte totals (search-anchor
     * preview — the anchor table is not in the byte rosters).
     *
     * @param list<string> $ids
     */
    private static function gcPurgeCountSessions(\PDO $connection, string $table, array $ids): int
    {
        if ($ids === [] || !GcInventory::tablePresent($connection, $table)) {
            return 0;
        }
        $count = 0;
        foreach (\array_chunk($ids, 400) as $chunk) {
            $placeholders = \implode(', ', \array_fill(0, \count($chunk), '?'));
            $statement = $connection->prepare('SELECT COUNT(*) FROM "' . $table . '" WHERE "session_id" IN (' . $placeholders . ')');
            $statement->execute($chunk);
            $line = $statement->fetchColumn();
            $statement->closeCursor();
            $count += (int) $line;
        }

        return $count;
    }

    /**
     * DELETE ... WHERE "session_id" IN (...) over 400-id chunks — the
     * same placeholder-chunk shape SessionStore::deleteSessionTrees uses.
     * The caller owns the transaction.
     *
     * @param list<string> $ids
     */
    private static function gcPurgeChunkedDelete(\PDO $connection, string $table, array $ids): int
    {
        $deleted = 0;
        foreach (\array_chunk($ids, 400) as $chunk) {
            $placeholders = \implode(', ', \array_fill(0, \count($chunk), '?'));
            $statement = $connection->prepare('DELETE FROM "' . $table . '" WHERE "session_id" IN (' . $placeholders . ')');
            $statement->execute($chunk);
            $deleted += $statement->rowCount();
            $statement->closeCursor();
        }

        return $deleted;
    }

    /**
     * Recursively delete one census-accused shadow directory. Never
     * follows a symlink (a linked entry is unlinked itself, not walked),
     * best-effort throughout — residue is the next census's problem, not
     * a purge failure. Returns [files removed, bytes released]; the
     * caller counts directories by accusation, not by this return.
     *
     * @return array{0:int,1:int}
     */
    private static function gcPurgeRemoveDirectory(string $path): array
    {
        $files = 0;
        $bytes = 0;
        if (!\is_dir($path) || \is_link($path)) {
            return [0, 0];
        }
        $entries = (array) \scandir($path);
        foreach ($entries as $entry) {
            if (!\is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (\is_link($child)) {
                if (@\unlink($child)) {
                    ++$files;
                }

                continue;
            }
            if (\is_dir($child)) {
                $inner = self::gcPurgeRemoveDirectory($child);
                $files += $inner[0];
                $bytes += $inner[1];
                @\rmdir($child);

                continue;
            }
            $size = \is_file($child) ? (int) @\filesize($child) : 0;
            if (@\unlink($child)) {
                ++$files;
                $bytes += $size;
            }
        }
        @\rmdir($path);

        return [$files, $bytes];
    }

    /**
     * Candidate list for the ambiguity door: the matched ids comma-
     * joined for display. The session verb's first-ten render pairs each
     * id with a sanitized label ('id (label)'); this list deliberately
     * carries the ids only — no labels ride it.
     *
     * @param list<string> $ids
     */
    private static function gcPurgeCandidateList(array $ids): string
    {
        return \implode(', ', $ids);
    }

    /**
     * Selected-clean-family totals out of a census payload — the FLAT
     * {@see GcOrphanCensus::census()} shape, whose top-level keys are the
     * family names this method's constant pairs them with.
     *
     * @param array<string,mixed> $census
     * @param list<string>        $cleans
     *
     * @return array{count:int,bytes:int,families:array<string,array{count:int,bytes:int}>}
     */
    private static function gcPurgeCensusTotals(array $census, array $cleans): array
    {
        $families = [];
        foreach (self::GC_PURGE_CLEAN_FAMILIES as $flag => $family) {
            if (!\in_array($flag, $cleans, true)) {
                continue;
            }
            $cell = $census[$family[0]] ?? ['count' => 0, 'bytes' => 0];
            $families[$flag] = ['count' => (int) $cell['count'], 'bytes' => (int) $cell['bytes'], 'label' => $family[1]];
        }
        $count = 0;
        $bytes = 0;
        foreach ($families as $cell) {
            $count += $cell['count'];
            $bytes += $cell['bytes'];
        }

        return ['count' => $count, 'bytes' => $bytes, 'families' => $families];
    }

    /**
     * The purge report, shared by --dry-run, the pre-confirm preview and
     * the post-execute summary so the plan a user confirms is byte-for-
     * byte the plan that was computed. Tallies ride $outcome only after
     * execution.
     *
     * @param array<string,mixed>                                             $plan
     * @param list<string>                                                    $cleans
     * @param array{count:int,bytes:int,families:array<string,mixed>}         $totals
     * @param array<string,mixed>|null                                        $outcome
     */
    private static function gcPurgeReport(
        bool $asJson,
        array $plan,
        array $cleans,
        array $totals,
        bool $dryRun,
        bool $executed,
        int $reclaimableBytes,
        int $now,
        ?array $outcome = null
    ): void {
        $selectors = $plan['selectors'];
        $rows = [];
        foreach ($plan['deleteIds'] as $id) {
            if (isset($plan['rows'][$id])) {
                $rows[] = $plan['rows'][$id];
            }
        }
        $stripRows = [];
        foreach ($plan['stripIds'] as $id) {
            if (isset($plan['rows'][$id])) {
                $stripRows[] = $plan['rows'][$id];
            }
        }

        if ($asJson) {
            $result = [
                'dry_run' => $dryRun,
                'executed' => $executed,
                'mode' => $plan['deleteIds'] !== [] ? 'delete' : ($plan['stripIds'] !== [] ? 'strip' : 'clean'),
                'filters' => [
                    'flags' => $selectors['deleteMode'] || $selectors['strips'] !== [] ? self::gcPurgeFilterSpelling($selectors) : [],
                    'older_than_seconds' => $selectors['older'],
                    'larger_than_bytes' => $selectors['larger'],
                    'keep_latest' => $selectors['keep'],
                    'sessions' => $selectors['tokens'],
                ],
                'plan' => [
                    'target_sessions' => $plan['deleteIds'],
                    'strip_sessions' => $plan['stripIds'],
                    'exemptions' => $plan['exemptions'],
                    'locked' => $plan['locked'],
                    'strips' => $selectors['strips'],
                    'strip_stats' => $plan['strip_stats'],
                    'cleans' => $cleans,
                    'clean_totals' => ['count' => $totals['count'], 'bytes' => $totals['bytes']],
                    'planned_bytes' => (int) $plan['planned_bytes'],
                ],
            ];
            if ($outcome !== null) {
                $result['deleted'] = $outcome['deleted'];
                $result['stripped'] = $outcome['stripped'];
                $result['cleaned'] = $outcome['cleaned'];
                $result['force_locked'] = $outcome['force_locked'] ?? [];
                $result['bytes_before'] = $outcome['bytes_before'];
                $result['bytes_after'] = $outcome['bytes_after'];
                $result['freed_bytes'] = $outcome['freed_bytes'];
            }
            self::emitDocument(['verb' => 'gc', 'action' => 'purge', 'result' => $result]);

            return;
        }

        echo 'candy-code gc purge', $dryRun ? ' [--dry-run]' : '', \PHP_EOL;
        if ($selectors['deleteMode'] && $plan['deleteIds'] !== []) {
            echo '  ' . Lang::t('cli.gc.purge.targets_header', ['count' => (string) \count($plan['deleteIds'])]) . \PHP_EOL;
            self::gcPurgeEchoRows($rows, $now);
        }
        if ($selectors['strips'] !== []) {
            echo '  ' . Lang::t('cli.gc.purge.strips_header') . ': ' . \implode(', ', $selectors['strips']) . \PHP_EOL;
            if ($plan['stripIds'] !== []) {
                echo '  ' . Lang::t('cli.gc.purge.targets_header', ['count' => (string) \count($plan['stripIds'])]) . \PHP_EOL;
                self::gcPurgeEchoRows($stripRows, $now);
            }
        }
        if ($plan['exemptions'] !== []) {
            echo '  ' . Lang::t('cli.gc.purge.exemptions_header', ['count' => (string) \count($plan['exemptions'])])
                . ': ' . \implode(', ', $plan['exemptions']) . \PHP_EOL;
        }
        if ($plan['locked'] !== []) {
            echo '  ' . Lang::t('cli.gc.purge.locked_header', ['count' => (string) \count($plan['locked'])])
                . ': ' . \implode(', ', $plan['locked']) . \PHP_EOL;
        }
        if ($cleans !== []) {
            echo '  ' . Lang::t('cli.gc.purge.cleans_header') . \PHP_EOL;
            foreach ($totals['families'] as $cell) {
                echo '  ' . Lang::t($cell['label'], [
                    'count' => (string) $cell['count'],
                    'bytes' => self::gcFormatBytes((int) $cell['bytes']),
                ]) . \PHP_EOL;
            }
        }
        if (\in_array('--strip-usage', $selectors['strips'], true)) {
            echo '  ' . Lang::t('cli.gc.purge.usage_strip_disclosure') . \PHP_EOL;
        }
        $anyWork = $plan['deleteIds'] !== [] || $plan['stripIds'] !== [] || $totals['count'] > 0 || $plan['planned_bytes'] > 0;
        if (!$anyWork) {
            echo '  ' . Lang::t('cli.gc.purge.nothing_matched') . \PHP_EOL;
        } else {
            echo '  ' . Lang::t('cli.gc.purge.total_row', [
                'count' => (string) (\count($plan['deleteIds']) + $totals['count']),
                'bytes' => self::gcFormatBytes((int) $plan['planned_bytes'] + $totals['bytes']),
            ]) . \PHP_EOL;
        }
        if ($outcome !== null) {
            echo '  ' . Lang::t('cli.gc.purge.summary', [
                'deleted' => (string) $outcome['deleted']['sessions'],
                'stripped' => (string) \array_sum($outcome['stripped']),
                'cleaned' => (string) \array_sum($outcome['cleaned']),
                'bytes' => self::gcFormatBytes((int) $outcome['freed_bytes']),
            ]) . \PHP_EOL;
            if ($plan['locked'] !== []) {
                echo '  ' . Lang::t('cli.gc.purge.locks_refused', ['count' => (string) \count($plan['locked'])]) . \PHP_EOL;
            }
            $crossedOut = $outcome['force_locked'] ?? [];
            if ($crossedOut !== []) {
                echo '  ' . Lang::t('cli.gc.purge.force_locks_crossed', ['count' => (string) \count($crossedOut)]) . \PHP_EOL;
            }
            if ($outcome['deleted']['sessions'] > 0 && $reclaimableBytes > 0) {
                echo '  ' . Lang::t('cli.gc.purge.vacuum_hint', ['bytes' => self::gcFormatBytes($reclaimableBytes)]) . \PHP_EOL;
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function gcPurgeEchoRows(array $rows, int $now): void
    {
        foreach ($rows as $row) {
            $cells = self::gcRowCells($row, $now);
            echo '  ' . Lang::t('cli.gc.purge.row', [
                'id' => $cells['id'],
                'flags' => $cells['flags'],
                'age' => $cells['age'],
                'title' => $cells['title'],
                'size' => $cells['size'],
            ]) . \PHP_EOL;
        }
    }

    /**
     * @param array<string,mixed> $selectors
     *
     * @return list<string>
     */
    private static function gcPurgeFilterSpelling(array $selectors): array
    {
        $spelling = [];
        if ($selectors['older'] !== null) {
            $spelling[] = '--older-than';
        }
        if ($selectors['tokens'] !== []) {
            $spelling[] = '--session';
        }
        if ($selectors['subagents']) {
            $spelling[] = '--subagents';
        }
        if ($selectors['larger'] !== null) {
            $spelling[] = '--larger-than';
        }
        if ($selectors['keep'] !== null) {
            $spelling[] = '--keep-latest';
        }
        if ($selectors['archived']) {
            $spelling[] = '--archived';
        }

        return $spelling;
    }

    /**
     * The store path every `gc` action reads, WITHOUT constructing a store:
     * {@see \SugarCraft\Code\Session\EnhancedSessionStore}'s constructor
     * CREATES the database and its schema, and a diagnostic that mints a
     * store just to report on it would be lying about the fresh install it
     * just performed. `Bootstrap::trustedConfigDirPath()` is the same path
     * the store's own launcher builds.
     */
    private static function gcStorePath(): string
    {
        return Bootstrap::trustedConfigDirPath() . '/session.db';
    }

    /**
     * The `gc` mirror of {@see self::sessionStoreForVerbs()}: a store that
     * will not open — missing, corrupt, or unreadable — states the reason it
     * gave and exits 1 (EXIT_FAILURE: the verb attempted something and the
     * ledger could not answer), never 255.
     */
    private static function gcStoreUnavailable(ParsedArgs $args, bool $asJson, string $action, string $error): int
    {
        $notice = Lang::t('cli.gc.store_unavailable', ['error' => $error]);

        \fwrite(\STDERR, 'candy-code: ' . $notice . "\n");
        if ($asJson) {
            self::emitDocument([
                'verb' => 'gc',
                'action' => $action,
                'result' => ['opened' => false, 'reason' => $notice],
            ]);
        }

        return NonInteractive::EXIT_FAILURE;
    }

    /**
     * Whether one lock file carries a LIVE flock right now. A lock this
     * process can take is a dead window's residue (free); one it cannot —
     * or a file it cannot even open — is held. The pid INSIDE the file is
     * never trusted for liveness (ISS-079's law), and no process-name
     * probe is used. Callers that delete on the strength of a FREE answer
     * must re-probe immediately before the unlink: a holder can appear at
     * any moment.
     */
    private static function gcLockIsHeld(string $path): bool
    {
        $handle = @\fopen($path, 'r');
        if ($handle === false) {
            return true;
        }

        if (@\flock($handle, \LOCK_EX | \LOCK_NB)) {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);

            return false;
        }

        @\fclose($handle);

        return true;
    }

    /**
     * How many per-session lock files under the config dir carry a LIVE
     * flock, the ground truth behind the locked-store advice line. The
     * per-file probe is {@see self::gcLockIsHeld} — the same law
     * `gc purge --clean-locks` re-probes with before every unlink.
     */
    private static function gcLiveLockCount(): int
    {
        $directory = \dirname(self::gcStorePath()) . '/' . SessionLock::DIRECTORY;
        if (!\is_dir($directory)) {
            return 0;
        }

        $live = 0;
        foreach ((array) \scandir($directory) as $entry) {
            if (!\is_string($entry) || !\str_ends_with($entry, '.lock')) {
                continue;
            }

            if (self::gcLockIsHeld($directory . '/' . $entry)) {
                ++$live;
            }
        }

        return $live;
    }

    /**
     * Bytes as a human reads them — the same binary walk
     * {@see \SugarCraft\Code\Support\DoctorChecks} uses for its store row
     * (its helper is private to that class; the shapes are kept identical).
     */
    private static function gcFormatBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $index = 0;
        while (\abs($value) >= 1024.0 && $index < \count($units) - 1) {
            $value /= 1024.0;
            ++$index;
        }

        return $index === 0
            ? $bytes . ' B'
            : \number_format($value, 1) . ' ' . $units[$index];
    }

    /**
     * A 30-cell fill bar scaled to the largest row, like ocgc's table
     * display: █ for the filled cells, ░ for the rest.
     */
    private static function gcBar(int $bytes, int $largest, int $cells): string
    {
        $filled = $largest > 0 ? (int) \round($cells * $bytes / $largest) : 0;

        return \str_repeat('█', $filled) . \str_repeat('░', $cells - $filled);
    }

    /**
     * Each `workflow` action and the {@see ParsedArgs::SUBCOMMAND_FLAGS} it
     * accepts - the same verb-level door {@see self::GC_ACTION_FLAGS} is for
     * `gc`. Only `run` takes `--param` (a fresh context); `resume` replays
     * the paused run's recorded context and `status` answers from the pause
     * records alone, so flags that would silently do nothing are refused.
     *
     * @var array<string, list<string>>
     */
    private const WORKFLOW_ACTION_FLAGS = [
        'run'    => ['--param', '--format'],
        'status' => ['--format'],
        'resume' => ['--format'],
    ];

    /**
     * W-1/D4 exit code: the run STOPPED for a person - a verification stage
     * that escalated (the engine's ESCALATION_MARKER on the first failure's
     * error), or a cooperative signal-pause completed by the W-2 observer.
     * The table: 0 completed, 1 failed or refused, 2 usage, 3 stopped.
     */
    private const WORKFLOW_EXIT_STOPPED = 3;

    /**
     * Each `gc` action and the {@see ParsedArgs::SUBCOMMAND_FLAGS} it
     * accepts — the verb-level door {@see self::gc()} checks before any
     * work, exactly like {@see self::SESSION_ACTION_FLAGS} does for
     * `session`. Both `--force` spellings sit in every list that carries a
     * write: `vacuum`'s rewrite and `purge`'s deletes/strips/cleans each
     * take `-f` as the `--force` alias.
     *
     * @var array<string, list<string>>
     */
    private const GC_ACTION_FLAGS = [
        'status' => ['--format'],
        'sessions' => ['--sort', '--limit', '--archived', '--children', '--format'],
        'analyze' => ['--top', '--format'],
        'purge' => [
            '--older-than',
            '--session',
            '--subagents',
            '--larger-than',
            '--keep-latest',
            '--archived',
            '--include-named',
            '--dry-run',
            '-n',
            '--strip-events',
            '--strip-usage',
            '--strip-checkpoints',
            '--strip-journal',
            '--strip-fts',
            '--clean-orphan-journals',
            '--clean-orphan-events',
            '--clean-orphan-usage',
            '--clean-locks',
            '--clean-shares',
            '--clean-imports',
            '--clean-shadows',
            '--clean-expired-tombstones',
            '--format',
            '--force',
            '-f',
        ],
        'vacuum' => ['--check', '--incremental', '--force', '-f'],
    ];

    /**
     * Purge clean flags mapped to their GcOrphanCensus family key and the
     * cli.gc.orphans_* label the analyze table already prints. The census
     * is the sole accuser: what appears here as count/bytes is exactly
     * what the same-family listers delete at execute time.
     */
    private const GC_PURGE_CLEAN_FAMILIES = [
        '--clean-orphan-journals' => ['journals', 'cli.gc.orphans_journals'],
        '--clean-orphan-events' => ['events', 'cli.gc.orphans_events'],
        '--clean-orphan-usage' => ['usage_records', 'cli.gc.orphans_usage_records'],
        '--clean-locks' => ['stale_locks', 'cli.gc.orphans_stale_locks'],
        '--clean-shares' => ['stale_shares', 'cli.gc.orphans_stale_shares'],
        '--clean-imports' => ['session_imports', 'cli.gc.orphans_session_imports'],
        '--clean-shadows' => ['abandoned_shadows', 'cli.gc.orphans_abandoned_shadows'],
        '--clean-expired-tombstones' => ['expired_tombstones', 'cli.gc.orphans_expired_tombstones'],
    ];

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
        // C-SES-7.b: `--format` picks the reader (default `auto`, which probes
        // the bytes), `--name` titles the minted row, and `--force` is the
        // same ISS-079 live-lock crossing every mutating sibling below speaks
        // — import mutates whenever the file has been imported before, so it
        // carries the flag even for a first run that only ever creates.
        'import' => ['--format', '--name', '--force'],
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
     * no accounted call ever named the row; a fixed-width untranslated header
     * row (NN-09, OD-1 clause j) names the columns above them, absent when no
     * rows print; the JSON rows are
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

        $store = self::sessionStoreForVerbs($args);
        if (\is_int($store)) {
            return $store;
        }

        $rows = $store->listSessionsFiltered($query);

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
        // NN-09 (OD-1 clause j): the fixed-width table header is a machine
        // literal, untranslated by design — a translated label would no longer
        // sit over the column it names. It shares the rows' format string, so
        // every field starts at its column's offset whatever $idWidth the
        // widest id forces. The empty store prints no header, only the
        // none-stored notice.
        \printf(
            "%s %-{$idWidth}s  %-19s  %-10s  %5s  %-9s  %-24s  %s\n",
            ' ',
            'ID',
            'UPDATED',
            'KIND',
            'TURNS',
            'SPEND',
            'PROVIDER/MODEL',
            'NAME',
        );
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

        $store = self::sessionStoreForVerbs($args);
        if (\is_int($store)) {
            return $store;
        }
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
        // M13: the transcript is untrusted stored text (tool output from a
        // poisoned repo file gets a second life here). Control bytes other
        // than TAB/LF read as caret notation instead of repainting the
        // operator's terminal; the JSON arm above keeps the exact bytes,
        // where escaping already neutralises them.
        echo "\n" . ($messages === [] ? Lang::t('cli.session.show.no_transcript') . "\n" : Sanitize::visibleControls(rtrim(Exporter::toMarkdown($messages))) . "\n");

        return NonInteractive::EXIT_OK;
    }

    /**
     * `session rename <target> <title…>`: every word after the target is the
     * title, joined with single spaces, so it needs no quoting. Recorded as a
     * USER title, which the auto-titler never overwrites.
     *
     * M13: argv is as untrusted as a model's guess — a title stored raw came
     * back executing on every read-only `session list`/`show` (OSC window
     * titles, screen clears, forged rows through embedded newlines). It now
     * passes through the canonical title sanitizer the auto-titler and the
     * TUI rename already use: every escape family stripped, the first line
     * only, clipped to {@see TitleService::TITLE_MAX_CHARS}. A title that
     * sanitises to nothing is refused, exactly as an empty one is.
     */
    private static function sessionRename(ParsedArgs $args): int
    {
        $raw = \trim(\implode(' ', \array_slice($args->subcommandArgs, 2)));
        $title = $raw === '' ? '' : TitleService::sanitizeTitle($raw);
        if (isset($args->subcommandArgs[1]) && $title === '') {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.session.rename.no_title'),
                $args->outputFormat,
                Lang::t('cli.session.rename.usage'),
            );
        }

        $store = self::sessionStoreForVerbs($args);
        if (\is_int($store)) {
            return $store;
        }
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

        $store = self::sessionStoreForVerbs($args);
        if (\is_int($store)) {
            return $store;
        }
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

        $store = self::sessionStoreForVerbs($args);
        if (\is_int($store)) {
            return $store;
        }
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
     * `session import <file> [--format auto|…] [--name <title>] [--force]`.
     *
     * C-SES-7.b wraps {@see \SugarCraft\Code\Session\Import\SessionImporter}
     * (C-SES-7.a) with the verb-side doors this class owns. A missing operand
     * or a `--format` word no reader knows rides the usage channel (exit 2:
     * nothing was attempted, the same grammar every sibling door uses). A
     * transcript the importer itself refuses — unreadable bytes, a shape it
     * cannot parse, a source that shrank under its own ledger — rides exit 1
     * with an `import-refused` JSON type, because the store WAS opened and
     * the import attempted and refused: the not-found channel's distinction,
     * applied to the source file instead of a target row. The live lock of a
     * session this file already feeds is asked through the same
     * {@see refuseLiveLockedSession()} the mutating siblings use, so
     * `--force` means one thing across the whole verb; the ask runs BEFORE
     * the import because a delta appended under a live window is exactly the
     * silent undo ISS-079 refuses elsewhere.
     */
    private static function sessionImport(ParsedArgs $args): int
    {
        $path = $args->subcommandArgs[1] ?? null;
        if ($path === null || $path === '') {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.session.import.no_file'),
                $args->outputFormat,
                Lang::t('cli.session.import.usage'),
            );
        }
        if (\count($args->subcommandArgs) > 2) {
            return self::rejectSessionOperand('import', $args->subcommandArgs[2], $args);
        }

        $format = null;
        if (isset($args->subcommandFlags['--format'])) {
            $spelled = (string) $args->subcommandFlags['--format'];
            if ($spelled !== 'auto') {
                $format = ImportFormat::tryFrom($spelled);
                if ($format === null) {
                    return NonInteractive::failUsage(
                        'candy-code: ' . Lang::t('cli.session.import.bad_format', ['format' => $spelled]),
                        $args->outputFormat,
                        Lang::t('cli.session.import.formats', ['formats' => 'auto, ' . \implode(
                            ', ',
                            \array_map(static fn(ImportFormat $f): string => $f->value, ImportFormat::cases()),
                        )]),
                    );
                }
            }
        }

        $store = self::sessionStoreForVerbs($args);
        if (\is_int($store)) {
            return $store;
        }

        // The ledger is the only way this verb knows a file has a session
        // already — a first import creates, and creation cannot cross a lock
        // that does not exist yet. The lookup key is the importer's own law
        // (`realpath ?: path`), so verb and ledger read the same identity.
        $known = SessionImportLedger::atPath($store->path())->lookup(\realpath($path) ?: $path);
        if ($known !== null) {
            $rows = SessionResolver::matches($store, $known['session_id']);
            if ($rows !== []) {
                $refusal = self::refuseLiveLockedSession($rows[0], $store, $args);
                if ($refusal !== null) {
                    return $refusal;
                }
            }
        }

        $name = isset($args->subcommandFlags['--name'])
            ? ((string) $args->subcommandFlags['--name'] ?: null)
            : null;

        try {
            $imported = SessionImporter::new()->importFile($store, $path, $format, $name);
        } catch (ImportRefusedException $e) {
            \fwrite(\STDERR, 'candy-code: ' . $e->getMessage() . "\n");
            if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
                self::emitDocument([
                    'result' => null,
                    'error' => ['type' => 'import-refused', 'message' => $e->getMessage()],
                ]);
            }

            return NonInteractive::EXIT_FAILURE;
        }

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => [
                'imported' => $imported->sessionId,
                'format' => $imported->format->value,
                'items' => $imported->items,
                'appended' => $imported->appended,
                'digest' => $imported->digest,
                'name' => $imported->title,
            ]]);

            return NonInteractive::EXIT_OK;
        }

        echo Lang::t('cli.session.import.done', [
            'items' => $imported->items,
            'format' => $imported->format->label(),
            'id' => $imported->sessionId,
            'appended' => $imported->appended,
        ]) . "\n";
        echo Lang::t('cli.session.import.resume_hint', ['id' => $imported->sessionId]) . "\n";

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
        ?string $targetOverride = null,
    ): SessionRow|int {
        // The override is what lets the top-level `usage --session <target>`
        // verb share this resolver: the session verbs carry the target as the
        // POSITIONAL operand (`session show <target>`), `usage` carries it as
        // a scoped FLAG, and the two report through the same ambiguous /
        // not-found doors so a mistyped id behaves identically either way.
        $target = $targetOverride ?? $args->subcommandArgs[1] ?? null;
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
     * `candy-code usage [7|30|all] [--session <target>]` (C-SES-8.e) — the
     * headless read of the durable spend ledger. It prints EXACTLY what the
     * in-app `/usage` page composes: the same
     * {@see UsageHostCommand::rollupFacts()} snapshot folded by the same
     * {@see UsageCommand::compose()}, so the TUI, the usage RPC and this verb
     * cannot disagree about the same ledger at the same instant. The window
     * operand rides `/usage`'s own contract (OBS-3): `7`/`30` take whole UTC
     * days today-included, `all` or no operand spans the whole ledger, and a
     * token outside that set is a usage error — never a silent fall-back to
     * all, because a typo'd window must not print a bigger number than was
     * asked for. `--session <target>` narrows to one session through the same
     * resolver the `session` verbs use (id, name or unique prefix; the same
     * ambiguous / not-found doors), and JSON mode emits the facts map itself.
     * A store that will not open prints its stated reason on the unavailable
     * page and exits 1 — silence-with-zeros is the failure mode this command's
     * completeness law forbids — and unpriced models keep compose()'s `≥`
     * lower-bound framing because nothing here re-formats a figure compose()
     * already worded.
     */
    private static function usage(ParsedArgs $args): int
    {
        $operands = $args->subcommandArgs;
        if (\count($operands) > 1) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.sub.unexpected_operand', ['verb' => 'usage', 'operand' => (string) $operands[1]]),
                $args->outputFormat,
                Lang::t('cli.usage.usage'),
            );
        }

        $token = $operands[0] ?? 'all';
        $windowDays = match ($token) {
            '7' => 7,
            '30' => 30,
            default => null,
        };
        if ($windowDays === null && $token !== 'all') {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.usage.bad_window', ['window' => $token]),
                $args->outputFormat,
                Lang::t('cli.usage.usage'),
            );
        }

        $hasTarget = isset($args->subcommandFlags['--session']);
        // A diagnostic never prunes: same law as `session list` — reading the
        // ledger must not delete empty sessions as a side effect.
        try {
            $store = Bootstrap::sessionStore(prune: false);
        } catch (\Throwable $e) {
            return self::renderUsage([
                'scopeAll' => !$hasTarget,
                'windowDays' => $windowDays,
                'days' => null,
                'models' => null,
                'reason' => $e->getMessage(),
            ], $args);
        }

        $sessionId = null;
        if ($hasTarget) {
            $row = self::resolveSessionTarget('usage', $args, $store, (string) $args->subcommandFlags['--session']);
            if (\is_int($row)) {
                // The no-target / ambiguous / not-found door already answered;
                // its exit code is the answer's.
                return $row;
            }
            $sessionId = $row->id;
        }

        return self::renderUsage(UsageHostCommand::rollupFacts($store, $sessionId, $windowDays, !$hasTarget), $args);
    }

    /**
     * The one print path of the `usage` verb: text gets compose()'s page,
     * JSON gets the facts map under result.usage — both from the SAME array,
     * so the machine shape can never drift from what the human read. An
     * unreadable ledger (days/models null) exits 1 with its reason stated,
     * exactly as the page words it.
     *
     * @param array<string, mixed> $facts
     */
    private static function renderUsage(array $facts, ParsedArgs $args): int
    {
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => ['usage' => $facts]]);
        } else {
            echo UsageCommand::compose($facts) . "\n";
        }

        return $facts['days'] === null ? NonInteractive::EXIT_FAILURE : NonInteractive::EXIT_OK;
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

    /**
     * A row's name, or `(unnamed)`. M13: rows stored by pre-sanitiser builds
     * still carry raw escapes on disk, so every table and header that prints
     * this label renders control characters as caret notation — the poison
     * stays visible evidence, never a terminal command.
     */
    private static function sessionLabel(SessionRow $row): string
    {
        return Sanitize::visibleControls($row->name ?? Lang::t('cli.session.unnamed'), preserveLayout: false);
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
        if ($verb === 'serve') {
            return self::mcpServe($args);
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
     * `candy-code mcp serve` — the C-MCP-5.b wiring for the stdio MCP host
     * C-MCP-5.a built ({@see McpServeHost}).
     *
     * The root is the launch's own: ArgvParser has already refused a `--root`
     * that does not exist, and the unset case is the working directory — the
     * same resolution the `-p` path uses for its own root-bearing calls. The
     * gate is the background-surface gate: `Bootstrap::permissionGate()`
     * with its defaults answers on the surface a daemon launched from a
     * terminal it does not own uses — with no mode named in the config that
     * is bypass, so the gate is deliberately NOT an approval floor here;
     * the floor is the read-only roster, the deny rules, and the hook chain
     * built AROUND it exactly as the host prescribes — {@see Bootstrap::hooks()}
     * installs the gate last, so audit, confirm-rm and protect-files still
     * run ahead of it. A mode the config does spell is honoured, and an ask
     * it config'd into existence is refused fail-closed by the host
     * ({@see McpServeHost}) — nothing can be answered in this terminal.
     * The roster is {@see McpServeRoster::build()}'s own
     * output — the read-only four, classified, never a hand-list widened at
     * this call site (r1 NIT-2). The host answers until stdin closes and
     * returns 0 itself; nothing here duplicates its framing or touches
     * `--output-format`, which governs candy-code's own documents, not the
     * MCP wire.
     */
    private static function mcpServe(ParsedArgs $args): int
    {
        $root = $args->root ?? (getcwd() ?: '');

        return McpServeHost::run(
            McpServeRoster::build($root),
            Bootstrap::hooks(Bootstrap::permissionGate(), $root),
            $root,
        );
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
     * `candy-code import claude [--project] [--yes]` (C-TUI-5.b): read
     * Claude Code's own settings documents, translate what candy-code has a
     * home for, PRINT the whole preview, and write the user tier only after
     * `--yes` or an interactive confirm — the same print-first contract
     * `mcp import` keeps, with a confirm instead of the paste-back step.
     *
     * Security posture (the imported file is UNTRUSTED INPUT): DENY WINS
     * (see {@see \SugarCraft\Code\Config\ClaudeSettingsImport}); every
     * `allow` the write would land — and every one the same document's
     * stricter half vetoed — is enumerated on screen BEFORE anything is
     * written; a malformed or hostile document exits 1 with nothing
     * partially applied; a headless run without `--yes` refuses the write
     * outright rather than guessing. All output rides stdout — this verb
     * mints no stderr writer.
     */
    private static function importClaude(ParsedArgs $args): int
    {
        $operand = $args->subcommandArgs[0] ?? null;
        if ($operand === null) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.sub.import.no_source'),
                $args->outputFormat,
                Lang::t('cli.sub.import.usage'),
            );
        }
        if ($operand !== ClaudeSettingsImport::SOURCE) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.sub.import.unknown_source', ['source' => $operand]),
                $args->outputFormat,
                Lang::t('cli.sub.import.usage'),
            );
        }
        if (($args->subcommandArgs[1] ?? null) !== null) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.sub.import.unexpected_operand', ['operand' => (string) $args->subcommandArgs[1]]),
                $args->outputFormat,
                Lang::t('cli.sub.import.usage'),
            );
        }

        $withProject = \array_key_exists('--project', $args->subcommandFlags);
        $autoYes = \array_key_exists('--yes', $args->subcommandFlags);

        $userFile = ClaudeSettingsImport::userSettingsFile();
        if ($userFile === null) {
            // No vouched home means no readable user file AND no writable
            // user tier — the import can never complete, so it says so before
            // reading anything.
            echo Lang::t('cli.sub.import.home_unowned') . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        $projectFile = $withProject
            ? ClaudeSettingsImport::projectSettingsFile(\rtrim($args->root ?? \getcwd(), '/'))
            : null;
        $paths = $withProject ? [$userFile, $projectFile] : [$userFile];

        try {
            $report = ClaudeSettingsImport::inspect($paths);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The class raises translated, path-named sentences; the file was
            // hostile or unreadable, and NOTHING has been applied.
            echo $e->getMessage() . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        if ($withProject && !\in_array((string) $projectFile, $report['files'], true)) {
            echo Lang::t('cli.sub.import.project_missing', ['path' => (string) $projectFile]) . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        // candy-code stores model ids per provider, so an imported scalar
        // `model` needs a provider name to land under — and a name worth
        // writing. When this install has no selected provider (or the name
        // resolves to nothing real), the model becomes a named skip rather
        // than a config entry nothing reads: the same rule `/model` enforces
        // on itself.
        $providerName = null;
        if ($report['model'] !== null) {
            try {
                $candidate = Bootstrap::selectedProviderName();
                $names = \array_map('strval', \array_keys(Bootstrap::availableProviders()));
                if ($candidate !== null && \in_array($candidate, $names, true)) {
                    $providerName = $candidate;
                }
            } catch (\Throwable) {
                $providerName = null;
            }
            if ($providerName === null) {
                $report['model'] = null;
                $report['skipped'][] = ['key' => 'model', 'note' => Lang::t('cli.sub.import.skipped_noprovider')];
            }
        }

        if ($report['files'] === []) {
            echo Lang::t('cli.sub.import.no_files', ['path' => $userFile]) . "\n";

            return NonInteractive::EXIT_OK;
        }

        echo Lang::t('cli.sub.import.header') . "\n";
        foreach ($report['files'] as $file) {
            echo Lang::t('cli.sub.import.file_read', ['path' => $file]) . "\n";
        }
        foreach ($report['rules'] as $row) {
            echo Lang::t('cli.sub.import.rule_row', ['action' => $row['action'], 'pattern' => $row['pattern']]) . "\n";
        }
        if ($report['rules'] !== [] && $report['widenings'] !== []) {
            // The note speaks about "these" — the rule rows just printed —
            // so it counts the rules that LAND as allow, i.e. the widening
            // this write actually causes. `widenings` also carries every
            // allow-spelled pattern the deny-wins precedence vetoed (the
            // preview must show those requests), and counting them would
            // overstate the note on any mixed import (C-TUI-5.b review).
            $landedAllows = 0;
            foreach ($report['rules'] as $row) {
                if ($row['action'] === 'allow') {
                    ++$landedAllows;
                }
            }
            echo Lang::t('cli.sub.import.widening_note', ['count' => (string) $landedAllows]) . "\n";
        }
        foreach ($report['dropped'] as $pattern) {
            echo Lang::t('cli.sub.import.dropped_row', ['pattern' => $pattern]) . "\n";
        }
        if ($report['model'] !== null) {
            echo Lang::t('cli.sub.import.model_row', ['model' => $report['model']]) . "\n";
        }
        foreach ($report['skipped'] as $row) {
            echo Lang::t('cli.sub.import.skipped_row', ['key' => $row['key'], 'note' => $row['note']]) . "\n";
        }
        foreach ($report['rejected'] as $row) {
            echo Lang::t('cli.sub.import.rejected_row', ['pattern' => $row['pattern'], 'reason' => $row['reason']]) . "\n";
        }

        if ($report['rules'] === [] && $report['model'] === null) {
            echo Lang::t('cli.sub.import.nothing_importable') . "\n";

            return NonInteractive::EXIT_OK;
        }

        echo Lang::t('cli.sub.import.order_note') . "\n";

        if (!$autoYes) {
            // The guarded probe is the house shape (as used by
            // {@see \SugarCraft\Code\App\App::firstRunWizardOfferCmd()}):
            // phpunit's bootstrap frees the STDIN resource during its fd-0
            // repair, and an unguarded stream_isatty on it is a TypeError, not
            // a "no". A dead STDIN is exactly the unattended case the refusal
            // speaks about, so fail closed.
            if (!\is_resource(\STDIN) || !\stream_isatty(\STDIN)) {
                echo Lang::t('cli.sub.import.confirm_required') . "\n";

                return NonInteractive::EXIT_FAILURE;
            }
            echo Lang::t('cli.sub.import.ask_confirm') . "\n";
            $answer = \strtolower(\trim((string) \fgets(\STDIN)));
            if ($answer !== 'y' && $answer !== 'yes') {
                echo Lang::t('cli.sub.import.declined') . "\n";

                return NonInteractive::EXIT_OK;
            }
        }

        try {
            $result = ClaudeSettingsImport::persist($report, LaunchContext::settingsWriter($args->root), $providerName);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // The editor's own refusal sentence (it validates with the
            // launch's strict parser): the file is byte-unchanged.
            echo Lang::t('cli.sub.import.failed', ['reason' => $e->getMessage()]) . "\n";

            return NonInteractive::EXIT_FAILURE;
        }

        echo Lang::t('cli.sub.import.written', [
            'path' => $result['path'],
            'rules' => (string) $result['added'],
        ]) . "\n";
        if ($result['model']) {
            echo Lang::t('cli.sub.import.written_model') . "\n";
        }

        return NonInteractive::EXIT_OK;
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

    // ── C-TOOL-5.d: `candy-code lsp install <language> | lsp list` ──────────

    /**
     * Test seam for the installer call: null runs the real
     * {@see LspInstaller::install()}. The suite NEVER downloads — an
     * npm install or a release fetch would hit the network, wait minutes,
     * and mutate a real home tree. The seam receives only the language, so
     * what a test pins is the CLI→installer wiring itself.
     *
     * @var (callable(string): array{ok: bool, message: string, path: ?string})|null
     */
    private static ?\Closure $lspInstaller = null;

    /**
     * @param (callable(string): array{ok: bool, message: string, path: ?string})|null $seam
     */
    public static function setLspInstallSeamForTesting(?\Closure $seam): void
    {
        self::$lspInstaller = $seam;
    }

    public static function resetLspInstallSeamForTesting(): void
    {
        self::$lspInstaller = null;
    }

    /**
     * `candy-code lsp` — the pinned language servers of
     * {@see LspReleases}, reported and installed: `lsp list` answers what is
     * pinned, what is installed and what is available for this machine;
     * `lsp install <language>` fetches exactly that pinned entry through
     * {@see LspInstaller::install()}, checksum-verified, into the private
     * install root.
     *
     * The shape mirrors {@see self::mcp()}'s verb gate: action arms first,
     * a loud failUsage for anything else, and ONE exit code per outcome for
     * both output formats — a JSON consumer must never see a verdict the
     * text arm would call a failure.
     */
    private static function lsp(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[0] ?? null;
        if ($verb === 'install') {
            return self::lspInstall($args);
        }
        if ($verb !== 'list') {
            return NonInteractive::failUsage(
                $verb === null
                    ? 'candy-code: ' . Lang::t('cli.lsp.no_action')
                    : 'candy-code: ' . Lang::t('cli.lsp.unknown_action', ['action' => $verb]),
                $args->outputFormat,
                Lang::t('cli.lsp.usage'),
            );
        }

        $rows = self::lspListRows();

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            self::emitDocument(['result' => [
                'installRoot' => LspInstaller::installRoot(),
                'languages' => $rows,
            ]]);

            return NonInteractive::EXIT_OK;
        }

        $width = 0;
        foreach ($rows as $row) {
            $width = \max($width, \strlen($row['language']));
        }
        foreach ($rows as $row) {
            $state = $row['path'] !== null
                ? $row['path']
                : ($row['available']
                    ? Lang::t('cli.lsp.list.not_installed')
                    : Lang::t('cli.lsp.list.no_release', ['os' => $row['os'], 'arch' => $row['arch']]));
            \printf("%-{$width}s  %-40s  %s\n", $row['language'], (string) $row['pinned'], $state);
        }

        return NonInteractive::EXIT_OK;
    }

    /**
     * `candy-code lsp install <language>` — hand the one language operand
     * to {@see LspInstaller::install()} (or the test seam) and report its
     * verdict verbatim. A failure message already carries the `lsp install:`
     * self-label from the installer; it goes to stderr and exits 1 in BOTH
     * formats — the mcp-list law again, formatting never changes the verdict.
     */
    private static function lspInstall(ParsedArgs $args): int
    {
        $language = $args->subcommandArgs[1] ?? null;
        if ($language === null) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.lsp.install.no_language'),
                $args->outputFormat,
                Lang::t('cli.lsp.usage'),
            );
        }
        if (isset($args->subcommandArgs[2])) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.lsp.install.unexpected_operand', ['operand' => $args->subcommandArgs[2]]),
                $args->outputFormat,
                Lang::t('cli.lsp.usage'),
            );
        }

        $result = self::$lspInstaller === null
            ? LspInstaller::install($language)
            : (self::$lspInstaller)($language);

        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            if ($result['ok']) {
                self::emitDocument(['result' => [
                    'language' => $language,
                    'ok' => true,
                    'message' => $result['message'],
                    'path' => $result['path'],
                ]]);

                return NonInteractive::EXIT_OK;
            }

            self::emitDocument([
                'result' => null,
                'error' => ['type' => 'lsp-install', 'message' => $result['message']],
            ]);

            return NonInteractive::EXIT_FAILURE;
        }

        if ($result['ok']) {
            echo Lang::t('cli.lsp.install.done', [
                'message' => $result['message'],
                'path' => (string) $result['path'],
            ]) . "\n";

            return NonInteractive::EXIT_OK;
        }

        \fwrite(\STDERR, 'candy-code: ' . $result['message'] . "\n");

        return NonInteractive::EXIT_FAILURE;
    }

    /**
     * One row per {@see LspReleases::installableLanguages()}: the pinned
     * version (package@version on the npm arm, release tag on the binary
     * arm), the install path when the launch probe already finds the
     * binary executably, and whether a release exists for THIS machine.
     *
     * @return list<array{language: string, kind: string, pinned: string, binary: ?string, path: ?string, available: bool, os: string, arch: string}>
     */
    private static function lspListRows(): array
    {
        [$os, $arch] = self::lspHostTarget();
        $rows = [];
        foreach (LspReleases::installableLanguages() as $language) {
            $npm = LspReleases::npmEntry($language);
            if ($npm !== null) {
                $binary = $npm['binary'];
                $pinned = $npm['package'] . '@' . $npm['version'];
                $available = true;
            } else {
                $entry = LspReleases::binaryEntry($language, $os, $arch);
                $binary = $entry['binary'] ?? null;
                $pinned = LspReleases::RUST_ANALYZER_RELEASE_TAG;
                $available = $entry !== null;
            }
            $rows[] = [
                'language' => $language,
                'kind' => $npm !== null ? 'npm' : 'release',
                'pinned' => $pinned,
                'binary' => $binary,
                'path' => $binary === null ? null : LspInstaller::installedBinaryPath($binary),
                'available' => $available,
                'os' => $os,
                'arch' => $arch,
            ];
        }

        return $rows;
    }

    /**
     * The host's "<os>,<arch>" target keys, derived EXACTLY as
     * {@see LspInstaller::install()} derives its defaults — the same Darwin
     * comparison and the same arch normalisation, duplicated here because
     * the installer keeps both private. {@see LspSubcommandTest} pins the
     * mirror against the installer source so the two cannot drift.
     *
     * @return array{0: string, 1: string}
     */
    private static function lspHostTarget(): array
    {
        $os = \strcasecmp(\PHP_OS_FAMILY, 'Darwin') === 0 ? 'darwin' : 'linux';
        $lower = \strtolower(\php_uname('m'));
        if ($lower === 'amd64' || $lower === 'x86_64' || $lower === 'x64') {
            $arch = 'x86_64';
        } elseif ($lower === 'arm64' || $lower === 'aarch64') {
            $arch = 'aarch64';
        } else {
            $arch = $lower;
        }

        return [$os, $arch];
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
        '--agent' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.agent'],
        '--batch' => ['short' => null, 'value' => null, 'desc' => 'cli.completion.option.batch'],
        '--permission-mode' => ['short' => null, 'value' => 'mode', 'desc' => 'cli.completion.option.mode'],
        '--max-cost' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.max_cost'],
        '--max-turns' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.max_turns'],
        '--json-schema' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.json_schema'],
        '--append-system-prompt' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.append_system_prompt'],
        // D1/D2: the completion census in BinCandyCodeDispatchTest scrapes
        // the parser's bare-arm spellings, so every recognized flag needs a
        // row here. 'text' values, not 'format': that arm hardcodes the
        // OUTPUT format words, which are the wrong set for input formats.
        '--session-id' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.session_id'],
        '--input-format' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.input_format'],
        '--continue' => ['short' => '-c', 'value' => null, 'desc' => 'cli.completion.option.continue'],
        '--resume' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.resume'],
        // C-AGT-6.c shipped the parser branch without this row, so no shell
        // ever offered --worktree (row added at the §7(1) gate cure).
        '--worktree' => ['short' => null, 'value' => 'text', 'desc' => 'cli.completion.option.worktree'],
        '--incognito' => ['short' => null, 'value' => null, 'desc' => 'cli.completion.option.incognito'],
        '--restricted' => ['short' => null, 'value' => null, 'desc' => 'cli.completion.option.restricted'],
        '--safe-mode' => ['short' => null, 'value' => null, 'desc' => 'cli.completion.option.safe_mode'],
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
        'gc' => 'cli.completion.verb.gc',
        'import' => 'cli.completion.verb.import',
        'login' => 'cli.completion.verb.login',
        'lsp' => 'cli.completion.verb.lsp',
        'mcp' => 'cli.completion.verb.mcp',
        'models' => 'cli.completion.verb.models',
        'self-update' => 'cli.completion.verb.self_update',
        'serve' => 'cli.completion.verb.serve',
        'session' => 'cli.completion.verb.session',
        'usage' => 'cli.completion.verb.usage',
    ];

    /**
     * Each subcommand's own operands, for the nested completion the three
     * scripts all offer after the verb.
     *
     * @var array<string, list<string>>
     */
    private const SUBCOMMAND_ACTIONS = [
        // C-SES-7.b: 'import' sits with the verbs that CREATE rows — it never
        // needs a <target>, its operand is a transcript file.
        'session' => ['list', 'show', 'import', 'rename', 'delete', 'pin', 'unpin', 'archive', 'unarchive'],
        // gc-cli-1106: all five rungs landed (B1 status/vacuum, B2 sessions/
        // analyze, B3 purge); order matches the gc() dispatch arms.
        'gc' => ['status', 'sessions', 'analyze', 'purge', 'vacuum'],
        'bundles' => ['list', 'install', 'marketplace', 'update', 'search', 'recommend'],
        'lsp' => ['install', 'list'],
        'mcp' => ['list', 'auth', 'import', 'trust', 'serve'],
        'import' => ['claude'],
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

    /**
     * `candy-code workflow run|status|resume <name|id>` - the native
     * headless door onto the workflow engine (W-1). The engine runs on this
     * process with NO chat session and no model in the loop for the
     * dispatch itself, so a workflow can be executed and scripted directly.
     * A signal-pause writes the engine's cooperative pause record and
     * leaves through the W-2 observer at exit 3 with a valid machine
     * document on stdout (D4).
     */
    private static function workflow(ParsedArgs $args): int
    {
        $verb = $args->subcommandArgs[0] ?? null;
        if ($verb === null) {
            return NonInteractive::failUsage('candy-code: ' . Lang::t('cli.workflow.no_action'), $args->outputFormat, self::workflowUsage());
        }
        if (!isset(self::WORKFLOW_ACTION_FLAGS[$verb])) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.workflow.unknown_action', ['action' => $verb]),
                $args->outputFormat,
                self::workflowUsage(),
            );
        }

        foreach (\array_keys($args->subcommandFlags) as $flag) {
            if (!\in_array($flag, self::WORKFLOW_ACTION_FLAGS[$verb], true)) {
                return NonInteractive::failUsage(
                    'candy-code: ' . Lang::t('cli.workflow.flag_does_not_apply', ['action' => $verb, 'flag' => $flag]),
                    $args->outputFormat,
                    Lang::t('cli.workflow.accepts', ['action' => $verb, 'flags' => \implode(', ', self::WORKFLOW_ACTION_FLAGS[$verb])]),
                );
            }
        }

        $spelled = isset($args->subcommandFlags['--format'])
            ? (string) $args->subcommandFlags['--format']
            : NonInteractive::FORMAT_TEXT;
        if ($spelled !== NonInteractive::FORMAT_TEXT && $spelled !== NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.workflow.bad_format', ['format' => $spelled]),
                $args->outputFormat,
                self::workflowUsage(),
            );
        }
        $asJson = $spelled === NonInteractive::FORMAT_JSON || $args->outputFormat === NonInteractive::FORMAT_JSON;

        return match ($verb) {
            'run' => self::workflowRun($args, $asJson),
            'status' => self::workflowStatus($args, $asJson),
            default => self::workflowResume($args, $asJson),
        };
    }

    /**
     * One line, for every `workflow` usage error - the gcUsage() shape.
     */
    private static function workflowUsage(): string
    {
        return Lang::t('cli.workflow.usage');
    }

    /**
     * `workflow run <name> [--param k=v]...` - load by name or path through
     * the shared engine and execute to completion here. Refusals (unknown
     * name, malformed definition, permission door) pass the engine's raw
     * English through untranslated - the law {@see self::gc()} follows for
     * store errors too.
     */
    private static function workflowRun(ParsedArgs $args, bool $asJson): int
    {
        $name = $args->subcommandArgs[1] ?? null;
        if ($name === null || $name === '') {
            return NonInteractive::failUsage('candy-code: ' . Lang::t('cli.workflow.need_name'), $args->outputFormat, self::workflowUsage());
        }

        $pairsRaw = $args->subcommandFlags['--param'] ?? null;
        [$context, $problems] = self::parseParamPairs(\is_string($pairsRaw) ? $pairsRaw : null);
        if ($problems !== []) {
            return NonInteractive::failUsage(
                'candy-code: ' . Lang::t('cli.workflow.bad_param', ['problem' => $problems[0]]),
                $args->outputFormat,
                self::workflowUsage(),
            );
        }

        $engine = Bootstrap::workflowEngine($args->root, Bootstrap::permissionGate());
        $engine->setSignalPauseHandler(
            static fn (\SugarCraft\Code\Workflows\WorkflowResult $partial, ?string $pausePath): int
                => self::workflowSignalPause($asJson, 'run', $partial, $pausePath),
        );

        try {
            $result = $engine->run($name, $context);
        } catch (\Throwable $e) {
            return self::workflowRefused($asJson, 'run', $name, $e->getMessage());
        }

        return self::workflowReport($asJson, 'run', $name, $result);
    }

    /**
     * `workflow status <name>` - the engine's own lookup (live runs,
     * remembered results, then pause records). Never dials a model.
     */
    private static function workflowStatus(ParsedArgs $args, bool $asJson): int
    {
        $name = $args->subcommandArgs[1] ?? null;
        if ($name === null || $name === '') {
            return NonInteractive::failUsage('candy-code: ' . Lang::t('cli.workflow.need_name'), $args->outputFormat, self::workflowUsage());
        }

        $engine = Bootstrap::workflowEngine($args->root, Bootstrap::permissionGate());
        try {
            $status = $engine->status($name);
        } catch (\Throwable $e) {
            return self::workflowRefused($asJson, 'status', $name, $e->getMessage());
        }

        if ($asJson) {
            self::emitDocument([
                'verb'   => 'workflow',
                'action' => 'status',
                'result' => ['workflow' => $name, 'status' => $status->value, 'session_id' => null],
            ]);

            return NonInteractive::EXIT_OK;
        }

        echo 'candy-code workflow status: ', $name, ' - ', $status->value, \PHP_EOL;

        return NonInteractive::EXIT_OK;
    }

    /**
     * `workflow resume <id>` - replays the recorded context from the pause
     * file; the engine refuses records that are live in another process and
     * this verb passes that refusal through as a failure (exit 1).
     */
    private static function workflowResume(ParsedArgs $args, bool $asJson): int
    {
        $id = $args->subcommandArgs[1] ?? null;
        if ($id === null || $id === '') {
            return NonInteractive::failUsage('candy-code: ' . Lang::t('cli.workflow.need_id'), $args->outputFormat, self::workflowUsage());
        }

        $engine = Bootstrap::workflowEngine($args->root, Bootstrap::permissionGate());
        $engine->setSignalPauseHandler(
            static fn (\SugarCraft\Code\Workflows\WorkflowResult $partial, ?string $pausePath): int
                => self::workflowSignalPause($asJson, 'resume', $partial, $pausePath),
        );

        try {
            $result = $engine->resume($id);
        } catch (\Throwable $e) {
            return self::workflowRefused($asJson, 'resume', $id, $e->getMessage());
        }

        return self::workflowReport($asJson, 'resume', $id, $result);
    }

    /**
     * The W-2/D4 observer: reached only AFTER the engine wrote the
     * cooperative pause record. Emit the stopped run's document (or the
     * text line on STDERR), then let the process exit 3 - the CLI's answer
     * to the raw 130/143 a TUI session still takes.
     */
    private static function workflowSignalPause(
        bool $asJson,
        string $action,
        \SugarCraft\Code\Workflows\WorkflowResult $partial,
        ?string $pausePath,
    ): int {
        if ($asJson) {
            $doc = self::workflowResultDoc($partial->workflowId, $partial);
            $doc['status'] = 'paused';
            $doc['pause_file'] = $pausePath;
            self::emitDocument(['verb' => 'workflow', 'action' => $action, 'result' => $doc]);
        } else {
            fwrite(\STDERR, 'candy-code: ' . Lang::t('cli.workflow.paused', ['id' => $partial->workflowId]) . \PHP_EOL);
        }

        return self::WORKFLOW_EXIT_STOPPED;
    }

    /**
     * Text/JSON report of a finished, failed or escalated run, with its D4
     * exit code.
     */
    private static function workflowReport(
        bool $asJson,
        string $action,
        string $name,
        \SugarCraft\Code\Workflows\WorkflowResult $result,
    ): int {
        $exit = self::workflowExitCode($result);

        if ($asJson) {
            self::emitDocument([
                'verb'   => 'workflow',
                'action' => $action,
                'result' => self::workflowResultDoc($name, $result),
            ]);

            return $exit;
        }

        echo 'candy-code workflow ', $action, ': ', $name, ' - ', $result->status->value,
            ' (', \count($result->stageResults), ' stages)', \PHP_EOL;
        foreach ($result->stageResults as $stage) {
            echo '  ', $stage->stageName, ': ', $stage->status->value, \PHP_EOL;
        }
        $failure = $result->firstFailure();
        if ($failure !== null && $failure->error !== null) {
            echo '  error: ', $failure->error, \PHP_EOL;
        }

        return $exit;
    }

    /**
     * D4 exit mapping. An escalated verification rides the Failed channel
     * with the engine's ESCALATION_MARKER prefix on the first failure's
     * error - the one stop-mode that is NOT a workflow defect: a person
     * answers and resumes, so it exits 3, not 1.
     */
    private static function workflowExitCode(\SugarCraft\Code\Workflows\WorkflowResult $result): int
    {
        if ($result->isSuccess()) {
            return NonInteractive::EXIT_OK;
        }

        $failure = $result->firstFailure();
        if ($failure !== null
            && \str_starts_with((string) $failure->error, \SugarCraft\Code\Workflows\WorkflowEngine::ESCALATION_MARKER)) {
            return self::WORKFLOW_EXIT_STOPPED;
        }

        return NonInteractive::EXIT_FAILURE;
    }

    /**
     * The machine document's result body: stage rows (name, status, output,
     * error, agent-attempt count), totals, and session_id - null, because
     * the native verb mints NO chat session; it is the engine directly
     * (the key ships for schema stability; disclosed seam).
     *
     * @return array<string, mixed>
     */
    private static function workflowResultDoc(
        string $name,
        \SugarCraft\Code\Workflows\WorkflowResult $result,
    ): array {
        $rows = [];
        foreach ($result->stageResults as $stage) {
            $rows[] = [
                'name'     => $stage->stageName,
                'status'   => $stage->status->value,
                'output'   => $stage->output,
                'error'    => $stage->error,
                'attempts' => \count($stage->agents),
            ];
        }

        return [
            'workflow'   => $name,
            'status'     => $result->status->value,
            'stages'     => $rows,
            'totals'     => [
                'tokens'      => $result->totalTokens,
                'cost'        => $result->totalCost,
                'duration_ms' => $result->totalDurationMs(),
            ],
            'session_id' => null,
        ];
    }

    /**
     * The refusal surface: engine/registry raw English passes through
     * untranslated (law), JSON under the schema envelope with status
     * 'refused', text on STDERR - exit 1, never a silent 0.
     */
    private static function workflowRefused(bool $asJson, string $action, string $name, string $message): int
    {
        if ($asJson) {
            self::emitDocument([
                'verb'   => 'workflow',
                'action' => $action,
                'result' => [
                    'workflow'   => $name,
                    'status'     => 'refused',
                    'refusal'    => $message,
                    'stages'     => [],
                    'totals'     => ['tokens' => 0, 'cost' => 0.0, 'duration_ms' => 0],
                    'session_id' => null,
                ],
            ]);

            return NonInteractive::EXIT_FAILURE;
        }

        fwrite(\STDERR, 'candy-code: workflow ' . $action . ': ' . $message . \PHP_EOL);

        return NonInteractive::EXIT_FAILURE;
    }

    /**
     * Split the comma-JOINED `--param` payload ArgvParser assembled from
     * repeated flags ('a=1,b="x,y"') into context pairs, honouring quoted
     * spans: a comma inside quotes is DATA, not a separator (the gc
     * `--session` list needs no quoting; parameters do, because prompt
     * contexts carry prose). Validation mirrors the TUI slash tokenizer's
     * rules: k=v shape, non-empty key, no '@' keys - the engine's
     * reserved-context law. Returns [pairs, problems]; problems are
     * human-readable, and the first one fails usage.
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function parseParamPairs(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [[], []];
        }

        $segments = [];
        $buffer = '';
        $quote = null;
        $length = \strlen($raw);
        for ($i = 0; $i < $length; ++$i) {
            $char = $raw[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ',') {
                $segments[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if ($quote !== null) {
            return [[], ['unterminated quote in `' . $buffer . '`']];
        }
        $segments[] = $buffer;

        $pairs = [];
        $problems = [];
        foreach ($segments as $segment) {
            $segment = \trim($segment);
            if ($segment === '') {
                continue;
            }
            $equals = \strpos($segment, '=');
            if ($equals === false) {
                $problems[] = 'expected key=value, got `' . $segment . '`';
                continue;
            }
            $key = \substr($segment, 0, $equals);
            $value = \substr($segment, $equals + 1);
            if ($key === '') {
                $problems[] = 'empty key in `' . $segment . '`';
                continue;
            }
            if (\str_starts_with($key, '@')) {
                $problems[] = 'reserved key `' . $key . '` - @-prefixed context belongs to the engine';
                continue;
            }
            if (\strlen($value) >= 2
                && (($value[0] === '"' && \substr($value, -1) === '"')
                    || ($value[0] === "'" && \substr($value, -1) === "'"))) {
                $value = \substr($value, 1, -1);
            }
            $pairs[$key] = $value;
        }

        return [$pairs, $problems];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\LSP;

use SugarCraft\Code\Tools\Concerns\DetectsCapabilities;

/**
 * Starts the language servers the user configured under the `lsp` settings
 * key and hands them over as one {@see LspClient} (step 3.F).
 *
 * THE SETTING, user tier only — starting a server is code execution, exactly
 * as it is for `.mcp.json`, so no project file may name one:
 *
 *     "lsp": {
 *       "php": {"command": "intelephense", "args": ["--stdio"]},
 *       "typescript": {"command": "typescript-language-server", "args": ["--stdio"],
 *                      "extensions": ["ts", "tsx"]}
 *     }
 *
 * Each key is the LSP language identifier sent in `didOpen`. Per server:
 * `command` (required), `args`, `extensions` (default: the key itself), `env`,
 * `initializationOptions`, `timeout` (seconds per request, default
 * {@see DEFAULT_TIMEOUT_SECONDS}) and `disabled`.
 *
 * AUTO-DETECT IS THE OTHER USER-TIER STATEMENT (EDT-2): with `lspAutoDetect`
 * true, {@see AUTO_TABLE} starts the server a project fingerprint names and a
 * binary is installed for. Detection reads; launching is the execution, so
 * the table runs ONLY on that opt-in AND on the project-trust input
 * {@see \SugarCraft\Code\Cli\Bootstrap::lspClient()} feeds it — the same
 * grant that already means "this repository's code may run here" (C-TOOL-7.c:
 * the fingerprints live in a CLONE's files, and rust-analyzer runs
 * `build.rs` before first paint). Explicit entries never consult trust: they
 * are the user's own words. Explicit entries win per language, the
 * MAX_SERVERS cap is shared, and every fingerprint outcome — started OR
 * skipped for trust — reports itself through {@see problems()} so no launch
 * has a mystery server and no silent loss is mistaken for no fingerprint.
 *
 * THE HANDSHAKE IS SPOKEN HERE, not through {@see LspConnection::initialize()},
 * because that one sends no `rootUri`: a server that is not told the workspace
 * root indexes nothing but the single file in front of it, and a cross-file
 * diagnostic ("undefined class") then reads every class in the project as
 * missing. A connection without a public `sendRequest()` (an in-memory fake)
 * gets `initialize()` instead.
 *
 * FAILS OPEN ON CAPABILITY. A malformed entry, a command that will not start
 * or a server that will not answer `initialize` costs that one server and is
 * reported in {@see problems()}; the others still start, and a launch with
 * none left simply has no client. Nothing here refuses the launch.
 *
 * Started ONCE, in the process that builds the tools: {@see LspConnection} is
 * fork-safe, so forked turns, tool calls and sub-agents share the servers.
 * {@see \SugarCraft\Code\Cli\Bootstrap::lspClient()} owns the memo and the
 * shutdown hook.
 */
final class LspLauncher
{
    use DetectsCapabilities;

    /** The settings key the server list is read from. */
    public const SETTINGS_KEY = 'lsp';

    /** Each request's bound, in seconds. */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /**
     * The handshake's own bound (rv-tui-2), in seconds.
     *
     * `Bootstrap::app()` builds the tool set — and with it the LSP client —
     * BEFORE the first frame paints, so this handshake is the pre-paint block
     * the user waits through. A cold language server routinely takes longer
     * than a repaint is worth: at the 10 s per-request default, eight hostile
     * servers (MAX_SERVERS) held a launch for up to eighty seconds. The
     * `initialize`/`initialized` exchange is now bounded at 2 s each (the
     * smaller of this and the server's own `timeout`), after which the
     * connection's regular request budget is restored for tool traffic.
     * A server that misses the 2 s window fails that one server — the
     * fail-open-on-capability doctrine above — instead of stealing the frame.
     *
     * The fully paint-first shape (handshake completes AFTER the first frame
     * via an init-Cmd and a ready message) needs `App`/`Chat` wiring outside
     * this file's reach and is recorded as the lane's seam; the bound here is
     * the half that lives entirely in the launcher.
     */
    public const DEFAULT_HANDSHAKE_TIMEOUT_SECONDS = 2.0;

    /** The most servers one launch starts — each is a long-lived process. */
    public const MAX_SERVERS = 8;

    /**
     * The settings key that lets the fingerprints below start servers (EDT-2).
     * User tier, default off: auto-DETECTING a language is reading a file, but
     * the detection's whole output is a LAUNCH, and launching is the code
     * execution {@see self::$root}'s docblock above reserves to the user — so
     * the table may only run where the user has said "yes, launch what you
     * find" AND, per root, where the project itself carries the hooks-trust
     * grant (C-TOOL-7.c; {@see self::fromConfig()}'s `$projectTrusted`). With
     * the key off nothing here consults the table at all, which is
     * what keeps every parse below byte-identical to the manual-only launcher
     * it grew out of.
     */
    public const AUTO_DETECT_SETTINGS_KEY = 'lspAutoDetect';

    /**
     * Fingerprint => language => candidate servers, in preference order (the
     * first candidate with a present binary wins; none present, nothing
     * starts). "Present" consults the LSP install tree first — a server the
     * user installed via {@see \SugarCraft\Code\LSP\LspInstaller} beats an
     * same-named binary on PATH — then falls back to the PATH lookup
     * (C-TOOL-5.c). A row fires only when EVERY file in `files` is missing its
     * `any` flag or at least one is present with it; the language is the
     * standard LSP `didOpen` identifier and `extensions` the suffixes that
     * route into it ({@see \SugarCraft\Code\LSP\LspClient::languageFor()}).
     *
     * @var list<array{language: string, files: list<string>, any: bool, extensions: list<string>, candidates: array<string, list<string>>}>
     */
    private const AUTO_TABLE = [
        [
            'language' => 'php',
            'files' => ['composer.json'],
            'any' => false,
            'extensions' => ['php'],
            'candidates' => ['intelephense' => ['--stdio']],
        ],
        [
            // Both files: package.json alone is plain JavaScript, and
            // typescript-language-server on a JS-only tree indexes nothing
            // the tooling resolves — the pair is the TypeScript fingerprint.
            'language' => 'typescript',
            'files' => ['package.json', 'tsconfig.json'],
            'any' => false,
            'extensions' => ['ts', 'tsx', 'js', 'jsx'],
            'candidates' => ['typescript-language-server' => ['--stdio']],
        ],
        [
            'language' => 'python',
            'files' => ['pyproject.toml', 'setup.py'],
            'any' => true,
            'extensions' => ['py'],
            'candidates' => ['pyright-langserver' => ['--stdio']],
        ],
        [
            'language' => 'go',
            'files' => ['go.mod'],
            'any' => false,
            'extensions' => ['go'],
            'candidates' => ['gopls' => []],
        ],
        [
            'language' => 'rust',
            'files' => ['Cargo.toml'],
            'any' => false,
            'extensions' => ['rs'],
            'candidates' => ['rust-analyzer' => []],
        ],
    ];

    /**
     * @param list<array{language: string, command: string, args: list<string>, extensions: list<string>, env: array<string, string>, initializationOptions: ?array<mixed>, timeout: float}> $servers
     * @param list<string> $problems
     */
    private function __construct(
        private readonly string $root,
        private readonly array $servers,
        private readonly array $problems,
    ) {
    }

    /**
     * Parse the `lsp` setting for a launch rooted at $root. Null or absent is
     * "no servers", not a problem.
     *
     * With `$autoDetect` (the `lspAutoDetect` key, EDT-2) the
     * {@see AUTO_TABLE} fingerprints of $root are consulted after the
     * explicit entries — but only when `$projectTrusted` says this root may
     * run the user's code (C-TOOL-7.c): the fingerprints live in a CLONE's
     * own files, and starting rust-analyzer runs its `build.rs`, so the
     * table rides the hooks-trust grant the launcher is given, never its own
     * reading of the tree. Explicit entries are unaffected by trust — a
     * named server is the user's own command line. Key on, trust off: the
     * table's servers stay down and {@see problems()} carries the skip line,
     * so the loss is loud on the same surface that names every started
     * server — the explicit half is exactly what it would have been. A row
     * fills only a
     * language NO explicit entry spoke for — including a `disabled` one,
     * because switching a language off is the user speaking for it — and only
     * when a candidate binary resolves. `$binaryPresent` is a seam so tests
     * never read the real PATH nor the install tree: injecting it keeps the
     * bare-name contract byte-identical, and the install dir is never
     * consulted. Production leaves it null and gets the C-TOOL-5.c ladder —
     * `$installRoot` (default {@see LspInstaller::installRoot()}) bin/ and
     * node_modules/.bin/ checked first, an install-dir hit stores the
     * ABSOLUTE path so launch() execs the installed copy, then the shared
     * capability-memo PATH lookup stores the bare name.
     */
    public static function fromConfig(mixed $raw, string $root, bool $autoDetect = false, ?callable $binaryPresent = null, bool $projectTrusted = false, ?string $installRoot = null): self
    {
        if ($raw === null || $raw === [] || $raw === false) {
            $raw = [];
        } elseif (!\is_array($raw) || array_is_list($raw)) {
            if (!$autoDetect) {
                return new self($root, [], ['the `lsp` setting must be an object of language => server; it was ignored']);
            }
            // Under auto-detect the map is not the only source of servers, so
            // a malformed one costs the explicit half, not the whole key.
            $malformed = ['the `lsp` setting must be an object of language => server; it was ignored'];
            $raw = [];
        }

        $servers = [];
        $problems = $malformed ?? [];
        // Every language an entry SPOKE for — started, disabled, or ignored
        // after its shape named the language. The first two hold it from the
        // table by right; the third holds it by caution: the user meant to
        // define php, and quietly launching some other php server over their
        // broken one would hide the problem line they need to read.
        $claimed = [];
        foreach ($raw as $language => $spec) {
            $language = (string) $language;
            if (\count($servers) >= self::MAX_SERVERS) {
                $problems[] = sprintf('lsp: only the first %d servers are started; "%s" and later entries were skipped', self::MAX_SERVERS, $language);
                break;
            }
            if (preg_match('/^[A-Za-z][A-Za-z0-9_+.-]*$/', $language) === 1 && \is_array($spec) && !($spec !== [] && array_is_list($spec))) {
                $claimed[$language] = true;
            }
            $parsed = self::parseServer($language, $spec);
            if (\is_string($parsed)) {
                $problems[] = $parsed;
            } elseif ($parsed !== null) {
                $servers[] = $parsed;
            }
        }

        if ($autoDetect && !$projectTrusted) {
            // C-TOOL-7.c: the fingerprints are read out of the clone's own
            // tree, and acting on them is a launch. Provenance rows are what
            // this method emits for every fingerprint outcome, so the skipped
            // one lands on the same surface and is never mistaken for
            // "matched nothing".
            $problems[] = 'lsp: auto-detect skipped — this project is not trusted for hooks';
        } elseif ($autoDetect) {
            // C-TOOL-5.c: with no injected probe, resolution prefers the
            // install tree (bin/ then node_modules/.bin/) over PATH, and an
            // install-dir hit is stored as an ABSOLUTE path so launch()
            // execs the installed copy, not whatever PATH answers. An
            // injected probe keeps the old bare-name contract byte-for-byte
            // and the install tree is never consulted.
            if ($binaryPresent === null) {
                $installRoot ??= LspInstaller::installRoot();
            }
            foreach (self::AUTO_TABLE as $row) {
                if (isset($claimed[$row['language']]) || \count($servers) >= self::MAX_SERVERS) {
                    continue;
                }
                if (!self::fingerprintMatches($root, $row['files'], $row['any'])) {
                    continue;
                }
                foreach ($row['candidates'] as $command => $args) {
                    if ($binaryPresent === null) {
                        $installed = LspInstaller::installedBinaryPath($command, $installRoot);
                        $chosen = $installed ?? (self::capabilityPresent($command) ? $command : '');
                    } else {
                        $chosen = $binaryPresent($command) ? $command : '';
                    }
                    if ($chosen === '') {
                        continue;
                    }
                    $servers[] = [
                        'language' => $row['language'],
                        'command' => $chosen,
                        'args' => $args,
                        'extensions' => $row['extensions'],
                        'env' => [],
                        'initializationOptions' => null,
                        'timeout' => self::DEFAULT_TIMEOUT_SECONDS,
                    ];
                    // Provenance, not a problem: the launch report is the
                    // surface that shows WHERE a server came from, and a
                    // server started by fingerprint rather than by name must
                    // never look like the user's own entry.
                    $problems[] = sprintf('lspAutoDetect started %s=%s', $row['language'], $chosen);
                    break;
                }
            }
        }

        return new self($root, $servers, $problems);
    }

    /**
     * Whether $root carries the row's fingerprint: every file when $any is
     * false, at least one when it is true.
     */
    private static function fingerprintMatches(string $root, array $files, bool $any): bool
    {
        $seen = false;
        foreach ($files as $file) {
            if (is_file($root . '/' . $file)) {
                if ($any) {
                    return true;
                }
                $seen = true;
            } elseif (!$any) {
                return false;
            }
        }

        return $seen;
    }

    /** Whether any server is configured (and not disabled). */
    public function hasServers(): bool
    {
        return $this->servers !== [];
    }

    /** @return list<string> the configured languages, in configuration order */
    public function languages(): array
    {
        return array_column($this->servers, 'language');
    }

    /**
     * What went wrong parsing or starting, one line each — for the launch
     * report. Grows as {@see launch()} meets servers that will not start.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Start every configured server and return the client over the ones that
     * came up, or null when none did. The second element is this launcher with
     * the start-up failures appended to {@see problems()}.
     *
     * @param \Closure(string, list<string>): LspConnectionInterface|null $connect
     *        builds the connection for a command and its arguments; null
     *        builds a real {@see LspConnection}
     * @return array{0: ?LspClient, 1: self}
     */
    public function launch(?\Closure $connect = null): array
    {
        $connect ??= static fn (string $command, array $args): LspConnectionInterface => new LspConnection($command, $args);

        $client = null;
        $problems = $this->problems;
        foreach ($this->servers as $server) {
            $connection = $connect($server['command'], $server['args']);
            $failure = $this->start($connection, $server);
            if ($failure !== null) {
                $problems[] = sprintf('lsp: the %s server (%s) did not start: %s', $server['language'], $server['command'], $failure);
                continue;
            }

            if ($client === null) {
                $client = new LspClient($connection, new LspCache(), $server['language']);
            } else {
                $client->addServer($server['language'], $connection, new LspCache());
            }
            $client->mapExtensions($server['language'], $server['extensions']);
        }

        return [$client, new self($this->root, $this->servers, $problems)];
    }

    /**
     * Spawn and initialise one server; null on success, else why not. A server
     * that started but failed the handshake is stopped again.
     *
     * @param array{language: string, command: string, args: list<string>, extensions: list<string>, env: array<string, string>, initializationOptions: ?array<mixed>, timeout: float} $server
     */
    private function start(LspConnectionInterface $connection, array $server): ?string
    {
        try {
            // rv-tui-2: the connect budget is the HANDSHAKE's, never the
            // server's full per-request timeout — this exchange happens
            // pre-paint. `min()` so a config that asks for a tighter timeout
            // still gets its own number.
            $connection->connect($server['command'], $server['env'], $this->root, min($server['timeout'], self::DEFAULT_HANDSHAKE_TIMEOUT_SECONDS));

            if (!method_exists($connection, 'sendRequest') || !method_exists($connection, 'sendNotification')) {
                $connection->initialize();

                return null;
            }

            /** @var LspResponse $response */
            $response = $connection->sendRequest('initialize', $this->initializeParams($server));
            if ($response->isError) {
                $connection->disconnect();

                return $response->errorMessage ?? 'initialize failed';
            }
            $connection->sendNotification('initialized', []);

            // Handshake cleared — hand the connection back the per-request
            // budget tools expect (see LspConnection::setRequestTimeout()).
            if (method_exists($connection, 'setRequestTimeout')) {
                $connection->setRequestTimeout($server['timeout']);
            }

            return null;
        } catch (\Throwable $e) {
            try {
                $connection->disconnect();
            } catch (\Throwable) {
                // Already gone.
            }

            return $e->getMessage();
        }
    }

    /**
     * @param array{language: string, initializationOptions: ?array<mixed>} $server
     * @return array<string, mixed>
     */
    private function initializeParams(array $server): array
    {
        $rootUri = LspClient::uriFor($this->root);
        $params = [
            'processId' => getmypid() ?: null,
            'clientInfo' => ['name' => 'candy-code', 'version' => '1.0.0'],
            'rootUri' => $rootUri,
            'rootPath' => $this->root,
            'workspaceFolders' => [['uri' => $rootUri, 'name' => basename($this->root) ?: $this->root]],
            'capabilities' => [
                'textDocument' => [
                    'synchronization' => ['didSave' => true, 'dynamicRegistration' => false],
                    'publishDiagnostics' => ['relatedInformation' => false, 'versionSupport' => true],
                    'diagnostic' => ['dynamicRegistration' => false, 'relatedDocumentSupport' => false],
                    'hover' => ['contentFormat' => ['plaintext', 'markdown']],
                    'definition' => ['linkSupport' => false],
                    'references' => [],
                    'documentSymbol' => ['hierarchicalDocumentSymbolSupport' => true],
                    'codeAction' => [],
                ],
                'workspace' => ['workspaceFolders' => true, 'configuration' => false],
            ],
        ];
        if ($server['initializationOptions'] !== null) {
            $params['initializationOptions'] = $server['initializationOptions'];
        }

        return $params;
    }

    /**
     * One entry, validated: the parsed server, null for a disabled one, or the
     * reason it was dropped.
     *
     * @return array{language: string, command: string, args: list<string>, extensions: list<string>, env: array<string, string>, initializationOptions: ?array<mixed>, timeout: float}|string|null
     */
    private static function parseServer(string $language, mixed $spec): array|string|null
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_+.-]*$/', $language) !== 1) {
            return sprintf('lsp: "%s" is not a language identifier; that entry was ignored', $language);
        }
        if (!\is_array($spec) || ($spec !== [] && array_is_list($spec))) {
            return sprintf('lsp: the %s entry must be an object with a "command"; it was ignored', $language);
        }
        if (($spec['disabled'] ?? false) === true) {
            return null;
        }

        $command = $spec['command'] ?? null;
        if (!\is_string($command) || trim($command) === '') {
            return sprintf('lsp: the %s entry has no "command"; it was ignored', $language);
        }

        $args = $spec['args'] ?? [];
        if (!\is_array($args) || !array_is_list($args) || array_filter($args, static fn (mixed $a): bool => !\is_string($a)) !== []) {
            return sprintf('lsp: the %s entry\'s "args" must be a list of strings; it was ignored', $language);
        }

        $extensions = $spec['extensions'] ?? [$language];
        if (!\is_array($extensions) || !array_is_list($extensions) || array_filter($extensions, static fn (mixed $e): bool => !\is_string($e)) !== []) {
            return sprintf('lsp: the %s entry\'s "extensions" must be a list of strings; it was ignored', $language);
        }

        $env = $spec['env'] ?? [];
        if (!\is_array($env) || ($env !== [] && array_is_list($env))) {
            return sprintf('lsp: the %s entry\'s "env" must be an object of strings; it was ignored', $language);
        }
        $cleanEnv = [];
        foreach ($env as $name => $value) {
            if (!\is_string($value)) {
                return sprintf('lsp: the %s entry\'s "env" must be an object of strings; it was ignored', $language);
            }
            $cleanEnv[(string) $name] = $value;
        }

        $options = $spec['initializationOptions'] ?? null;
        if ($options !== null && !\is_array($options)) {
            return sprintf('lsp: the %s entry\'s "initializationOptions" must be an object; it was ignored', $language);
        }

        $timeout = $spec['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS;
        if ((!\is_int($timeout) && !\is_float($timeout)) || $timeout <= 0 || $timeout > 300) {
            return sprintf('lsp: the %s entry\'s "timeout" must be a number of seconds between 0 and 300; it was ignored', $language);
        }

        return [
            'language' => $language,
            'command' => $command,
            'args' => $args,
            'extensions' => $extensions,
            'env' => $cleanEnv,
            'initializationOptions' => $options,
            'timeout' => (float) $timeout,
        ];
    }
}

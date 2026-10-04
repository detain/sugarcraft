<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

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
 * {@see \SugarCraft\Crush\Cli\Bootstrap::lspClient()} owns the memo and the
 * shutdown hook.
 */
final class LspLauncher
{
    /** The settings key the server list is read from. */
    public const SETTINGS_KEY = 'lsp';

    /** Each request's bound (and the `initialize` handshake's), in seconds. */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /** The most servers one launch starts — each is a long-lived process. */
    public const MAX_SERVERS = 8;

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
     */
    public static function fromConfig(mixed $raw, string $root): self
    {
        if ($raw === null || $raw === [] || $raw === false) {
            return new self($root, [], []);
        }
        if (!\is_array($raw) || array_is_list($raw)) {
            return new self($root, [], ['the `lsp` setting must be an object of language => server; it was ignored']);
        }

        $servers = [];
        $problems = [];
        foreach ($raw as $language => $spec) {
            $language = (string) $language;
            if (\count($servers) >= self::MAX_SERVERS) {
                $problems[] = sprintf('lsp: only the first %d servers are started; "%s" and later entries were skipped', self::MAX_SERVERS, $language);
                break;
            }
            $parsed = self::parseServer($language, $spec);
            if (\is_string($parsed)) {
                $problems[] = $parsed;
            } elseif ($parsed !== null) {
                $servers[] = $parsed;
            }
        }

        return new self($root, $servers, $problems);
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
            $connection->connect($server['command'], $server['env'], $this->root, $server['timeout']);

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
            'clientInfo' => ['name' => 'sugar-crush', 'version' => '1.0.0'],
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

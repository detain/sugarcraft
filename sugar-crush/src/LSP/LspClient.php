<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * LSP Client that wraps LspConnection with caching, diagnostics tracking,
 * multi-language server support, and graceful fallback to grep when LSP
 * is unavailable.
 *
 * CACHE KEY DOMAINS, because they differ per request and a single wrong one is
 * a stale answer reported as a fresh one: `documentSymbol` is cached per
 * `uri` + method, because that request asks about a whole file;
 * `definition` / `references` / `hover` / `codeAction` are cached per
 * `uri` + method + LINE + COLUMN (+ the codeAction context), because those ask
 * about a cursor — see {@see positionalKey()}. `diagnostics()` is not cached
 * here at all: it reads the map {@see handlePublishDiagnostics()} fills.
 *
 * Mirrors the LSP spec: https://microsoft.github.io/language-server-protocol/
 */
final class LspClient
{
    /**
     * @var array<string, LspConnectionInterface>
     */
    private array $connections = [];

    /**
     * @var array<string, LspCacheInterface>
     */
    private array $caches = [];

    /**
     * @var array<string, array<string, array<int, array<string, mixed>>>>
     *  diagnostics[uri] = list of Diagnostic
     */
    private array $diagnostics = [];

    /**
     * The language whose server the un-suffixed accessors use.
     *
     * NON-NULLABLE, AND THAT IS AN INVARIANT THE DELEGATION BELOW RESTS ON.
     * {@see __construct()} sets it to `php` and registers a connection and a
     * cache under that key; {@see use()} is the only other writer and refuses a
     * language `$connections` does not already hold. So the subscript cannot
     * miss, which is why {@see definitions()} and its four siblings could
     * subscript it without a guard, and why they can now forward to their
     * `…For()` twin — whose guard is therefore unreachable from this direction,
     * not merely unused.
     *
     * It was `?string $language = null` with the same invariant already true;
     * the type now says so. {@see language()} keeps its `?string` return so its
     * (currently unused) contract is unchanged.
     */
    private string $language = 'php';

    /**
     * File extension (lower case, no dot) → the language whose server owns it,
     * so a caller holding only a path — the post-edit diagnostics hook, a Read
     * outline, an `Lsp` call that names no language — reaches the right server
     * (step 3.F). Filled by {@see mapExtensions()}; see {@see languageFor()} for
     * the fallback when nothing was mapped.
     *
     * @var array<string, string>
     */
    private array $extensions = [];

    /**
     * The last `textDocument/didOpen` version sent per URI. A document is
     * opened, asked about and closed again inside one call
     * ({@see freshDiagnostics()}), so the server always reads it from the text
     * we sent; the version still climbs per touch so a publish for an older
     * touch can be told apart from the one being waited for.
     *
     * @var array<string, int>
     */
    private array $versions = [];

    /**
     * URIs open on their server right now — between a touch's `didOpen` and its
     * `didClose`. A `publishDiagnostics` for a document that is NOT open is not
     * recorded: after `didClose` most servers publish an empty list for the
     * closed file, and taking that for a verdict would make every touched file
     * read as clean on the next look.
     *
     * @var array<string, true>
     */
    private array $openDocuments = [];

    /** How long {@see freshDiagnostics()} sleeps between two pumps of the server's output. */
    private const DIAGNOSTICS_POLL_MICROS = 150_000;

    /**
     * $language is the key the injected connection is registered under. It
     * defaults to `php` because that is what every caller before step 3.F
     * meant; {@see LspLauncher} passes the configured server's own key.
     */
    public function __construct(
        private readonly LspConnectionInterface $connection,
        private readonly LspCacheInterface $cache,
        string $language = 'php',
    ) {
        // Default server is the injected one.
        $this->language = $language;
        $this->connections[$this->language] = $connection;
        $this->caches[$this->language] = $cache;
        $this->subscribe($connection);
    }

    // -------------------------------------------------------------------------
    // Server management
    // -------------------------------------------------------------------------

    /**
     * Register an additional language server.
     *
     * @param string                 $language   Language identifier (e.g. "php", "typescript")
     * @param LspConnectionInterface $connection Connection for this language
     * @param LspCacheInterface      $cache      Cache for this language
     */
    public function addServer(string $language, LspConnectionInterface $connection, LspCacheInterface $cache): void
    {
        $this->connections[$language] = $connection;
        $this->caches[$language] = $cache;
        $this->subscribe($connection);
    }

    /**
     * Route files ending in any of $extensions to $language's server.
     *
     * @param list<string> $extensions with or without the leading dot
     */
    public function mapExtensions(string $language, array $extensions): void
    {
        foreach ($extensions as $extension) {
            $extension = strtolower(ltrim(trim($extension), '.'));
            if ($extension !== '') {
                $this->extensions[$extension] = $language;
            }
        }
    }

    /**
     * The registered language whose server owns $path, or null when none does.
     *
     * The extension map first; with nothing mapped for the extension, a server
     * registered under the extension's own name (`php` for `x.php`) — the shape
     * a client built by hand, with no map, has always meant.
     */
    public function languageFor(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        $language = $this->extensions[$extension] ?? $extension;

        return isset($this->connections[$language]) ? $language : null;
    }

    /**
     * Speak the shutdown protocol to every server and forget them. In a process
     * that did not start a server this only closes its own copies of the pipes
     * — {@see LspConnection::disconnect()} keeps the shared server running for
     * the process that owns it.
     */
    public function disconnectAll(): void
    {
        foreach ($this->connections as $connection) {
            try {
                $connection->disconnect();
            } catch (\Throwable) {
                // One server that will not stop must not keep the rest running.
            }
        }
    }

    /**
     * Subscribe to a server's notifications: `textDocument/publishDiagnostics`
     * lands in {@see handlePublishDiagnostics()}, which is what makes
     * {@see diagnostics()} and {@see freshDiagnostics()} mean anything. Before
     * step 3.F nothing in `src/` subscribed, so the map stayed empty forever.
     */
    private function subscribe(LspConnectionInterface $connection): void
    {
        $connection->onNotification(function (string $method, ?array $params): void {
            if ($method !== 'textDocument/publishDiagnostics' || !\is_string($params['uri'] ?? null)) {
                return;
            }

            $uri = $params['uri'];

            // A document this client touched and has closed again: what the
            // server says about it now is the empty list most servers publish
            // on `didClose`, not a verdict on the file (see $openDocuments).
            if (isset($this->versions[$uri]) && !isset($this->openDocuments[$uri])) {
                return;
            }

            // A publish stamped with an older version answers an earlier touch.
            $version = \is_int($params['version'] ?? null) ? $params['version'] : null;
            if ($version !== null && $version < ($this->versions[$uri] ?? 0)) {
                return;
            }

            $diagnostics = \is_array($params['diagnostics'] ?? null) ? array_values($params['diagnostics']) : [];
            $this->handlePublishDiagnostics($uri, $diagnostics);
        });
    }

    /**
     * Switch the active language server context.
     *
     * @param string $language Language identifier
     * @throws \InvalidArgumentException If no server registered for $language
     */
    public function use(string $language): self
    {
        if (!isset($this->connections[$language])) {
            throw new \InvalidArgumentException("No server registered for language: {$language}");
        }
        $client = clone $this;
        $client->language = $language;
        return $client;
    }

    /**
     * @return string|null Current language identifier
     */
    public function language(): ?string
    {
        return $this->language;
    }

    /**
     * All registered language identifiers.
     *
     * @return array<int, string>
     */
    public function servers(): array
    {
        return array_keys($this->connections);
    }

    /**
     * E690: forward the between-exchanges stderr drain to every registered
     * connection — the dispatch-entry mirror of
     * {@see \SugarCraft\Crush\MCP\McpClient::pumpStderr()}.
     *
     * The `instanceof` gate is that precedent carried over verbatim: only a
     * spawned child owns an fd 2, so the interface must NOT grow
     * `pumpStderr()` and the in-memory fakes that implement it stay inert
     * here. Cheap and non-blocking by contract (see
     * {@see LspConnection::pumpStderr()}), and mounted at DISPATCH ENTRY
     * inside the `…For()` operations only — never from a timer or the loop,
     * the hazard the connection's own docblock records (E537). A language
     * whose server never connected, or already disconnected, is a no-op for
     * the same reason, which is what makes the blanket fan-out safe to call
     * unconditionally on entry.
     */
    public function pumpStderr(): void
    {
        foreach ($this->connections as $connection) {
            if ($connection instanceof LspConnection) {
                $connection->pumpStderr();
            }
        }
    }

    /**
     * Whether a server is registered and connected for the given language.
     */
    public function isConnected(?string $language = null): bool
    {
        $language ??= $this->language;
        $conn = $this->connections[$language] ?? null;
        return $conn !== null && $conn->isConnected();
    }

    // -------------------------------------------------------------------------
    // Definitions (go-to-definition) — cached per uri+method+POSITION
    // -------------------------------------------------------------------------

    /**
     * textDocument/definition — go-to-definition.
     *
     * Caches result per URI. Falls back to grep when LSP is unavailable.
     *
     * @param string $uri  File URI
     * @param int    $line  Cursor line (0-indexed)
     * @param int    $col   Cursor column (0-indexed)
     * @return array<mixed> Locations (LSP Location[])
     *
     * ⚠️ THE BODY IS A FORWARD, AND IT USED TO BE A COPY. {@see definitionsFor()}
     * carried a character-identical tail, and the cost was measured rather than
     * aesthetic: a mutation anchored on that tail landed in the wrong twin and
     * SURVIVED, which read as a hole in a test that was in fact sound. There is
     * now one body, so a mutation of it cannot be misattributed.
     */
    public function definitions(string $uri, int $line = 0, int $col = 0): array
    {
        return $this->definitionsFor($this->language, $uri, $line, $col);
    }

    /**
     * Definitions using a specific language server.
     *
     * @throws \InvalidArgumentException If no server for $language
     */
    public function definitionsFor(string $language, string $uri, int $line = 0, int $col = 0): array
    {
        $conn = $this->connections[$language] ?? null;
        if ($conn === null) {
            throw new \InvalidArgumentException("No server registered for language: {$language}");
        }

        $cache = $this->caches[$language];
        // E690: dispatch-entry drain (mirror of McpClient's callTool/
        // listTools/listResources pumps). Runs for cache hits too — a
        // between-turns visit is exactly the settled gap LspConnection's
        // contract names, and the fan-out is non-blocking.
        $this->pumpStderr();
        $key = self::positionalKey('textDocument/definition', $line, $col);

        if ($cache->has($uri, $key)) {
            return $cache->get($uri, $key) ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->definitions($uri, $line, $col);
            $cache->set($uri, $key, $result);
            return $result;
        }

        $result = $this->fallbackGrep($uri, $line, 'definition');
        $cache->set($uri, $key, $result);
        return $result;
    }

    // -------------------------------------------------------------------------
    // References — cached per uri+method+POSITION
    // -------------------------------------------------------------------------

    /**
     * textDocument/references — find all references.
     *
     * @return array<mixed> Locations (LSP Location[])
     *
     * ⚠️ THE BODY IS A FORWARD, AND IT USED TO BE A COPY. {@see referencesFor()}
     * carried a character-identical tail, and the cost was measured rather than
     * aesthetic: a mutation anchored on that tail landed in the wrong twin and
     * SURVIVED, which read as a hole in a test that was in fact sound. There is
     * now one body, so a mutation of it cannot be misattributed.
     */
    public function references(string $uri, int $line = 0, int $col = 0): array
    {
        return $this->referencesFor($this->language, $uri, $line, $col);
    }

    /**
     * References using a specific language server.
     */
    public function referencesFor(string $language, string $uri, int $line = 0, int $col = 0): array
    {
        $conn = $this->connections[$language] ?? null;
        if ($conn === null) {
            throw new \InvalidArgumentException("No server registered for language: {$language}");
        }

        $cache = $this->caches[$language];
        // E690: dispatch-entry drain (mirror of McpClient's callTool/
        // listTools/listResources pumps). Runs for cache hits too — a
        // between-turns visit is exactly the settled gap LspConnection's
        // contract names, and the fan-out is non-blocking.
        $this->pumpStderr();
        $key = self::positionalKey('textDocument/references', $line, $col);

        if ($cache->has($uri, $key)) {
            return $cache->get($uri, $key) ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->references($uri, $line, $col);
            $cache->set($uri, $key, $result);
            return $result;
        }

        $result = $this->fallbackGrep($uri, $line, 'references');
        $cache->set($uri, $key, $result);
        return $result;
    }

    // -------------------------------------------------------------------------
    // Hover — cached per uri+method+POSITION
    // -------------------------------------------------------------------------

    /**
     * textDocument/hover — hover information.
     *
     * @return array|null Hover result or null if not available
     *
     * ⚠️ THE BODY IS A FORWARD, AND IT USED TO BE A COPY. {@see hoverFor()}
     * carried a character-identical tail, and the cost was measured rather than
     * aesthetic: a mutation anchored on that tail landed in the wrong twin and
     * SURVIVED, which read as a hole in a test that was in fact sound. There is
     * now one body, so a mutation of it cannot be misattributed.
     */
    public function hover(string $uri, int $line = 0, int $col = 0): ?array
    {
        return $this->hoverFor($this->language, $uri, $line, $col);
    }

    /**
     * Hover using a specific language server.
     */
    public function hoverFor(string $language, string $uri, int $line = 0, int $col = 0): ?array
    {
        $conn = $this->connections[$language] ?? null;
        if ($conn === null) {
            throw new \InvalidArgumentException("No server registered for language: {$language}");
        }

        $cache = $this->caches[$language];
        // E690: dispatch-entry drain (mirror of McpClient's callTool/
        // listTools/listResources pumps). Runs for cache hits too — a
        // between-turns visit is exactly the settled gap LspConnection's
        // contract names, and the fan-out is non-blocking.
        $this->pumpStderr();
        $key = self::positionalKey('textDocument/hover', $line, $col);

        if ($cache->has($uri, $key)) {
            return $cache->get($uri, $key);
        }

        if ($conn->isConnected()) {
            $result = $conn->hover($uri, $line, $col);
            // Cache null results too so we don't re-query.
            $cache->set($uri, $key, $result);
            return $result;
        }

        // No fallback for hover — grep cannot synthesise a type signature.
        $cache->set($uri, $key, null);
        return null;
    }

    // -------------------------------------------------------------------------
    // Symbols — cached per uri+method (NOT per position: documentSymbol takes none)
    // -------------------------------------------------------------------------

    /**
     * textDocument/documentSymbol — list symbols in a document.
     *
     * @return array<mixed> DocumentSymbol[] or SymbolInformation[]
     *
     * ⚠️ THE BODY IS A FORWARD, AND IT USED TO BE A COPY. {@see symbolsFor()}
     * carried a character-identical tail, and the cost was measured rather than
     * aesthetic: a mutation anchored on that tail landed in the wrong twin and
     * SURVIVED, which read as a hole in a test that was in fact sound. There is
     * now one body, so a mutation of it cannot be misattributed.
     */
    public function symbols(string $uri): array
    {
        return $this->symbolsFor($this->language, $uri);
    }

    /**
     * Symbols using a specific language server.
     */
    public function symbolsFor(string $language, string $uri): array
    {
        $conn = $this->connections[$language] ?? null;
        if ($conn === null) {
            throw new \InvalidArgumentException("No server registered for language: {$language}");
        }

        $cache = $this->caches[$language];
        // E690: dispatch-entry drain (mirror of McpClient's callTool/
        // listTools/listResources pumps). Runs for cache hits too — a
        // between-turns visit is exactly the settled gap LspConnection's
        // contract names, and the fan-out is non-blocking.
        $this->pumpStderr();

        if ($cache->has($uri, 'textDocument/documentSymbol')) {
            return $cache->get($uri, 'textDocument/documentSymbol') ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->symbols($uri);
            $cache->set($uri, 'textDocument/documentSymbol', $result);
            return $result;
        }

        $result = $this->fallbackGrep($uri, 0, 'symbols');
        $cache->set($uri, 'textDocument/documentSymbol', $result);
        return $result;
    }

    // -------------------------------------------------------------------------
    // Code Actions — cached per uri+method+POSITION+context
    // -------------------------------------------------------------------------

    /**
     * textDocument/codeAction — get code actions (quick fixes, refactorings, etc.).
     *
     * Caches result per URI. Falls back to empty array when LSP is unavailable.
     *
     * @param string $uri     File URI
     * @param int    $line    Cursor line (0-indexed)
     * @param int    $col     Cursor column (0-indexed)
     * @param array  $context Context containing diagnostics; empty array uses server defaults
     * @return array<mixed> CodeAction[] — never null, may be empty
     *
     * ⚠️ THE BODY IS A FORWARD, AND IT USED TO BE A COPY. {@see codeActionsFor()}
     * carried a character-identical tail, and the cost was measured rather than
     * aesthetic: a mutation anchored on that tail landed in the wrong twin and
     * SURVIVED, which read as a hole in a test that was in fact sound. There is
     * now one body, so a mutation of it cannot be misattributed.
     */
    public function codeActions(string $uri, int $line = 0, int $col = 0, array $context = []): array
    {
        return $this->codeActionsFor($this->language, $uri, $line, $col, $context);
    }

    /**
     * Code actions using a specific language server.
     *
     * @throws \InvalidArgumentException If no server for $language
     */
    public function codeActionsFor(string $language, string $uri, int $line = 0, int $col = 0, array $context = []): array
    {
        $conn = $this->connections[$language] ?? null;
        if ($conn === null) {
            throw new \InvalidArgumentException("No server registered for language: {$language}");
        }

        $cache = $this->caches[$language];
        // E690: dispatch-entry drain (mirror of McpClient's callTool/
        // listTools/listResources pumps). Runs for cache hits too — a
        // between-turns visit is exactly the settled gap LspConnection's
        // contract names, and the fan-out is non-blocking.
        $this->pumpStderr();
        $key = self::positionalKey('textDocument/codeAction', $line, $col, $context);

        if ($cache->has($uri, $key)) {
            return $cache->get($uri, $key) ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->codeActions($uri, $line, $col, $context);
            $cache->set($uri, $key, $result);
            return $result;
        }

        // No meaningful fallback for code actions — return empty.
        $cache->set($uri, $key, []);
        return [];
    }

    // -------------------------------------------------------------------------
    // Diagnostics — collected from publishDiagnostics notifications
    // -------------------------------------------------------------------------

    /**
     * Return cached diagnostics for a URI.
     *
     * Diagnostics are collected via handlePublishDiagnostics() which is called
     * when the server sends a textDocument/publishDiagnostics notification.
     * Unlike the deprecated LspConnection::diagnostics() stub, this returns
     * the actual diagnostics received from the server.
     *
     * @param string $uri File URI
     * @return array<mixed> Diagnostics (LSP Diagnostic[])
     */
    public function diagnostics(string $uri): array
    {
        return $this->diagnostics[$uri] ?? [];
    }

    /**
     * Handle an incoming publishDiagnostics notification from a server.
     *
     * This is called by application code that reads the LSP notification stream.
     *
     * @param string $uri         File URI
     * @param array<int, array<string, mixed>> $diagnostics LSP Diagnostic[]
     */
    public function handlePublishDiagnostics(string $uri, array $diagnostics): void
    {
        $this->diagnostics[$uri] = $diagnostics;
    }

    /**
     * Whether ANY diagnostics list — possibly an empty one — has been delivered
     * for $uri. The difference between "the server said this file is clean"
     * and "the server never said anything" is exactly this bit.
     */
    public function hasDiagnostics(string $uri): bool
    {
        return \array_key_exists($uri, $this->diagnostics);
    }

    /**
     * Have $language's server re-check the file at $path as it is on disk now,
     * and wait up to $waitSeconds for its verdict (step 3.F; opencode waits
     * 5 s for a document's diagnostics, `DIAGNOSTICS_DOCUMENT_WAIT_TIMEOUT_MS`).
     *
     * THE DOCUMENT IS OPENED, ASKED ABOUT AND CLOSED IN ONE CALL. Keeping
     * documents open would make the server read them from what we last sent
     * rather than from disk, and every process sharing the server — turns and
     * tool calls are forked — would need one view of which documents are open.
     * Open-ask-close needs neither: the server sees exactly the bytes on disk.
     *
     * HOW THE VERDICT ARRIVES. A server that implements LSP 3.17 pull
     * diagnostics answers `textDocument/diagnostic` with the list itself. One
     * that does not answers it with an error — and reading that answer is
     * still worth the round trip, because the connection dispatches every
     * notification queued ahead of a response while it reads, which is the
     * only way a pushed `publishDiagnostics` gets in. So the loop asks, then
     * looks for a delivered list, then sleeps {@see DIAGNOSTICS_POLL_MICROS}.
     *
     * Null when the server cannot be asked at all: none registered for
     * $language, not connected, a connection with no notification channel (an
     * in-memory fake), or a file that cannot be read. Otherwise `delivered`
     * says whether a list arrived before the deadline — false is "the server
     * did not say", never "the file is clean".
     *
     * `sendNotification()`/`sendRequest()` are reached by name, because
     * {@see LspConnectionInterface} does not declare them; the one real
     * implementation, {@see LspConnection}, has both.
     *
     * @return array{delivered: bool, diagnostics: list<array<string, mixed>>}|null
     */
    public function freshDiagnostics(string $language, string $path, float $waitSeconds = 5.0): ?array
    {
        $connection = $this->connections[$language] ?? null;
        if (!$this->canTouch($connection)) {
            return null;
        }
        \assert($connection !== null);

        $text = @file_get_contents($path);
        if (!\is_string($text)) {
            return null;
        }

        $this->pumpStderr();
        $uri = self::uriFor($path);
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $this->open($connection, $language, $uri, $text);

        try {
            while (!$this->hasDiagnostics($uri)) {
                /** @var LspResponse $pulled */
                $pulled = $connection->sendRequest('textDocument/diagnostic', ['textDocument' => ['uri' => $uri]]);
                if (!$pulled->isError && \is_array($pulled->result) && \is_array($pulled->result['items'] ?? null)) {
                    $this->handlePublishDiagnostics($uri, array_values($pulled->result['items']));
                    break;
                }

                $left = $deadline - microtime(true);
                if ($this->hasDiagnostics($uri) || $left <= 0.0 || !$connection->isConnected()) {
                    break;
                }
                usleep((int) min(self::DIAGNOSTICS_POLL_MICROS, $left * 1_000_000));
            }
        } finally {
            $this->close($connection, $uri);
        }

        return [
            'delivered' => $this->hasDiagnostics($uri),
            'diagnostics' => $this->diagnostics[$uri] ?? [],
        ];
    }

    /**
     * The symbols declared in the file at $path as a flat, line-ordered outline
     * (`line` 1-based, `depth` 0 for top level), for the Read tool's outline of
     * a large file (step 3.F, Zed's `read_file` outline).
     *
     * From the file's language server when one is registered and connected —
     * opened for the request and closed again, as {@see freshDiagnostics()}
     * does — and otherwise from {@see declarationsIn()}'s one-line regex over
     * the source, so a launch with no server still gets an outline of the
     * declarations it can see.
     *
     * @return list<array{line: int, depth: int, kind: string, name: string}>
     */
    public function outline(string $path): array
    {
        $language = $this->languageFor($path);
        $connection = $language === null ? null : $this->connections[$language];
        if ($language === null || !$this->canTouch($connection)) {
            return self::regexOutline($path);
        }
        \assert($connection !== null);

        $text = @file_get_contents($path);
        if (!\is_string($text)) {
            return [];
        }

        $uri = self::uriFor($path);
        $this->open($connection, $language, $uri, $text);
        try {
            $symbols = $connection->symbols($uri);
        } finally {
            $this->close($connection, $uri);
        }

        return $symbols !== [] ? self::outlineOf($symbols) : self::regexOutline($path);
    }

    /**
     * {@see outline()} with no server to ask: the declarations
     * {@see declarationsIn()} finds, as outline rows.
     *
     * @return list<array{line: int, depth: int, kind: string, name: string}>
     */
    public static function regexOutline(string $path): array
    {
        return self::outlineOf(self::declarationsIn($path));
    }

    /**
     * Every declaration the one-line regex finds in the file at $path, as
     * `SymbolInformation[]` — the degraded outline {@see outline()} falls back
     * to. Empty for a file that cannot be read.
     *
     * @return list<array<string, mixed>>
     */
    public static function declarationsIn(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        return \is_array($lines) ? self::grepDeclarations(self::uriFor($path), $lines) : [];
    }

    /**
     * The `file://` URI for an absolute local path, percent-encoded per segment
     * — the encoding {@see uriToPath()} decodes, so the two round-trip; see
     * {@see \SugarCraft\Crush\Tools\BuiltIn\LspTool} for why the encoding is
     * not optional.
     */
    public static function uriFor(string $path): string
    {
        return 'file://' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * LSP `SymbolKind` numbers → the words an outline prints. The kinds a
     * source outline shows; anything else prints as `symbol`.
     */
    private const SYMBOL_KIND_WORDS = [
        2 => 'module',
        3 => 'namespace',
        5 => 'class',
        6 => 'method',
        7 => 'property',
        8 => 'field',
        9 => 'constructor',
        10 => 'enum',
        11 => 'interface',
        12 => 'function',
        13 => 'variable',
        14 => 'constant',
        22 => 'case',
        23 => 'struct',
    ];

    /**
     * Flatten `DocumentSymbol[]` (nested through `children`) or
     * `SymbolInformation[]` (flat, positioned by `location`) into outline rows,
     * ordered by line.
     *
     * @param array<mixed> $symbols
     * @return list<array{line: int, depth: int, kind: string, name: string}>
     */
    private static function outlineOf(array $symbols, int $depth = 0): array
    {
        $rows = [];
        foreach ($symbols as $symbol) {
            if (!\is_array($symbol) || !\is_string($symbol['name'] ?? null)) {
                continue;
            }
            $range = $symbol['selectionRange'] ?? $symbol['range'] ?? $symbol['location']['range'] ?? null;
            $line = \is_array($range) && \is_int($range['start']['line'] ?? null) ? $range['start']['line'] : 0;
            $kind = \is_int($symbol['kind'] ?? null) ? $symbol['kind'] : 0;
            $rows[] = [
                'line' => $line + 1,
                'depth' => $depth,
                'kind' => self::SYMBOL_KIND_WORDS[$kind] ?? 'symbol',
                'name' => $symbol['name'],
            ];
            if (\is_array($symbol['children'] ?? null) && $depth < 4) {
                array_push($rows, ...self::outlineOf($symbol['children'], $depth + 1));
            }
        }

        if ($depth === 0) {
            usort($rows, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        }

        return $rows;
    }

    /** Whether $connection is up and can be sent the notifications a touch needs. */
    private function canTouch(?LspConnectionInterface $connection): bool
    {
        return $connection !== null
            && $connection->isConnected()
            && method_exists($connection, 'sendNotification')
            && method_exists($connection, 'sendRequest');
    }

    /**
     * `didOpen` $text as the next version of $uri, after dropping everything
     * cached about it: whatever the server said before was about other bytes.
     */
    private function open(LspConnectionInterface $connection, string $language, string $uri, string $text): void
    {
        $this->clearFile($uri);
        $version = ($this->versions[$uri] ?? 0) + 1;
        $this->versions[$uri] = $version;
        $this->openDocuments[$uri] = true;

        $connection->sendNotification('textDocument/didOpen', [
            'textDocument' => ['uri' => $uri, 'languageId' => $language, 'version' => $version, 'text' => $text],
        ]);
    }

    private function close(LspConnectionInterface $connection, string $uri): void
    {
        unset($this->openDocuments[$uri]);
        $connection->sendNotification('textDocument/didClose', ['textDocument' => ['uri' => $uri]]);
    }

    // -------------------------------------------------------------------------
    // Cache management
    // -------------------------------------------------------------------------

    /**
     * Evict all cached entries for a file, on EVERY registered server.
     *
     * Call this when a file is saved so stale LSP responses are discarded.
     *
     * The sweep is over `$this->caches` rather than over the constructor's
     * `$this->cache`, and the difference is only visible once
     * {@see addServer()} has been called: those two are the same object for a
     * single-server client, so the narrower version was indistinguishable there
     * and left every ADDITIONAL language answering a saved file from the cache
     * it had before the save. `clearAll()` below already swept all of them; this
     * matches it. Each cache's own `clearFile()` is a prefix sweep over the uri,
     * so it evicts every {@see positionalKey()} of that file, not just position
     * 0:0.
     */
    public function clearFile(string $uri): void
    {
        foreach ($this->caches as $cache) {
            $cache->clearFile($uri);
        }
        // Also clear diagnostics for this file.
        unset($this->diagnostics[$uri]);
    }

    /**
     * Evict all cached entries across all servers.
     */
    public function clearAll(): void
    {
        foreach ($this->caches as $cache) {
            $cache->clear();
        }
        $this->diagnostics = [];
    }

    // -------------------------------------------------------------------------
    // Fallback: grep-based search when LSP is unavailable
    // -------------------------------------------------------------------------

    /**
     * The cache key for a POSITIONAL request.
     *
     * WHY THIS EXISTS, and the domain of the bug it closes. Every cached method
     * here originally keyed on `uri` + the LSP method name alone. That is correct
     * for `textDocument/documentSymbol` (and for `diagnostics`, which is not
     * cached here at all), because those ask about a whole FILE. It is wrong for
     * `definition` / `references` / `hover` / `codeAction`, which ask about a
     * CURSOR: with the file-shaped key, the second query on a file returned the
     * FIRST query's answer no matter where the caller had moved to, and it
     * returned it as a normal answer with no way for the caller to tell.
     * Measured before this key existed, one client, one connected server, the
     * same file, `references` at line 1 then at line 2: the connection was asked
     * exactly once and the two answers were byte-identical. A stale answer about
     * a different symbol is worse than a cache miss, and for
     * {@see \SugarCraft\Crush\Tools\BuiltIn\LspTool} it is the same
     * confident-lie shape that tool is built to avoid.
     *
     * $context is folded in for `codeAction` only because it is the only
     * positional request whose ANSWER also depends on an argument beyond the
     * position — the diagnostics the caller is asking for fixes to. It is hashed
     * rather than embedded so the key length does not grow with it; the hash is
     * a cache discriminator and nothing security-sensitive depends on it.
     *
     * The key stays `$uri`-prefixed via the cache's own `makeKey()`, so
     * {@see LspCache::clearFile()}'s prefix sweep still evicts every position of
     * a file in one call — that is why the position goes in the METHOD half of
     * the key rather than into the uri.
     *
     * @param array<mixed> $context `codeAction` context; empty for every other method
     */
    private static function positionalKey(string $method, int $line, int $col, array $context = []): string
    {
        $key = sprintf('%s@%d:%d', $method, $line, $col);

        if ($context !== []) {
            $key .= '#' . md5((string) json_encode($context));
        }

        return $key;
    }

    /**
     * Basic grep fallback for when no LSP server is available.
     *
     * TWO SHAPES, NOT ONE, and conflating them is what made `symbols` useless.
     * `definition`/`references` are CURSOR-shaped: they take the identifier under
     * `$line` and search the file for it. `symbols` is FILE-shaped —
     * `textDocument/documentSymbol` carries no position at all, which is why both
     * callers pass `$line = 0` — so it enumerates the file's declarations and
     * ignores the cursor entirely. It used to take the identifier from line 0
     * too, and line 0 of a PHP file is `<?php`: the extracted identifier was
     * `php`, no declaration of `php` existed, and EVERY file came back with no
     * symbols. Measured on this tree: a fixture declaring `function fbTarget()`
     * returned `[]` from `symbolsFor()` with a disconnected server. An empty
     * SUCCESS reading "this file declares nothing" is the same fabrication
     * {@see \SugarCraft\Crush\Tools\BuiltIn\LspTool} refuses elsewhere.
     *
     * @param string $uri    File URI being queried
     * @param int    $line   Cursor line (0-indexed); IGNORED for `symbols`
     * @param string $method One of: definition | references | symbols
     * @return array<mixed>
     */
    private function fallbackGrep(string $uri, int $line, string $method): array
    {
        $path = $this->uriToPath($uri);
        if ($path === null || !file_exists($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        if ($method === 'symbols') {
            return self::grepDeclarations($uri, $lines);
        }

        // From here on the request IS cursor-shaped, so a cursor off the end of
        // the file has no identifier to search for.
        if (!isset($lines[$line])) {
            return [];
        }

        $targetLine = $lines[$line];
        // Extract identifier under/near cursor.
        if (preg_match('/[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*/', $targetLine, $matches)) {
            $symbol = $matches[0];
        } else {
            return [];
        }

        $results = [];

        if ($method === 'definition' || $method === 'references') {
            // Grep for the symbol definition and references in the same file.
            $pattern = preg_quote($symbol, '/');
            foreach ($lines as $idx => $lineContent) {
                if (preg_match("/\b{$pattern}\b/", $lineContent)) {
                    $results[] = [
                        'uri' => $uri,
                        'range' => [
                            'start' => ['line' => $idx, 'character' => 0],
                            'end' => ['line' => $idx, 'character' => strlen($lineContent)],
                        ],
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Every declaration in a file, as `SymbolInformation[]` — the file-shaped
     * half of {@see fallbackGrep()}.
     *
     * The `kind` numbers are the LSP `SymbolKind` enum
     * (https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/#symbolKind),
     * and they are stated here because the code this replaced hard-coded `6`
     * with the comment "SymbolKind::Function = 6" — 6 is `Method`; `Function` is
     * 12. `trait` has no SymbolKind of its own, so it takes `Class` (5), which is
     * what the PHP language servers report for one.
     *
     * A one-line regex over source text, not a parser: it finds declarations
     * whose keyword and name are on the same line, which is the overwhelming
     * majority and all a degraded fallback promises. Modifiers are consumed so
     * `final class X` and `public static function y` are seen.
     *
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private static function grepDeclarations(string $uri, array $lines): array
    {
        static $kinds = [
            'class' => 5,
            'method' => 6,
            'enum' => 10,
            'interface' => 11,
            'function' => 12,
            'const' => 14,
            'trait' => 5,
        ];

        $results = [];

        foreach ($lines as $idx => $lineContent) {
            $matched = preg_match(
                '/^\s*(?:(?:final|abstract|readonly|public|protected|private|static)\s+)*'
                . '(function|class|interface|trait|const|enum)\s+'
                . '([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/',
                $lineContent,
                $matches,
            );

            if ($matched !== 1) {
                continue;
            }

            $results[] = [
                'name' => $matches[2],
                'kind' => $kinds[$matches[1]] ?? 12,
                'location' => [
                    'uri' => $uri,
                    'range' => [
                        'start' => ['line' => $idx, 'character' => 0],
                        'end' => ['line' => $idx, 'character' => strlen($lineContent)],
                    ],
                ],
            ];
        }

        return $results;
    }

    /**
     * Convert file:// URI to local filesystem path.
     *
     * `rawurldecode()`, NOT `urldecode()`. The difference is exactly one
     * character and it silently loses files: `urldecode()` implements
     * `application/x-www-form-urlencoded`, in which a literal `+` means a SPACE.
     * A `file://` URI is not a form body, and `+` is a perfectly legal character
     * in a POSIX filename. So `file:///src/Web+Fetch.php` decoded to
     * `/src/Web Fetch.php`, which does not exist — and {@see fallbackGrep()}'s
     * `file_exists()` then returned `[]`, i.e. "no references", for a file that
     * was never opened. Measured on this tree before the change: a two-hit
     * `references` query on `sub/Web+Fetch.php` came back as a SUCCESS reading
     * "No references found", while the identical query on `sub/Target.php`
     * returned both hits.
     *
     * The producing half is
     * {@see \SugarCraft\Crush\Tools\BuiltIn\LspTool::fileUri()}, which
     * percent-encodes each segment, so the pair round-trips exactly. This
     * decoder is deliberately the tolerant end of that pair: it also accepts a
     * URI from anywhere else that left `+` unencoded, which the old one could
     * not.
     *
     * @param string $uri
     * @return string|null
     */
    private function uriToPath(string $uri): ?string
    {
        if (str_starts_with($uri, 'file://')) {
            return rawurldecode(substr($uri, 7));
        }
        return null;
    }
}

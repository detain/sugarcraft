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

    private ?string $language = null;

    public function __construct(
        private readonly LspConnectionInterface $connection,
        private readonly LspCacheInterface $cache,
    ) {
        // Default server is the injected one.
        $this->language = 'php';
        $this->connections[$this->language] = $connection;
        $this->caches[$this->language] = $cache;
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
     */
    public function definitions(string $uri, int $line = 0, int $col = 0): array
    {
        $conn = $this->connections[$this->language];
        $cache = $this->caches[$this->language];
        $key = self::positionalKey('textDocument/definition', $line, $col);

        // Try cache first.
        if ($cache->has($uri, $key)) {
            return $cache->get($uri, $key) ?? [];
        }

        // Try LSP.
        if ($conn->isConnected()) {
            $result = $conn->definitions($uri, $line, $col);
            $cache->set($uri, $key, $result);
            return $result;
        }

        // Fallback: grep.
        $result = $this->fallbackGrep($uri, $line, 'definition');
        $cache->set($uri, $key, $result);
        return $result;
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
     */
    public function references(string $uri, int $line = 0, int $col = 0): array
    {
        $conn = $this->connections[$this->language];
        $cache = $this->caches[$this->language];
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
     */
    public function hover(string $uri, int $line = 0, int $col = 0): ?array
    {
        $conn = $this->connections[$this->language];
        $cache = $this->caches[$this->language];
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

        // No fallback for hover.
        $cache->set($uri, $key, null);
        return null;
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
        $key = self::positionalKey('textDocument/hover', $line, $col);

        if ($cache->has($uri, $key)) {
            return $cache->get($uri, $key);
        }

        if ($conn->isConnected()) {
            $result = $conn->hover($uri, $line, $col);
            $cache->set($uri, $key, $result);
            return $result;
        }

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
     */
    public function symbols(string $uri): array
    {
        $conn = $this->connections[$this->language];
        $cache = $this->caches[$this->language];

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
     */
    public function codeActions(string $uri, int $line = 0, int $col = 0, array $context = []): array
    {
        $conn = $this->connections[$this->language];
        $cache = $this->caches[$this->language];
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
        $key = self::positionalKey('textDocument/codeAction', $line, $col, $context);

        if ($cache->has($uri, $key)) {
            return $cache->get($uri, $key) ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->codeActions($uri, $line, $col, $context);
            $cache->set($uri, $key, $result);
            return $result;
        }

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
            return $this->grepDeclarations($uri, $lines);
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
    private function grepDeclarations(string $uri, array $lines): array
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

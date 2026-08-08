<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * LSP Client that wraps LspConnection with caching, diagnostics tracking,
 * multi-language server support, and graceful fallback to grep when LSP
 * is unavailable.
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
    // Definitions (go-to-definition) — cached per uri+method
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

        // Try cache first.
        if ($cache->has($uri, 'textDocument/definition')) {
            return $cache->get($uri, 'textDocument/definition') ?? [];
        }

        // Try LSP.
        if ($conn->isConnected()) {
            $result = $conn->definitions($uri, $line, $col);
            $cache->set($uri, 'textDocument/definition', $result);
            return $result;
        }

        // Fallback: grep.
        $result = $this->fallbackGrep($uri, $line, 'definition');
        $cache->set($uri, 'textDocument/definition', $result);
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

        if ($cache->has($uri, 'textDocument/definition')) {
            return $cache->get($uri, 'textDocument/definition') ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->definitions($uri, $line, $col);
            $cache->set($uri, 'textDocument/definition', $result);
            return $result;
        }

        $result = $this->fallbackGrep($uri, $line, 'definition');
        $cache->set($uri, 'textDocument/definition', $result);
        return $result;
    }

    // -------------------------------------------------------------------------
    // References — cached per uri+method
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

        if ($cache->has($uri, 'textDocument/references')) {
            return $cache->get($uri, 'textDocument/references') ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->references($uri, $line, $col);
            $cache->set($uri, 'textDocument/references', $result);
            return $result;
        }

        $result = $this->fallbackGrep($uri, $line, 'references');
        $cache->set($uri, 'textDocument/references', $result);
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

        if ($cache->has($uri, 'textDocument/references')) {
            return $cache->get($uri, 'textDocument/references') ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->references($uri, $line, $col);
            $cache->set($uri, 'textDocument/references', $result);
            return $result;
        }

        $result = $this->fallbackGrep($uri, $line, 'references');
        $cache->set($uri, 'textDocument/references', $result);
        return $result;
    }

    // -------------------------------------------------------------------------
    // Hover — cached per uri+method
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

        if ($cache->has($uri, 'textDocument/hover')) {
            return $cache->get($uri, 'textDocument/hover');
        }

        if ($conn->isConnected()) {
            $result = $conn->hover($uri, $line, $col);
            // Cache null results too so we don't re-query.
            $cache->set($uri, 'textDocument/hover', $result);
            return $result;
        }

        // No fallback for hover.
        $cache->set($uri, 'textDocument/hover', null);
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

        if ($cache->has($uri, 'textDocument/hover')) {
            return $cache->get($uri, 'textDocument/hover');
        }

        if ($conn->isConnected()) {
            $result = $conn->hover($uri, $line, $col);
            $cache->set($uri, 'textDocument/hover', $result);
            return $result;
        }

        $cache->set($uri, 'textDocument/hover', null);
        return null;
    }

    // -------------------------------------------------------------------------
    // Symbols — cached per uri+method
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
    // Code Actions — cached per uri+method
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

        if ($cache->has($uri, 'textDocument/codeAction')) {
            return $cache->get($uri, 'textDocument/codeAction') ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->codeActions($uri, $line, $col, $context);
            $cache->set($uri, 'textDocument/codeAction', $result);
            return $result;
        }

        // No meaningful fallback for code actions — return empty.
        $cache->set($uri, 'textDocument/codeAction', []);
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

        if ($cache->has($uri, 'textDocument/codeAction')) {
            return $cache->get($uri, 'textDocument/codeAction') ?? [];
        }

        if ($conn->isConnected()) {
            $result = $conn->codeActions($uri, $line, $col, $context);
            $cache->set($uri, 'textDocument/codeAction', $result);
            return $result;
        }

        $cache->set($uri, 'textDocument/codeAction', []);
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
     * Evict all cached entries for a file.
     *
     * Call this when a file is saved so stale LSP responses are discarded.
     */
    public function clearFile(string $uri): void
    {
        $this->cache->clearFile($uri);
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
     * Basic grep fallback for when no LSP server is available.
     *
     * @param string $uri    File URI being queried
     * @param int    $line   Cursor line (0-indexed) — used to extract identifier
     * @param string $method One of: definition | references | symbols
     * @return array<mixed>
     */
    private function fallbackGrep(string $uri, int $line, string $method): array
    {
        $path = $this->uriToPath($uri);
        if ($path === null || !file_exists($path)) {
            return [];
        }

        // Read the line to extract the symbol to search for.
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false || !isset($lines[$line])) {
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
        } elseif ($method === 'symbols') {
            // Grep for function/class/const definitions.
            $pattern = preg_quote($symbol, '/');
            foreach ($lines as $idx => $lineContent) {
                if (preg_match("/^\s*(function|class|interface|trait|const|enum)\s+{$pattern}/m", $lineContent)) {
                    $results[] = [
                        'name' => $symbol,
                        'kind' => 6, // LSP SymbolKind::Function = 6
                        'location' => [
                            'uri' => $uri,
                            'range' => [
                                'start' => ['line' => $idx, 'character' => 0],
                                'end' => ['line' => $idx, 'character' => strlen($lineContent)],
                            ],
                        ],
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Convert file:// URI to local filesystem path.
     *
     * @param string $uri
     * @return string|null
     */
    private function uriToPath(string $uri): ?string
    {
        if (str_starts_with($uri, 'file://')) {
            return urldecode(substr($uri, 7));
        }
        return null;
    }
}

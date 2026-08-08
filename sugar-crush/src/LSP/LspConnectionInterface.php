<?php

declare(strict_types=1);

namespace SugarCraft\Crush\LSP;

/**
 * Contract for LSP transport connections.
 *
 * Abstracts stdio/TCP LSP communication so LspClient remains testable
 * without spawning real server processes.
 */
interface LspConnectionInterface
{
    /**
     * Start the LSP server process.
     *
     * @param string $command The server executable path
     * @param array<string, string> $env Environment variables to pass to the server
     * @param string|null $cwd Working directory for the subprocess (null = inherited)
     * @param float $timeout Request timeout in seconds
     */
    public function connect(string $command, array $env, ?string $cwd = null, float $timeout = 30.0): void;

    /**
     * Complete the LSP initialization handshake and return server capabilities.
     *
     * @return array Server capabilities from the initialize response result.
     */
    public function initialize(): array;

    /**
     * Send shutdown, wait for exit, then terminate the process.
     */
    public function disconnect(): void;

    /**
     * textDocument/definition — go-to-definition.
     *
     * @return array<mixed> Location[]
     */
    public function definitions(string $uri, int $line, int $col): array;

    /**
     * textDocument/references — find all references.
     *
     * @return array<mixed> Location[]
     */
    public function references(string $uri, int $line, int $col): array;

    /**
     * textDocument/hover — hover information.
     *
     * @return array|null Hover result or null if not available
     */
    public function hover(string $uri, int $line, int $col): ?array;

    /**
     * textDocument/documentSymbol — list symbols in a document.
     *
     * @return array<mixed> DocumentSymbol[] or SymbolInformation[]
     */
    public function symbols(string $uri): array;

    /**
     * textDocument/codeAction — get code actions (quick fixes, refactorings, etc.).
     *
     * @return array<mixed> CodeAction[]
     */
    public function codeActions(string $uri, int $line, int $col, array $context = []): array;

    /**
     * Whether the connection is currently active.
     */
    public function isConnected(): bool;

    /**
     * Get cached server capabilities.
     *
     * @return array|null Server capabilities or null if not yet initialized
     */
    public function capabilities(): ?array;

    /**
     * Register a callback for server-initiated notifications.
     *
     * @param callable $callback Called with (method: string, params: array|null) when server sends a notification
     */
    public function onNotification(callable $callback): void;
}

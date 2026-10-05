<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Acp\Support;

use SugarCraft\Crush\Acp\AcpServer;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Host\WorkspaceContext;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tests\Server\Support\ScriptedTurnBackend;

/**
 * An {@see AcpServer} over a scratch project root and session store, its turns
 * run by a {@see ScriptedTurnBackend}, every line it writes kept and decoded —
 * the editor's side of the pipe, played by a test.
 */
final class AcpHarness
{
    /** The project root sessions open in. */
    public readonly string $root;

    /** The scratch directory holding the root and, beside it, the store. */
    private readonly string $base;

    public readonly EnhancedSessionStore $store;

    public readonly ScriptedTurnBackend $backend;

    public readonly AcpServer $server;

    /** @var list<string> every line the server wrote */
    public array $lines = [];

    private int $nextId = 0;

    public function __construct()
    {
        // The store sits BESIDE the project, as ~/.sugar-crush does: one inside
        // it would put the checkpoint store in the tree it snapshots.
        $this->base = (string) realpath(sys_get_temp_dir()) . '/crush-acp-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/project';
        mkdir($this->root, 0700, true);
        $this->store = new EnhancedSessionStore($this->base . '/session.db');
        $this->backend = new ScriptedTurnBackend();
        $store = $this->store;
        $backend = $this->backend;
        $this->server = AcpServer::new(
            static fn (string $cwd): SessionHub => SessionHub::new(WorkspaceContext::new(root: $cwd, sessionStore: $store, backend: $backend)),
            function (string $line): void {
                $this->lines[] = $line;
            },
            '9.9.9-test',
        );
    }

    /** Remove the scratch root. */
    public function dispose(): void
    {
        $this->server->stop();
        $walk = static function (string $dir) use (&$walk): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                is_dir($path) && !is_link($path) ? $walk($path) : @unlink($path);
            }
            @rmdir($dir);
        };
        $walk($this->base);
    }

    /**
     * Send a request with a fresh integer id (or $id); returns the id.
     *
     * @param array<string, mixed> $params
     */
    public function request(string $method, array $params = [], int|string|null $id = null): int|string
    {
        $id ??= ++$this->nextId;
        $this->server->receive((string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]));

        return $id;
    }

    /** @param array<string, mixed> $params */
    public function notify(string $method, array $params = []): void
    {
        $this->server->receive((string) json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params]));
    }

    /** Answer the agent's request $id with $result. */
    public function respond(int|string $id, mixed $result): void
    {
        $this->server->receive((string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]));
    }

    /** `initialize` then `session/new` in the root; returns the session id. */
    public function open(): string
    {
        $this->request('initialize', ['protocolVersion' => 1, 'clientCapabilities' => (object) []]);
        $id = $this->request('session/new', ['cwd' => $this->root, 'mcpServers' => []]);

        return (string) ($this->response($id)['result']['sessionId'] ?? '');
    }

    /**
     * Every message written, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(): array
    {
        return array_map(static fn (string $line): array => (array) json_decode($line, true, 512, \JSON_THROW_ON_ERROR), $this->lines);
    }

    /**
     * The response to request $id, or null while it has none.
     *
     * @return array<string, mixed>|null
     */
    public function response(int|string $id): ?array
    {
        foreach ($this->messages() as $message) {
            if (\array_key_exists('id', $message) && $message['id'] === $id && !isset($message['method'])) {
                return $message;
            }
        }

        return null;
    }

    /**
     * The `update` objects of every `session/update` written, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function updates(): array
    {
        $updates = [];
        foreach ($this->messages() as $message) {
            if (($message['method'] ?? null) === 'session/update') {
                $updates[] = $message['params']['update'];
            }
        }

        return $updates;
    }

    /**
     * The agent→client requests of $method written, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function requests(string $method): array
    {
        return array_values(array_filter(
            $this->messages(),
            static fn (array $message): bool => ($message['method'] ?? null) === $method && \array_key_exists('id', $message),
        ));
    }

    /** The concatenated text of every chunk of $kind. */
    public function chunks(string $kind = 'agent_message_chunk'): string
    {
        $text = '';
        foreach ($this->updates() as $update) {
            if ($update['sessionUpdate'] === $kind) {
                $text .= $update['content']['text'];
            }
        }

        return $text;
    }
}

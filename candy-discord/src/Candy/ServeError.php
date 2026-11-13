<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Candy;

use SugarCraft\Code\Lang;

/**
 * A serve-side failure with its kind in front: the JSON-RPC error `data.kind`
 * (or the bridge's own — `not_connected`, `timeout`, `dropped`, `unreachable`,
 * `handshake_failed`, `bad_url`) is what callers branch on; the message is
 * what the log shows.
 */
class ServeError extends \RuntimeException
{
    /** @param array<string, mixed> $data the RPC error's data object */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly array $data = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<string, mixed> $error the JSON-RPC error object */
    public static function fromRpc(string $method, array $error): self
    {
        $data = \is_array($error['data'] ?? null) ? $error['data'] : [];
        $kind = \is_string($data['kind'] ?? null) ? $data['kind'] : 'rpc_' . (int) ($error['code'] ?? 0);

        return new self($kind, "channel-bridge: {$method}: " . (string) ($error['message'] ?? 'error'), $data);
    }

    /**
     * The chat-safe reason (015): getMessage() carries the `channel-bridge:`
     * prefix and the snake_case RPC method name, which belong in the log
     * only — a Discord reader gets the kind's sentence. Unknown and rpc_*
     * kinds share the generic line; the full message stays one warn-field
     * away for the operator.
     */
    public function userReason(): string
    {
        return Lang::t(match ($this->kind) {
            'not_connected' => 'channels.discord.serve.not_connected',
            'timeout' => 'channels.discord.serve.timeout',
            'dropped' => 'channels.discord.serve.dropped',
            'unreachable' => 'channels.discord.serve.unreachable',
            'handshake_failed' => 'channels.discord.serve.handshake_failed',
            'bad_url' => 'channels.discord.serve.bad_url',
            default => 'channels.discord.serve.failed',
        });
    }
}

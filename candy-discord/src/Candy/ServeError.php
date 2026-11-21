<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Candy;

use CandyCode\Bundles\Discord\Lang;
use SugarCraft\McpClient\Protocol\Refusal;

/**
 * A serve-side failure with its kind in front: callers branch on the kind, which for a refused
 * call is the wire's kind (the JSON-RPC error `data.kind`, carried through by the in-process host)
 * and for any other failure is `internal`. The in-process door produces no other kinds; the
 * message is what the log shows.
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

    /**
     * The router's error for a refused in-process call (PL-5d): the host's refusal carries the
     * wire's kind and data, so the router reads it as it reads a wire refusal. A failure that is
     * not a refusal (the host itself failed) is kind `internal`.
     */
    public static function fromHost(string $method, \Throwable $e): self
    {
        if ($e instanceof self) {
            return $e;
        }
        if ($e instanceof Refusal) {
            return new self($e->refusalKind(), "channel-bridge: {$method}: " . $e->getMessage(), $e->refusalData(), $e);
        }

        return new self('internal', "channel-bridge: {$method}: " . $e->getMessage(), [], $e);
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
            // An in-process call before the client's hello is the wire's not_initialized refusal (PL-5d).
            'not_connected', 'not_initialized' => 'channels.discord.serve.not_connected',
            'timeout' => 'channels.discord.serve.timeout',
            'dropped' => 'channels.discord.serve.dropped',
            'unreachable' => 'channels.discord.serve.unreachable',
            'handshake_failed' => 'channels.discord.serve.handshake_failed',
            'bad_url' => 'channels.discord.serve.bad_url',
            default => 'channels.discord.serve.failed',
        });
    }
}

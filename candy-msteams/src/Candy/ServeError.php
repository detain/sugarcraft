<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Candy;

use SugarCraft\Plugins\Host\RefusedCall;

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
        if ($e instanceof RefusedCall) {
            return new self($e->refusalKind(), "channel-bridge: {$method}: " . $e->getMessage(), $e->refusalData(), $e);
        }

        return new self('internal', "channel-bridge: {$method}: " . $e->getMessage(), [], $e);
    }
}

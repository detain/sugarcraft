<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Ws;

/**
 * What the WebSocket transport hands its traffic to. The transport (O-3a)
 * owns framing, limits, control frames and authentication; the handler owns
 * what a message MEANS — the `sugarcrush.v1` JSON-RPC dispatcher (O-3b)
 * implements this, and until it lands {@see ProtocolPendingHandler} does.
 */
interface MessageHandler
{
    /** $connection completed its upgrade and is authenticated. */
    public function onOpen(Connection $connection): void;

    /** One complete UTF-8 text message, within the size limit. */
    public function onMessage(Connection $connection, string $payload): void;

    /** $connection is gone; $code is the close code seen or sent (1006 when none). */
    public function onClose(Connection $connection, int $code): void;
}

<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

/**
 * The structured log the router writes to. Each row is an event name plus
 * scalar fields; the router never writes message text or secrets into it.
 */
interface BridgeLog
{
    /** @param array<string,mixed> $fields */
    public function debug(string $event, array $fields = []): void;

    /** @param array<string,mixed> $fields */
    public function info(string $event, array $fields = []): void;

    /** @param array<string,mixed> $fields */
    public function warn(string $event, array $fields = []): void;

    /** @param array<string,mixed> $fields */
    public function error(string $event, array $fields = []): void;
}

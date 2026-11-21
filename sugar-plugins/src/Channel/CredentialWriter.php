<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Channel;

/**
 * The single writer of a host's credential store (decision O9).
 *
 * A plugin never writes the store file itself. The host hands an implementer
 * of {@see WritesCredentials} one of these, and every change goes through
 * {@see mutate()}, so the host stays the only writer of the file and the
 * host's own lock and merge rules apply to every credential write.
 */
interface CredentialWriter
{
    /**
     * Read the current record, pass it to $change, and persist what $change
     * returns. Returns the record as it was written.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed>
     */
    public function mutate(\Closure $change): array;
}

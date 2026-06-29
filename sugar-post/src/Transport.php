<?php

declare(strict_types=1);

namespace SugarCraft\Post;

use SugarCraft\Async\CancellationToken;

/**
 * Pluggable transport for sending Email messages.
 */
interface Transport
{
    /**
     * Send the email.
     * Throws \RuntimeException on failure.
     *
     * @param Email $email
     * @param CancellationToken|null $token Optional cancellation token to abort the send
     */
    public function send(Email $email, ?CancellationToken $token = null): void;

    /**
     * Name of this transport for logging/debugging.
     */
    public function name(): string;
}

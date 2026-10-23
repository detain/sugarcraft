<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

use SugarCraft\Async\CancellationToken;

/**
 * Pluggable transport for sending Email messages.
 */
interface Transport
{
    /**
     * Send the email.
     *
     * Implementations throw \RuntimeException on any transport failure
     * (connection, protocol, or provider error) and \InvalidArgumentException
     * when the message itself is unusable at this seam (e.g. no sender to
     * MAIL FROM). Envelope-shape validation of recipients lives in
     * {@see Mailer::send()}, which guards before dispatch; a transport used
     * directly must not silently accept an empty envelope either.
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

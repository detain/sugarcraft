<?php

declare(strict_types=1);

namespace SugarCraft\Mail;

use SugarCraft\Async\CancellationToken;

/**
 * High-level email sender wrapping a Transport.
 */
final class Mailer
{
    private Transport $transport;

    public function __construct(Transport $transport)
    {
        $this->transport = $transport;
    }

    /**
     * Send an email via the configured transport.
     *
     * The shape is validated here, BEFORE the transport is asked to dial:
     * "does this message exist" fails fast and loud at this seam, so no
     * transport ever pays a connection for an empty envelope.
     *
     * @throws \InvalidArgumentException if the message has no recipients or no sender.
     * @throws \RuntimeException         if the transport fails (or the token was already cancelled).
     */
    public function send(Email $email, ?CancellationToken $token = null): void
    {
        if ($token !== null && $token->isCancelled()) {
            throw new \RuntimeException(Lang::t('mailer.send_cancelled'));
        }
        if ($email->to === [] && $email->cc === [] && $email->bcc === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_recipient'));
        }
        if ($email->from === []) {
            throw new \InvalidArgumentException(Lang::t('mailer.no_from'));
        }

        $this->transport->send($email, $token);
    }

    /**
     * Get the transport name for logging.
     */
    public function transportName(): string
    {
        return $this->transport->name();
    }
}

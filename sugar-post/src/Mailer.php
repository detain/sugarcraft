<?php

declare(strict_types=1);

namespace SugarCraft\Post;

use SugarCraft\Async\CancellationToken;
use SugarCraft\Post\Lang;

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
     * @throws \RuntimeException if the transport fails.
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

<?php

declare(strict_types=1);

namespace SugarCraft\Code\App\Tabs;

use SugarCraft\Code\App\Msg;

/**
 * Ask the App to open a new tab in the session deck.
 *
 * Carries only intent — the App owns deck construction, model building,
 * and parking of the outgoing foreground session. A null sessionId asks
 * the App to mint a fresh session first; the deck's own tab key is
 * assigned by the App because it must stay stable across `/branch`
 * re-keys of the underlying session.
 */
final class OpenTabMsg implements Msg
{
    /**
     * @param string|null $sessionId Pre-existing session to open, or null to mint one.
     * @param string|null $sessionName Optional human name; the App falls back to its default.
     */
    public function __construct(
        public readonly ?string $sessionId = null,
        public readonly ?string $sessionName = null,
    ) {
    }

    /**
     * Return the requested session id, null when one should be minted.
     */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Return the requested human name, null when the App should choose.
     */
    public function sessionName(): ?string
    {
        return $this->sessionName;
    }
}

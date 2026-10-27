<?php

declare(strict_types=1);

namespace SugarCraft\Code\App\Tabs;

use SugarCraft\Code\App\Msg;

/**
 * Ask the App to close one tab of the session deck.
 *
 * A null tabKey means "whichever tab is in the foreground right now".
 * The refusal of a last-tab close is SessionDeck's own invariant; this
 * message never encodes an override.
 */
final class CloseTabMsg implements Msg
{
    /**
     * @param string|null $tabKey Deck key to close, or null for the active one.
     */
    public function __construct(
        public readonly ?string $tabKey = null,
    ) {
    }

    /**
     * Return the deck key to close, null for the active tab.
     */
    public function tabKey(): ?string
    {
        return $this->tabKey;
    }
}

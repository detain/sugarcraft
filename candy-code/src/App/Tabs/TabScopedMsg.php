<?php

declare(strict_types=1);

namespace SugarCraft\Code\App\Tabs;

use SugarCraft\Code\App\Msg;
use SugarCraft\Core\Msg as CoreMsg;

/**
 * Envelope tying an inner Msg to the tab whose model produced it.
 *
 * A background tab's Chat keeps running while the foreground shows
 * another tab; when one of its lazily-wrapped Cmds finally returns a
 * Msg, the loop needs to know WHO produced it before re-entering the
 * Elm update chain. {@see TabScope::msg()} builds exactly one of these
 * per non-sentinel result; the App routes it to the owning tab's model
 * (foreground unwraps and re-enters; parked models update directly).
 */
final class TabScopedMsg implements Msg
{
    /**
     * @param string  $tabKey Deck key of the producing tab.
     * @param CoreMsg $inner  The Msg the tab's model would have seen unwrapped.
     */
    public function __construct(
        public readonly string $tabKey,
        public readonly CoreMsg $inner,
    ) {
    }

    /**
     * Return the deck key of the producing tab.
     */
    public function tabKey(): string
    {
        return $this->tabKey;
    }

    /**
     * Return the wrapped inner Msg.
     */
    public function inner(): CoreMsg
    {
        return $this->inner;
    }
}

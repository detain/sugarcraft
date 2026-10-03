<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Forms\ItemList\Item;
use SugarCraft\Forms\ItemList\ItemList;

/**
 * One row of the session picker as an {@see Item} for the underlying
 * {@see ItemList} selection model (E744 WS5).
 *
 * The picker paints its own chrome ({@see SessionPicker::render()}) so this
 * row object never reaches the screen — it exists to give the widget a stable
 * item identity per session id. Filtering is the picker's own (`/`, ranked by
 * candy-fuzzy over title, last prompt, agent, id and branch); the widget's
 * filter path stays disabled, so {@see filterValue()} composes nothing. The
 * row text arrives sanitized by
 * {@see \SugarCraft\Crush\Chat::sanitizeSessionField()} upstream.
 *
 * Not to be confused with {@see \SugarCraft\Crush\Session\SessionRow}, the
 * typed store row the host builds the picker's row arrays from.
 */
final class SessionRow implements Item
{
    public function __construct(
        private readonly string $sessionId,
        private readonly string $title,
    ) {
    }

    /**
     * @param array{sessionId: string, sessionName: string} $session a picker row; see {@see SessionPicker}'s `Row` shape
     */
    public static function fromSession(array $session): self
    {
        return new self($session['sessionId'], $session['sessionName']);
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return '';
    }

    public function filterValue(): string
    {
        return $this->title;
    }
}

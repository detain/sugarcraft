<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * The debounced re-read tick behind the session picker's `/` filter (C-SES-2).
 *
 * Every query edit arms a {@see \SugarCraft\Core\Cmd::tick()} for this
 * message; when it lands,
 * {@see Chat} re-reads the FTS-ranked page ONLY while the picker on screen
 * still shows exactly $query. Fast typing therefore collapses into one store
 * read per pause: each newer edit re-arms the tick with its own text, and the
 * ticks armed for the abandoned prefixes arrive stale and no-op — there is no
 * tick to cancel, the query stamp IS the cancellation.
 *
 * The payload is the query the tick was armed for, not the picker's live one:
 * a tick must never be able to read a page the user has since retyped away
 * from (the same stamp rule the length-stop notices follow).
 */
final class SessionPickerSearchMsg implements Msg
{
    public function __construct(public readonly string $query)
    {
    }
}

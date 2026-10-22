<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * Leave the read-only Agent View (roadmap P-C2, Appendix P §5.5): `Esc` or
 * `Alt+↑` in the view, a click on the header's `main` crumb, or the agent
 * dashboard's `q` ({@see \SugarCraft\Code\Tui\Commands\QuitAgentViewCmd}).
 *
 * {@see \SugarCraft\Code\App\App} drops the view state, then hands the message
 * on to the hosted {@see Chat}, which disarms its `Esc` `Esc` cancel: the key
 * that left the view must not count as the first half of a turn cancel.
 */
final readonly class CloseAgentViewMsg implements Msg
{
}

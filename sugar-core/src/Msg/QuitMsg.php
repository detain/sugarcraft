<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Signals the {@see \SugarCraft\Core\Program} to tear down and exit.
 * Returned from a Cmd or sent via {@see \SugarCraft\Core\Program::quit()}.
 */
final class QuitMsg implements Msg
{
}

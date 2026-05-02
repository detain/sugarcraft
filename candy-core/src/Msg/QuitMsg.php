<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Signals the {@see \CandyCore\Core\Program} to tear down and exit.
 * Returned from a Cmd or sent via {@see \CandyCore\Core\Program::quit()}.
 */
final class QuitMsg implements Msg
{
}

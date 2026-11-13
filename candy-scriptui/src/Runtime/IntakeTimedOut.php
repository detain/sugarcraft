<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * Raised by {@see IntakeReader} when the data channel stayed silent past the
 * caller's `--timeout`; commands map it to {@see TimeoutGuard::EXIT_TIMEOUT}.
 */
final class IntakeTimedOut extends \RuntimeException
{
}

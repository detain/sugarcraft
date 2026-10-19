<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * @internal The reader behind an {@see SseMcpServer} stream raises this when
 * the wait deadline owned by the calling exchange passes. It is a MARKER, not
 * a diagnosis: every real raise is caught and re-raised by the leg that set
 * the deadline (endpoint await, reply wait), each with the sentence its
 * operator needs. A bare one escaping to a caller means a wait forgot its
 * handler — a bug this class exists to make loud.
 */
final class SseStreamDeadlineException extends \RuntimeException
{
}

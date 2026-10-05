<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Thrown at a cooperative stop point when the user moved a running delegated
 * run to the background (roadmap P-E3, `Ctrl+X b` in the Agent View) — at
 * its next tool start or provider step, the same points a soft cancel stops
 * at ({@see ToolCallCancelled}). The run's failure boundary in
 * {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} saves it resumable and
 * hands it to a background session that continues the same conversation,
 * so the delegating `Task` call returns at once.
 */
final class RunPromotedToBackground extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('moved to the background by the user');
    }
}

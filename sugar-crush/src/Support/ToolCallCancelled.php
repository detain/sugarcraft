<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Thrown at a cooperative stop point when the user stopped the running call
 * (`cancel_tool{callId}`, roadmap 1.C-4b, {@see ToolCancelRequests}) — today a
 * lone Task's delegated run, at its next tool start or provider step. The
 * run's own failure boundary turns it into a cancelled, resumable result.
 */
final class ToolCallCancelled extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(ToolCancelRequests::CANCELLED);
    }
}

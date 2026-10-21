<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents\Live;

/**
 * The overlay lifecycle of one live run row (v4-F15).
 *
 * Coarser than {@see \SugarCraft\Code\Agents\SubAgentStatus} on purpose:
 * the live pane distinguishes only "waiting for its first frame", "on
 * screen now", and "done" — the outcome of a finished row is carried by
 * the frame's own verdict, not by a state here. The STATUS_* constants on
 * {@see AgentLiveState} remain the public string spelling (the property is
 * read by out-of-scope UI files), and a lockstep pin in
 * AgentLiveRegistryTest keeps the two spellings one vocabulary.
 */
enum AgentLiveStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Finished = 'finished';
}

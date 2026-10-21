<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents\Live;

/**
 * The two statuses a run card owns (v4-F15): while its run works the card
 * says {@see RunCardStatus::Running}; a card whose daemon died with no
 * group reading for it says {@see RunCardStatus::Lost}, never a false
 * running. Every other value a card's status slot can hold is a
 * {@see \SugarCraft\Code\Agents\SubAgentStatus} outcome passed through
 * from the run itself — this enum is only what the card mints.
 *
 * {@see AgentRunCards}'s STATUS_RUNNING / STATUS_LOST constants stay the
 * public string spelling (card arrays cross a serialization boundary); a
 * lockstep pin in AgentLiveRegistryTest keeps the two spellings one
 * vocabulary.
 */
enum RunCardStatus: string
{
    case Running = 'running';
    case Lost = 'lost';
}

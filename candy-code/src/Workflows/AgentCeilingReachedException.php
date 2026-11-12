<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * P0-3 `config.maxAgents`: a dispatch that would cross the run's agent
 * ceiling. A plain RuntimeException to every caller that already catches
 * one (the stage loop turns it into the stage's failure); its own type so a
 * `type: map` stage (P1-2) can tell "this run may dispatch no more" — which
 * fails the whole stage and admits no further item — from one item's own
 * failure, which only drops that item.
 */
final class AgentCeilingReachedException extends \RuntimeException
{
}

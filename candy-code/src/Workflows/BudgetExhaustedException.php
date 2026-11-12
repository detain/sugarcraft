<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * P1-7 `config.maxTokens` / `config.maxCost` (or the run's external token
 * budget): a dispatch that would start with the run's spend at or past its
 * ceiling. Its message begins "budget exhausted (spent X of Y tokens|USD)".
 * A subclass of {@see AgentCeilingReachedException} because it is the same
 * run-wide "dispatch no more" refusal — a `type: map` stage stops admitting
 * items on either — while the stage loop tells them apart: a budget stop
 * PAUSES the run (resume under a raised ceiling continues), the agent
 * ceiling fails it.
 */
final class BudgetExhaustedException extends AgentCeilingReachedException
{
}

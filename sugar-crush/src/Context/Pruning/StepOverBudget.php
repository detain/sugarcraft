<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Context\ContextPressure;

/**
 * Thrown by the engine's request observer to keep an over-budget request from
 * being sent (roadmap 2.2-1 / 2.4-1).
 *
 * {@see \SugarCraft\Crush\Runtime::run()} hands its observer the request it
 * has fully built — system prompt and tool schemas included — just before the
 * provider call, and that is the only point the whole footprint can be
 * measured. The observer cannot change the request, so when it is over the
 * step budget and the turn still has a way to relieve it, the observer throws
 * this: `run()` has made no call and yielded nothing yet, so the turn loop
 * catches it, prunes or summarises, and builds the step again. Never escapes
 * {@see \SugarCraft\Crush\Backend\EngineBackend}'s turn loop.
 */
final class StepOverBudget extends \RuntimeException
{
    public function __construct(public readonly ContextPressure $pressure)
    {
        parent::__construct(sprintf(
            'step request is over its budget: %d of %d tokens',
            $pressure->tokens,
            $pressure->threshold,
        ));
    }
}

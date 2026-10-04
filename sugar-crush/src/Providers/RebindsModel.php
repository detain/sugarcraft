<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * A provider that can answer for another model without being rebuilt
 * (roadmap N-P3b remainder, 4.1-1).
 *
 * WHY THE MODEL IS PROVIDER STATE AT ALL. A request names its model on
 * {@see CompleteRequest::$model}, and most providers already price a request
 * at that id. But {@see ProviderInterface::contextWindow()} takes no argument:
 * it answers for the model the provider was BUILT with, and so does any rate a
 * provider reads off its own configured id. So
 * {@see \SugarCraft\Crush\Backend\EngineBackend::withModel()} on its own moved
 * the wire and left every context tier a percentage of the old model's window
 * — a sub-agent on a smaller model compacted late, one on a larger model
 * early.
 *
 * {@see withModel()} returns a copy keyed to `$model` — window, rates and any
 * other per-model answer — and leaves the receiver untouched. A provider whose
 * model is fixed by its server (SGLang serves one model) does not implement
 * this; the caller then keeps the provider and refuses a model it cannot
 * serve rather than relabelling it.
 */
interface RebindsModel
{
    /**
     * A copy of this provider answering for $model.
     */
    public function withModel(string $model): static;
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

use SugarCraft\Code\Events\ToolProgress;

/**
 * Opt-in declaration that a {@see Tool} can stream live {@see ToolProgress}
 * frames while it runs, and the seam a {@see \SugarCraft\Code\Backend\EngineBackend}
 * uses to hand it the turn's event channel.
 *
 * WHY A SEAM OF ITS OWN. The `DelegatesToEngine::withEngine()` emitter carries
 * delegation beats and preview frames; a shell tail is neither, and the tools
 * that produce one (Bash) do not delegate to an engine at all. The turn binds
 * this channel through {@see withToolProgressEmitter()} instead, on the same
 * pid-gated rule as every other live event: a frame pushed from a forked
 * grandchild lands in a copy of the parent's state and vanishes, so the bound
 * closure checks its writer's process before writing.
 */
interface AcceptsToolProgress
{
    /**
     * This tool streaming its live tail through $emit, every other field kept.
     *
     * @param \Closure(ToolProgress): void $emit
     */
    public function withToolProgressEmitter(\Closure $emit): self;
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;

/**
 * Internal Msg that lands the startup summary's expensive facts computed
 * OFF the paint path (VIS-1-P2, plan_comparison C-TUI-7).
 *
 * {@see \SugarCraft\Code\Support\StartupSummary::render()} used to resolve
 * them inline on a memo miss — a `git branch` child process and a full
 * `SkillManager::loadAll` disk scan running inside `view()`, so the first
 * frame of every launch (and every frame after a mode or model switch) paid
 * for them synchronously. The gather now rides the Cmd channel:
 * {@see \SugarCraft\Code\Support\StartupSummary::gatherCmd()} produces it
 * from `Chat::init()` and from the `ClearTranscript`/`SwitchSession` arms of
 * {@see \SugarCraft\Code\Chat::applyCommandResult()}, the Program settles
 * this Msg, and {@see \SugarCraft\Code\Support\StartupSummary::store()}
 * parks the bag for the next paint.
 *
 * The bag is the producer's private shape on purpose — the summary parses
 * every key defensively at compose time, so a stale or partial bag degrades
 * to omitted rows, never to a fatal. One producer, one consumer, both in
 * this package.
 */
final class StartupSummaryGatheredMsg implements Msg
{
    /** @param array<string, mixed> $facts */
    public function __construct(public readonly array $facts)
    {
    }
}

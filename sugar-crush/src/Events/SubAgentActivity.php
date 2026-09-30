<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

/**
 * "A sub-agent the Task tool is running has begun, produced activity, or
 * finished" — emitted by {@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}
 * through a per-turn emitter the engine threads down to it, on the SAME frame
 * channel {@see ToolStarted}, {@see ToolFinished} and {@see SpendCapBreached}
 * use, because a delegated run's story only renders correctly if its beats
 * arrive interleaved with the turn's own in socket order.
 *
 * Exists because the engine path runs TaskTool INSIDE
 * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s forked
 * child: the child's AgentManager row (and every chunk the sub-agent streamed
 * into it) died with the child, so the Agents dashboard showed nothing for a
 * delegation that was visibly, expensively running. The frame re-materialises
 * the row in the PARENT manager as an observed mirror
 * ({@see \SugarCraft\Crush\Agents\AgentManager::projectRemoteSubAgent()}).
 *
 * ONE-WAY OBSERVATION, NEVER CONTROL. A consumer that ignores these frames
 * costs the delegated run nothing — exactly the channel contract every other
 * event here upholds. $tail is display data, bounded by the emitter before it
 * crosses the wire; nothing re-parses it.
 *
 * $id is the CHILD's SubAgent id, and it is the projection key: two
 * concurrent Task calls for the same agent name are two rows, and a bare name
 * could not tell their frames apart. The child's pid is already inside that
 * id (E329's namespace law), which also makes it collision-free across
 * processes without the protocol carrying a pid of its own.
 */
final readonly class SubAgentActivity
{
    public const OP_STARTED = 'started';
    public const OP_PROGRESS = 'progress';
    public const OP_FINISHED = 'finished';

    /** The ops a frame may carry — decodeEvent() validates against this. */
    public const OPS = [self::OP_STARTED, self::OP_PROGRESS, self::OP_FINISHED];

    /**
     * @param string $op   one of the OP_* constants.
     * @param string $id   the delegated run's SubAgent id, minted in the child.
     * @param string $name the roster agent name being run.
     * @param string $task the delegated prompt, clipped to a display snippet
     *                     by the emitter; empty on every op but started.
     * @param int    $seq  1-based counter, strictly increasing per run — the
     *                     reader's staleness ordering within one $id.
     * @param string $tail bounded output tail as of this beat.
     */
    public function __construct(
        public string $op,
        public string $id,
        public string $name,
        public string $task,
        public int $seq,
        public string $tail,
    ) {}
}

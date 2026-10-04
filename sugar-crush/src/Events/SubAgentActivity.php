<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Agents\Live\ActivityItem;

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
 *
 * VERSION 2 (Appendix P §4.3, step P-B1) adds, all optional so a v1 frame
 * still decodes ({@see fromArray()} fills the defaults):
 *  - $parentCallId: the parent's Task tool-call id, so a beat can be hung
 *    under the right Task row; $parentAgentId for nesting (4.7);
 *  - $description: the Task call's own `description` argument;
 *  - $items: what the run did since its previous frame, coalesced child-side
 *    by {@see \SugarCraft\Crush\Agents\Live\SubAgentActivityBuffer};
 *  - $stats: step, maxSteps, tools, tokensIn, tokensOut, costUsd, startedAt;
 *  - on finished: the real $outcome, $error and $resumeId — a failed run
 *    used to project as complete;
 *  - the `queued` op: a Task member waiting for a delegation slot (step
 *    0.16), sent by the forking turn child under {@see queuedId()} until the
 *    member's own `started` replaces it.
 * The v1 fields stay: $tail and the running totals still feed the Agents
 * pane and {@see \SugarCraft\Crush\Agents\AgentManager::liveOutput()}.
 *
 * STEP P-C1 adds, still optional:
 *  - $transcriptLog: the run's own JSONL transcript
 *    ({@see \SugarCraft\Crush\Agents\Live\SubAgentTranscriptLog}), written
 *    by the process that runs it, announced on started and finished;
 *  - $parentSessionId: the session the delegating turn belongs to — the
 *    parent of the child session the finished run becomes;
 *  - $childSessionId: that child session's id. Never set by the child (only
 *    the parent writes SQLite): the parent stamps it on the finished beat it
 *    projects ({@see withChildSessionId()}) so every consumer after the
 *    projection — the live line, the server's `agent.status` — names it.
 *
 * {@see toArray()} is the wire shape the fork frame, the grandchild relay and
 * (later) the server's `agent.*` events share; {@see fromArray()} is the one
 * validator for all of them.
 */
final readonly class SubAgentActivity
{
    public const OP_STARTED = 'started';
    public const OP_PROGRESS = 'progress';
    public const OP_FINISHED = 'finished';
    public const OP_QUEUED = 'queued';

    /** The ops a frame may carry — decodeEvent() validates against this. */
    public const OPS = [self::OP_STARTED, self::OP_PROGRESS, self::OP_FINISHED, self::OP_QUEUED];

    /** The wire version {@see toArray()} writes. */
    public const VERSION = 2;

    /** How a finished run ended ({@see $outcome}); '' before it finished. */
    public const OUTCOME_COMPLETE = 'complete';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_CANCELLED = 'cancelled';
    public const OUTCOME_EMPTY = 'empty';

    public const OUTCOMES = [self::OUTCOME_COMPLETE, self::OUTCOME_FAILED, self::OUTCOME_CANCELLED, self::OUTCOME_EMPTY];

    /** Items one frame may carry; a decoder keeps the newest this many. */
    public const MAX_ITEMS = 32;

    /** Byte ceiling on $error and $description as carried on the wire. */
    public const MAX_TEXT_BYTES = 512;

    /** The integer keys of {@see $stats}, then the float ones. */
    public const STAT_INT_KEYS = ['step', 'maxSteps', 'tools', 'tokensIn', 'tokensOut'];
    public const STAT_FLOAT_KEYS = ['costUsd', 'startedAt'];

    /** A tool call's state in {@see $calls}. */
    public const CALL_RUNNING = 'running';
    public const CALL_OK = 'ok';
    public const CALL_ERROR = 'error';

    /** Most recent calls a beat carries; older ones fall off the front. */
    public const MAX_CALLS = 20;

    /**
     * @param string $op   one of the OP_* constants.
     * @param string $id   the delegated run's SubAgent id, minted in the child.
     * @param string $name the roster agent name being run.
     * @param string $task the delegated prompt, clipped to a display snippet
     *                     by the emitter; empty on every op but started.
     * @param int    $seq  1-based counter, strictly increasing per run — the
     *                     reader's staleness ordering within one $id.
     * @param string $tail bounded output tail as of this beat.
     * @param int    $tokensUsed the run's provider-counted tokens so far —
     *                     a running total, not a delta, so a dropped beat
     *                     costs the row nothing the next one cannot restore.
     * @param float  $costUsd    the run's spend so far, same rule.
     * @param int    $lines      trail lines the run has produced so far;
     *                     $tail is clipped, so this is what an "N more
     *                     lines" count reads.
     * @param string $model      the model the run really executes on (the
     *                     one TaskTool chose for it, roadmap 4.1-1); '' when
     *                     unknown.
     * @param int    $contextTokens the run's CURRENT context: what its latest
     *                     request carried, not a running total; 0 when unknown.
     * @param list<array{id: string, label: string, state: string, at: int}> $calls
     *                     the run's most recent tool calls, newest last,
     *                     bounded by the emitter — the whole recent list on
     *                     every beat, so the latest beat is the truth.
     * @param string $parentCallId the parent's Task tool-call id; '' when unknown (v1).
     * @param string|null $parentAgentId the delegating run's id when nested; null at depth 1.
     * @param string $description the Task call's `description` argument.
     * @param list<ActivityItem> $items what the run did since its previous frame.
     * @param array{step?: int, maxSteps?: int, tools?: int, tokensIn?: int, tokensOut?: int, costUsd?: float, startedAt?: float} $stats
     *                     the run's figures as of this frame; [] when unknown (v1).
     * @param string $outcome one of the OUTCOME_* constants on finished; '' otherwise.
     * @param string|null $error why a run that did not complete ended.
     * @param string|null $resumeId the id a later Task call resumes this run by.
     * @param string|null $transcriptLog the run's JSONL transcript path (P-C1).
     * @param string|null $parentSessionId the delegating turn's session (P-C1).
     * @param string|null $childSessionId the child session the finished run became (P-C1).
     */
    public function __construct(
        public string $op,
        public string $id,
        public string $name,
        public string $task,
        public int $seq,
        public string $tail,
        public int $tokensUsed = 0,
        public float $costUsd = 0.0,
        public int $lines = 0,
        public string $model = '',
        public int $contextTokens = 0,
        public array $calls = [],
        public string $parentCallId = '',
        public ?string $parentAgentId = null,
        public string $description = '',
        public array $items = [],
        public array $stats = [],
        public string $outcome = '',
        public ?string $error = null,
        public ?string $resumeId = null,
        public ?string $transcriptLog = null,
        public ?string $parentSessionId = null,
        public ?string $childSessionId = null,
    ) {}

    /**
     * This beat naming the child session its finished run was stored as —
     * the parent's stamp after {@see \SugarCraft\Crush\Agents\AgentManager::projectRemoteSubAgent()}
     * created it (step P-C1).
     */
    public function withChildSessionId(string $childSessionId): self
    {
        return new self(...array_merge(get_object_vars($this), ['childSessionId' => $childSessionId]));
    }

    /**
     * The id a queued member's placeholder row is keyed by, until its own
     * `started` (which carries the same $parentCallId) replaces it.
     */
    public static function queuedId(string $parentCallId): string
    {
        return 'queued_' . $parentCallId;
    }

    /**
     * The wire shape, version 2: one fixed set of keys on every op, so a
     * fixed-shape reader never sees a key vanish between ops. Plain arrays
     * only — the reader unserializes with `allowed_classes => false`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'op' => $this->op,
            'id' => $this->id,
            'name' => $this->name,
            'task' => $this->task,
            'seq' => $this->seq,
            'tail' => $this->tail,
            'tokens' => $this->tokensUsed,
            'cost' => $this->costUsd,
            'lines' => $this->lines,
            'model' => $this->model,
            'context' => $this->contextTokens,
            'calls' => $this->calls,
            'parentCallId' => $this->parentCallId,
            'parentAgentId' => $this->parentAgentId,
            'description' => $this->description,
            'items' => array_map(static fn (ActivityItem $item): array => $item->toArray(), $this->items),
            'stats' => $this->stats,
            'outcome' => $this->outcome,
            'error' => $this->error,
            'resumeId' => $this->resumeId,
            'transcriptLog' => $this->transcriptLog,
            'parentSessionId' => $this->parentSessionId,
            'childSessionId' => $this->childSessionId,
        ];
    }

    /**
     * Rebuild a beat from its wire shape — v2, or a v1 frame, which gets the
     * v2 defaults — or null when the identity fields are out of shape. The
     * identity (op, id, name, task, seq, tail) is all-or-nothing: a beat that
     * lies about it must drop, not materialise a lying row. Everything else
     * is display data and degrades field by field: a bad figure is 0, a bad
     * item or call entry is skipped, an unknown outcome is ''.
     *
     * @param array<string, mixed> $frame
     */
    public static function fromArray(array $frame): ?self
    {
        $op = $frame['op'] ?? null;
        $id = $frame['id'] ?? null;
        $name = $frame['name'] ?? null;
        $task = $frame['task'] ?? null;
        $seq = $frame['seq'] ?? null;
        $tail = $frame['tail'] ?? null;
        if (!is_string($op) || !in_array($op, self::OPS, true)
            || !is_string($id) || $id === ''
            || !is_string($name) || $name === ''
            || !is_string($task) || !is_int($seq) || !is_string($tail)) {
            return null;
        }

        $outcome = $frame['outcome'] ?? '';

        return new self(
            $op,
            $id,
            $name,
            $task,
            $seq,
            $tail,
            ...self::totals($frame),
            parentCallId: self::text($frame['parentCallId'] ?? null, 256),
            parentAgentId: is_string($frame['parentAgentId'] ?? null) && $frame['parentAgentId'] !== '' ? self::text($frame['parentAgentId'], 256) : null,
            description: self::text($frame['description'] ?? null, self::MAX_TEXT_BYTES),
            items: self::items($frame['items'] ?? []),
            stats: self::stats($frame['stats'] ?? []),
            outcome: is_string($outcome) && in_array($outcome, self::OUTCOMES, true) ? $outcome : '',
            error: is_string($frame['error'] ?? null) ? self::text($frame['error'], self::MAX_TEXT_BYTES) : null,
            resumeId: is_string($frame['resumeId'] ?? null) && $frame['resumeId'] !== '' ? self::text($frame['resumeId'], 64) : null,
            transcriptLog: self::optionalText($frame['transcriptLog'] ?? null, 4096),
            parentSessionId: self::optionalText($frame['parentSessionId'] ?? null, 256),
            childSessionId: self::optionalText($frame['childSessionId'] ?? null, 256),
        );
    }

    /**
     * A non-empty string field, clipped like {@see text()}, or null.
     */
    private static function optionalText(mixed $value, int $bytes): ?string
    {
        return is_string($value) && $value !== '' ? self::text($value, $bytes) : null;
    }

    /**
     * A beat's v1 running totals, each 0 when absent or out of shape: they
     * are display-only, so a bad figure costs the row its count, never the
     * beat.
     *
     * @param array<string, mixed> $frame
     * @return array{tokensUsed: int, costUsd: float, lines: int, model: string, contextTokens: int, calls: list<array{id: string, label: string, state: string, at: int}>}
     */
    public static function totals(array $frame): array
    {
        $tokens = $frame['tokens'] ?? 0;
        $cost = $frame['cost'] ?? 0.0;
        $lines = $frame['lines'] ?? 0;
        $model = $frame['model'] ?? '';
        $context = $frame['context'] ?? 0;

        return [
            'tokensUsed' => is_int($tokens) && $tokens >= 0 ? $tokens : 0,
            'costUsd' => (is_float($cost) || is_int($cost)) && $cost >= 0 ? (float) $cost : 0.0,
            'lines' => is_int($lines) && $lines >= 0 ? $lines : 0,
            'model' => is_string($model) ? $model : '',
            'contextTokens' => is_int($context) && $context >= 0 ? $context : 0,
            'calls' => self::calls($frame['calls'] ?? []),
        ];
    }

    /**
     * A beat's recent-call list, keeping only well-formed entries, at most
     * {@see MAX_CALLS} of the newest.
     *
     * @return list<array{id: string, label: string, state: string, at: int}>
     */
    private static function calls(mixed $calls): array
    {
        if (!is_array($calls)) {
            return [];
        }

        $states = [self::CALL_RUNNING, self::CALL_OK, self::CALL_ERROR];
        $kept = [];
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $id = $call['id'] ?? null;
            $label = $call['label'] ?? null;
            $state = $call['state'] ?? null;
            $at = $call['at'] ?? null;
            if (!is_string($id) || !is_string($label) || !in_array($state, $states, true) || !is_int($at)) {
                continue;
            }
            $kept[] = ['id' => $id, 'label' => $label, 'state' => $state, 'at' => $at];
        }

        return array_slice($kept, -self::MAX_CALLS);
    }

    /**
     * @return list<ActivityItem>
     */
    private static function items(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $kept = [];
        foreach ($items as $item) {
            $decoded = ActivityItem::fromArray($item);
            if ($decoded !== null) {
                $kept[] = $decoded;
            }
        }

        return array_slice($kept, -self::MAX_ITEMS);
    }

    /**
     * Keep only the known stat keys, each a non-negative number of its type.
     *
     * @return array{step?: int, maxSteps?: int, tools?: int, tokensIn?: int, tokensOut?: int, costUsd?: float, startedAt?: float}
     */
    private static function stats(mixed $stats): array
    {
        if (!is_array($stats)) {
            return [];
        }

        $kept = [];
        foreach (self::STAT_INT_KEYS as $key) {
            $value = $stats[$key] ?? null;
            if (is_int($value) && $value >= 0) {
                $kept[$key] = $value;
            }
        }
        foreach (self::STAT_FLOAT_KEYS as $key) {
            $value = $stats[$key] ?? null;
            if ((is_float($value) || is_int($value)) && $value >= 0) {
                $kept[$key] = (float) $value;
            }
        }

        return $kept;
    }

    /**
     * A string field bounded to $bytes, never split inside a codepoint;
     * anything that is not a string is ''.
     */
    private static function text(mixed $value, int $bytes): string
    {
        if (!is_string($value)) {
            return '';
        }
        if (strlen($value) <= $bytes) {
            return $value;
        }

        $cut = substr($value, 0, $bytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }
}

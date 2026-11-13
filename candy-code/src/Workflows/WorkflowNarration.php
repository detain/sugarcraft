<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

use SugarCraft\Code\Agents\Live\ActivityItem;
use SugarCraft\Code\Events\SubAgentActivity;

/**
 * wf-impl P2-2 (tool half): a `Workflow` tool run's narration on the live
 * line under its tool row.
 *
 * The run is one row in the turn's live-agent registry: a `started` beat when
 * it begins, one `progress` beat per announcement the engine makes
 * ({@see WorkflowEngine::setAnnouncementSink()} — `announce:` lines and the
 * mandatory truncation / casualty / budget / ceiling notices) carrying the
 * line as the row's latest text, and a `finished` beat with the run's
 * outcome. The beats ride the turn's delegation emitter
 * ({@see \SugarCraft\Code\Tools\DelegatesToEngine}), which is pid-bound and
 * one-way: a dropped beat costs a repaint, never the run. No emitter (a
 * headless caller, a test) narrates nothing; the announcements still come
 * back on the result.
 *
 * Mutable on purpose: it counts the beats (`seq` must strictly increase per
 * run id) across the sink's calls.
 */
final class WorkflowNarration
{
    private int $seq = 0;

    private readonly string $id;

    /**
     * @param ?\Closure(SubAgentActivity): void $emit
     */
    public function __construct(
        private readonly ?\Closure $emit,
        private readonly string $toolCallId,
        private readonly string $workflowName,
    ) {
        // The registry keys rows by id; the pid keeps two processes' runs
        // apart, the call id two calls in one turn (E329's namespace law).
        $this->id = 'workflow-' . (int) getmypid() . '-' . ($toolCallId !== '' ? $toolCallId : bin2hex(random_bytes(4)));
    }

    /** @return \Closure(string, array<string, mixed>): void the engine's announcement sink */
    public function sink(): \Closure
    {
        return function (string $line, array $meta): void {
            $this->say($line);
        };
    }

    /**
     * One line as the row's latest text. The run-ID line rides this too: the
     * Workflow tool's live row is the only transcript-visible place a TUI user
     * sees a run ID before its result arrives, and a killed run never gets one.
     */
    public function say(string $line): void
    {
        $this->beat(SubAgentActivity::OP_PROGRESS, [ActivityItem::text($line)]);
    }

    public function started(): void
    {
        $this->beat(SubAgentActivity::OP_STARTED, []);
    }

    public function finished(string $outcome, ?string $error = null): void
    {
        $this->beat(SubAgentActivity::OP_FINISHED, [], $outcome, $error);
    }

    /** @param list<ActivityItem> $items */
    private function beat(string $op, array $items, string $outcome = '', ?string $error = null): void
    {
        if ($this->emit === null) {
            return;
        }

        try {
            ($this->emit)(new SubAgentActivity(
                $op,
                $this->id,
                'workflow',
                $op === SubAgentActivity::OP_STARTED ? $this->workflowName : '',
                ++$this->seq,
                '',
                parentCallId: $this->toolCallId,
                description: "Workflow '{$this->workflowName}'",
                items: $items,
                outcome: $outcome,
                error: $error === null || $error === '' ? null : mb_strcut($error, 0, SubAgentActivity::MAX_TEXT_BYTES, 'UTF-8'),
            ));
        } catch (\Throwable) {
            // Narration is display data; it must never break the run.
        }
    }
}

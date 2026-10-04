<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;

/**
 * Represents a single background session running an agent in a child process.
 *
 * Background sessions continue running after the main TUI closes and report
 * their health via heartbeats. The BackgroundSupervisor monitors these
 * heartbeats and flags a session as stalled if they stop arriving.
 *
 * Mirrors charmbracelet/charmcrush background session design.
 */
final class BackgroundSession
{
    /**
     * The most of a settled session's output {@see announcement()} quotes —
     * the size `tools.spillAboveChars` keeps inline for a tool result, which is
     * what a background result is to the agent that reads it.
     */
    public const ANNOUNCE_OUTPUT_MAX_CHARS = 16_000;

    /**
     * How every {@see announcement()} opens, and therefore how a queued
     * prompt is recognised as one ({@see isAnnouncement()}).
     */
    private const ANNOUNCEMENT_HEADER = '/\A\[Background session \S+ \(\'/';

    /** The tag prefix `/fork` marks a session's stored transcript copy with. */
    private const FORK_TAG_PREFIX = 'session:';

    /** @var list<string> User-defined labels for organization */
    public readonly array $tags;

    /** @var int Last heartbeat Unix timestamp */
    private int $lastHeartbeat;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Agent $agent,
        public readonly string $task,
        public readonly string $workingDirectory,
        public readonly int $timeoutSeconds = 3600,
        ?array $tags = null,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public readonly BackgroundSessionStatus $status = BackgroundSessionStatus::Pending,
        public readonly string $output = '',
    ) {
        $this->tags = $tags ?? [];
        $this->lastHeartbeat = time();
    }

    // =========================================================================
    // Status
    // =========================================================================

    /** @var int Tokens consumed so far */
    public int $tokensUsed = 0;

    /** @var float Estimated cost in USD */
    public float $costUsd = 0.0;

    /** @var \DateTimeImmutable|null When the session finished */
    public ?\DateTimeImmutable $completedAt = null;

    /** @var string|null Error message if the session failed */
    public ?string $error = null;

    /**
     * Update the session status.
     *
     * The first move into a settled status ({@see isSettled()}) stamps
     * {@see $completedAt}, so the runtime {@see announcement()} reports is the
     * session's own and not "until the next poll looked".
     */
    public function withStatus(BackgroundSessionStatus $status): self
    {
        $next = $this->mutate($status, $this->output);
        if ($next->completedAt === null && $next->isSettled()) {
            $next->completedAt = new \DateTimeImmutable();
        }

        return $next;
    }

    /**
     * Update the accumulated output.
     */
    public function withOutput(string $output): self
    {
        return $this->mutate($this->status, $output);
    }

    /**
     * The same session with what its turn cost: $tokens consumed and $costUsd
     * spent, as the daemon reported them ({@see BackgroundSessionRunner::USAGE_RECORD}).
     * Negative figures are clamped to zero.
     */
    public function withUsage(int $tokens, float $costUsd): self
    {
        $next = $this->mutate($this->status, $this->output);
        $next->tokensUsed = max(0, $tokens);
        $next->costUsd = max(0.0, $costUsd);

        return $next;
    }

    /**
     * The same session with $error as the reason it did not complete — the
     * `Error:` line {@see announcement()} prints. Null or '' clears it.
     */
    public function withError(?string $error): self
    {
        $next = $this->mutate($this->status, $this->output);
        $next->error = $error === '' ? null : $error;

        return $next;
    }

    /**
     * Build the successor instance for a with*() call.
     *
     * The constructor stamps $lastHeartbeat with time(), so a bare `new self`
     * forged a fresh heartbeat on every status change: the supervisor marked a
     * silent session Stalled, that very act reset its heartbeat clock, and the
     * next tick saw a "healthy" session and announced it Running again —
     * flapping stalled/running notices into the transcript forever. Carrying
     * the real heartbeat across means stalled → running only fires when a
     * heartbeat genuinely arrived.
     */
    private function mutate(BackgroundSessionStatus $status, string $output): self
    {
        $clone = new self(
            id: $this->id,
            name: $this->name,
            agent: $this->agent,
            task: $this->task,
            workingDirectory: $this->workingDirectory,
            timeoutSeconds: $this->timeoutSeconds,
            tags: $this->tags,
            createdAt: $this->createdAt,
            status: $status,
            output: $output,
        );
        $clone->lastHeartbeat = $this->lastHeartbeat;
        // The mutable figures ride across too: a status change that dropped
        // them would announce a finished session as having cost nothing, run
        // for no time and failed for no reason.
        $clone->tokensUsed = $this->tokensUsed;
        $clone->costUsd = $this->costUsd;
        $clone->completedAt = $this->completedAt;
        $clone->error = $this->error;

        return $clone;
    }

    /**
     * Record a heartbeat from this session.
     */
    public function recordHeartbeat(): void
    {
        $this->lastHeartbeat = time();
    }

    /**
     * Return true when heartbeats have stopped arriving for longer than the
     * acceptable window. A stalled session is not immediately killed — background
     * sessions are allowed to run longer than foreground ones.
     *
     * Uses the same heartbeat timeout contract as the Phase 1 worker pool:
     * the session is marked stalled when the gap between the last heartbeat
     * and now exceeds BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS.
     */
    public function isStalled(int $heartbeatTimeoutSecs): bool
    {
        return (time() - $this->lastHeartbeat) > $heartbeatTimeoutSecs;
    }

    /**
     * Seconds since the last heartbeat was received.
     */
    public function secondsSinceLastHeartbeat(): int
    {
        return time() - $this->lastHeartbeat;
    }

    // =========================================================================
    // Queries
    // =========================================================================

    public function isRunning(): bool
    {
        return $this->status === BackgroundSessionStatus::Running
            || $this->status === BackgroundSessionStatus::Streaming;
    }

    public function isActive(): bool
    {
        return $this->status !== BackgroundSessionStatus::Completed
            && $this->status !== BackgroundSessionStatus::Failed
            && $this->status !== BackgroundSessionStatus::Stopped;
    }

    public function isComplete(): bool
    {
        return $this->status === BackgroundSessionStatus::Completed;
    }

    public function isFailure(): bool
    {
        return $this->error !== null
            || $this->status === BackgroundSessionStatus::Failed
            || $this->status === BackgroundSessionStatus::Stopped;
    }

    /**
     * Whether the session has reached a final status — it will not run again.
     *
     * Unlike {@see isActive()} this counts TimedOut as settled: a session past
     * its deadline is over, whatever the poll does with it next.
     */
    public function isSettled(): bool
    {
        return match ($this->status) {
            BackgroundSessionStatus::Completed,
            BackgroundSessionStatus::Failed,
            BackgroundSessionStatus::Stopped,
            BackgroundSessionStatus::TimedOut => true,
            default => false,
        };
    }

    /**
     * The `/fork` transcript copy this session continues, or null for a plain
     * `/bg` session (which keeps no transcript of its own).
     */
    public function forkedSessionId(): ?string
    {
        foreach ($this->tags as $tag) {
            if (is_string($tag) && str_starts_with($tag, self::FORK_TAG_PREFIX) && strlen($tag) > strlen(self::FORK_TAG_PREFIX)) {
                return substr($tag, strlen(self::FORK_TAG_PREFIX));
            }
        }

        return null;
    }

    /**
     * Seconds the session ran: created to settled, or to now while it runs.
     */
    public function runtimeSeconds(): int
    {
        $end = $this->completedAt?->getTimestamp() ?? time();

        return max(0, $end - $this->createdAt->getTimestamp());
    }

    /**
     * The agent-visible report of a settled session (roadmap 4.3-1): what it
     * was asked, how it ended, what it answered, and the figures beside it.
     *
     * `Chat` sends this as a user-role turn, so the model that started the work
     * reads its result instead of the user having to paste it. Hence the shape:
     * a bracketed header naming the session and its outcome, the task, the
     * answer verbatim (bounded by $maxOutputChars, with the clip announced so
     * a cut answer never reads as a complete one), and one stats line.
     *
     * The stats line lists only what is known: tokens and cost appear once
     * the daemon reports them, and the resume pointer only for a `/fork`
     * session, whose stored transcript is the one thing there is to resume.
     */
    public function announcement(int $maxOutputChars = self::ANNOUNCE_OUTPUT_MAX_CHARS): string
    {
        $outcome = match ($this->status) {
            BackgroundSessionStatus::Completed => 'completed',
            BackgroundSessionStatus::Failed => 'failed',
            BackgroundSessionStatus::Stopped => 'was stopped',
            BackgroundSessionStatus::TimedOut => 'timed out',
            default => 'is ' . $this->status->value,
        };

        $lines = [
            sprintf("[Background session %s ('%s') %s]", $this->id, $this->name, $outcome),
            'Task: ' . $this->task,
        ];
        if ($this->error !== null && $this->error !== '') {
            $lines[] = 'Error: ' . $this->error;
        }

        $output = rtrim($this->output);
        if ($output === '') {
            $lines[] = 'Output: (none)';
        } else {
            $length = mb_strlen($output, 'UTF-8');
            if ($length > $maxOutputChars) {
                $output = mb_substr($output, 0, max(0, $maxOutputChars), 'UTF-8')
                    . sprintf("\n[… %d more characters of output not shown]", $length - max(0, $maxOutputChars));
            }
            $lines[] = "Output:\n" . $output;
        }

        $stats = ['runtime ' . self::durationDisplay($this->runtimeSeconds())];
        if ($this->tokensUsed > 0) {
            $stats[] = $this->usageDisplay();
        }
        if ($this->costUsd > 0.0) {
            $stats[] = sprintf('$%.4f', $this->costUsd);
        }
        $fork = $this->forkedSessionId();
        if ($fork !== null) {
            $stats[] = 'resume: sugarcrush --resume ' . $fork;
        }
        $lines[] = 'Stats: ' . implode(' · ', $stats);

        return implode("\n", $lines);
    }

    /**
     * Whether $text is one or more {@see announcement()}s — the prompt a host
     * sends when background sessions settle — rather than something a person
     * typed.
     *
     * A host asks so that it can refuse to read `@path` / `@url` mentions out
     * of it (W4-i handoff): the announcement quotes a daemon's output
     * verbatim, and an `@src/x.php` or `@https://…` token in a model's answer
     * is not the user asking for that file or page to be attached. Erring the
     * other way is harmless — a typed prompt that happens to open with this
     * exact header only loses its own mentions.
     */
    public static function isAnnouncement(string $text): bool
    {
        return preg_match(self::ANNOUNCEMENT_HEADER, ltrim($text)) === 1;
    }

    /**
     * "45s", "2m 30s", "1h 5m" — {@see elapsedDisplay()}'s units, without its
     * trailing space on a whole minute or hour.
     */
    private static function durationDisplay(int $secs): string
    {
        if ($secs < 60) {
            return "{$secs}s";
        }
        $mins = intdiv($secs, 60);
        if ($mins < 60) {
            return trim("{$mins}m " . ($secs % 60 > 0 ? ($secs % 60) . 's' : ''));
        }

        return trim(intdiv($mins, 60) . 'h ' . ($mins % 60 > 0 ? ($mins % 60) . 'm' : ''));
    }

    /**
     * Elapsed time in seconds since the session was created.
     */
    public function elapsedSeconds(): int
    {
        return time() - $this->createdAt->getTimestamp();
    }

    /**
     * Human-readable elapsed time string (e.g. "2m 30s").
     */
    public function elapsedDisplay(): string
    {
        $secs = $this->elapsedSeconds();
        if ($secs < 60) {
            return "{$secs}s";
        }
        $mins = intdiv($secs, 60);
        $remainderSecs = $secs % 60;
        if ($mins < 60) {
            return "{$mins}m " . ($remainderSecs > 0 ? "{$remainderSecs}s" : '');
        }
        $hours = intdiv($mins, 60);
        $remainderMins = $mins % 60;
        return "{$hours}h " . ($remainderMins > 0 ? "{$remainderMins}m" : '');
    }

    /**
     * Token usage display string.
     */
    public function usageDisplay(): string
    {
        if ($this->tokensUsed === 0) {
            return '';
        }
        return \SugarCraft\Crush\Util\TokenCount::compact($this->tokensUsed) . ' tokens';
    }

    /**
     * Build a complete AgentResult from the final session state.
     */
    public function toAgentResult(): AgentResult
    {
        $status = match ($this->status) {
            BackgroundSessionStatus::Completed => AgentStatus::Completed,
            BackgroundSessionStatus::Failed => AgentStatus::Failed,
            BackgroundSessionStatus::Stopped => AgentStatus::Stopped,
            BackgroundSessionStatus::TimedOut => AgentStatus::TimedOut,
            default => AgentStatus::Running,
        };

        return new AgentResult(
            agentId: $this->id,
            status: $status,
            output: $this->output ?: null,
            error: $this->error !== null ? new \RuntimeException($this->error) : null,
            tokensUsed: $this->tokensUsed,
            costUsd: $this->costUsd,
            startedAt: $this->createdAt,
            completedAt: $this->completedAt,
        );
    }

    /**
     * Convert to an array for serialization/persistence.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tags' => $this->tags,
            'agent' => [
                'name' => $this->agent->name,
                'model' => $this->agent->model,
            ],
            'task' => $this->task,
            'working_directory' => $this->workingDirectory,
            'timeout_seconds' => $this->timeoutSeconds,
            'status' => $this->status->value,
            'output' => $this->output,
            'tokens_used' => $this->tokensUsed,
            'cost_usd' => $this->costUsd,
            'error' => $this->error,
            'created_at' => $this->createdAt->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
            'last_heartbeat' => $this->lastHeartbeat,
        ];
    }
}

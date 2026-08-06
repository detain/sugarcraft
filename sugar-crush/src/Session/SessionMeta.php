<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

/**
 * Enhanced session metadata for better resumption.
 *
 * Tracks session summary, tasks, modified files, and agent states
 * to enable meaningful session resumption and context replay.
 *
 * All values are immutable after construction — use with*() methods
 * to produce derived instances.
 */
final readonly class SessionMeta
{
    /**
     * @param string               $sessionId      Unique session identifier.
     * @param string               $summary        Human-readable summary of the session's current state.
     * @param array                $tasks          List of active tasks in the session.
     * @param array                $modifiedFiles  List of files modified during the session.
     * @param array                $agentStates    Map of agent id to agent state snapshot.
     * @param \DateTimeImmutable   $lastActivity   Timestamp of the most recent activity.
     */
    public function __construct(
        public string $sessionId,
        public string $summary,
        public array $tasks,
        public array $modifiedFiles,
        public array $agentStates,
        public \DateTimeImmutable $lastActivity,
    ) {}

    /**
     * Factory creating a SessionMeta with all fields including timestamps set to now.
     *
     * @param string               $sessionId     Unique session identifier.
     * @param string               $summary       Human-readable summary (default: empty string).
     * @param array                $tasks         List of active tasks (default: empty array).
     * @param array                $modifiedFiles List of modified files (default: empty array).
     * @param array                $agentStates   Map of agent id to state (default: empty array).
     * @param \DateTimeImmutable|null $lastActivity Timestamp (default: now).
     */
    public static function new(
        string $sessionId,
        string $summary = '',
        array $tasks = [],
        array $modifiedFiles = [],
        array $agentStates = [],
        ?\DateTimeImmutable $lastActivity = null,
    ): self {
        return new self(
            sessionId: $sessionId,
            summary: $summary,
            tasks: $tasks,
            modifiedFiles: $modifiedFiles,
            agentStates: $agentStates,
            lastActivity: $lastActivity ?? new \DateTimeImmutable(),
        );
    }

    /**
     * Create a new instance with a different summary value.
     */
    public function withSummary(string $summary): self
    {
        return new self(
            sessionId: $this->sessionId,
            summary: $summary,
            tasks: $this->tasks,
            modifiedFiles: $this->modifiedFiles,
            agentStates: $this->agentStates,
            lastActivity: $this->lastActivity,
        );
    }

    /**
     * Create a new instance with a different tasks value.
     */
    public function withTasks(array $tasks): self
    {
        return new self(
            sessionId: $this->sessionId,
            summary: $this->summary,
            tasks: $tasks,
            modifiedFiles: $this->modifiedFiles,
            agentStates: $this->agentStates,
            lastActivity: $this->lastActivity,
        );
    }

    /**
     * Create a new instance with a different modifiedFiles value.
     */
    public function withModifiedFiles(array $modifiedFiles): self
    {
        return new self(
            sessionId: $this->sessionId,
            summary: $this->summary,
            tasks: $this->tasks,
            modifiedFiles: $modifiedFiles,
            agentStates: $this->agentStates,
            lastActivity: $this->lastActivity,
        );
    }

    /**
     * Create a new instance with a different agentStates value.
     */
    public function withAgentStates(array $agentStates): self
    {
        return new self(
            sessionId: $this->sessionId,
            summary: $this->summary,
            tasks: $this->tasks,
            modifiedFiles: $this->modifiedFiles,
            agentStates: $agentStates,
            lastActivity: $this->lastActivity,
        );
    }

    /**
     * Create a new instance with a different lastActivity value.
     */
    public function withLastActivity(\DateTimeImmutable $lastActivity): self
    {
        return new self(
            sessionId: $this->sessionId,
            summary: $this->summary,
            tasks: $this->tasks,
            modifiedFiles: $this->modifiedFiles,
            agentStates: $this->agentStates,
            lastActivity: $lastActivity,
        );
    }

    /**
     * Serialize the session metadata to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'summary' => $this->summary,
            'tasks' => $this->tasks,
            'modifiedFiles' => $this->modifiedFiles,
            'agentStates' => $this->agentStates,
            'lastActivity' => $this->lastActivity->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Reconstruct a SessionMeta instance from an array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: $data['sessionId'],
            summary: $data['summary'] ?? '',
            tasks: $data['tasks'] ?? [],
            modifiedFiles: $data['modifiedFiles'] ?? [],
            agentStates: $data['agentStates'] ?? [],
            lastActivity: new \DateTimeImmutable($data['lastActivity'] ?? null),
        );
    }
}

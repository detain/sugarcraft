<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Board;

/**
 * One participant of a {@see Board}: a member of one parallel `Task` batch.
 *
 * `agent` is the roster name the call asked for and `task` the call's short
 * description; both come from the call's own arguments
 * ({@see \SugarCraft\Crush\Tools\SharesBoard::boardMember()}). `id` is what
 * peers address a post to. {@see Board::create()} assigns it as
 * `<agent>-<n>`, n being the member's 1-based place in the batch, so two
 * members running the same agent stay distinct and a model can tell from the
 * id alone which of them it is talking to.
 */
final readonly class BoardMember
{
    private function __construct(
        public string $id,
        public string $agent,
        public string $task,
    ) {}

    /** A member not yet seated on a board: its id is assigned by {@see Board::create()}. */
    public static function new(string $agent, string $task): self
    {
        return new self('', $agent, $task);
    }

    /** The same member, addressed as $id. */
    public function withId(string $id): self
    {
        return new self($id, $this->agent, $this->task);
    }

    /**
     * @return array{id: string, agent: string, task: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'agent' => $this->agent, 'task' => $this->task];
    }
}

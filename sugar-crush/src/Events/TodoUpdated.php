<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Todo\TodoList;

/**
 * The session's todo list was rewritten by a `Todo` call (roadmap 3.C).
 *
 * Built in the PARENT, from the call's `finished` frame: the tool runs in the
 * turn's forked child, where anything it kept would die with the process, so
 * the list crosses the fork as the rendering the tool returned
 * ({@see TodoList::parse()}) — the input as it actually ran, after any
 * PreToolUse hook rewrote it, which the `started` frame's raw arguments are
 * not (E16). {@see \SugarCraft\Crush\Host\TurnRunner} turns that frame into
 * this event, keeps the list as the session's and saves it to
 * {@see \SugarCraft\Crush\Session\SessionMeta::$tasks}.
 */
final readonly class TodoUpdated
{
    public function __construct(
        public string $toolCallId,
        public TodoList $todos,
        public ?string $sessionId = null,
    ) {
    }

    /**
     * @return array{toolCallId: string, sessionId: ?string, todos: list<array{content: string, status: string}>}
     */
    public function toArray(): array
    {
        return [
            'toolCallId' => $this->toolCallId,
            'sessionId' => $this->sessionId,
            'todos' => $this->todos->toArray(),
        ];
    }
}

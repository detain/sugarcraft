<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

/**
 * Several {@see TurnInbox}es read as one (roadmap 1.C-3): drained in the
 * order given, pending when any member is.
 *
 * A turn can have more than one source of mid-turn messages — the user's
 * `steer` frames ({@see SocketSteerInbox}) and, for a sub-agent, its mailbox
 * ({@see MailboxTurnInbox}, P-D1) — and the step loop drains exactly one inbox.
 */
final class CompositeTurnInbox implements TurnInbox
{
    /** @param list<TurnInbox> $inboxes */
    private function __construct(private readonly array $inboxes)
    {
    }

    /**
     * The members that are present, as one inbox — or the single member
     * itself, or null when none is. Nulls are skipped so a caller can pass
     * an optional source without branching.
     */
    public static function of(?TurnInbox ...$inboxes): ?TurnInbox
    {
        $present = array_values(array_filter($inboxes, static fn (?TurnInbox $inbox): bool => $inbox !== null));

        return match (count($present)) {
            0 => null,
            1 => $present[0],
            default => new self($present),
        };
    }

    public function drain(int $step): array
    {
        $messages = [];
        foreach ($this->inboxes as $inbox) {
            array_push($messages, ...$inbox->drain($step));
        }

        return $messages;
    }

    public function pending(): bool
    {
        foreach ($this->inboxes as $inbox) {
            if ($inbox->pending()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<TurnInbox> */
    public function inboxes(): array
    {
        return $this->inboxes;
    }
}

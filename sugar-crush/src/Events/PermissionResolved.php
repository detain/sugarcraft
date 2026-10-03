<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

use SugarCraft\Crush\Permissions\PermissionReply;

/**
 * "The question a {@see PermissionAsked} put has been settled" — by an
 * answer, or by nobody being left to give one.
 *
 * Emitted on the same ordered `$onEvent` channel as the ask itself (Appendix
 * O §5.1), so a consumer that drew a modal for the ask has a matching fact to
 * take it down on, whoever settled it: the user's own reply, another client's
 * reply (server mode, §6.7), or the turn ending underneath an open question.
 *
 * `cancelled` is the last case and is kept apart from a reject on purpose.
 * A reject is a decision someone made about THIS call; a cancellation is the
 * turn going away (Escape, the idle ceiling, the parent's end of the socket
 * closing) with the question still open. They are different refusals —
 * {@see \SugarCraft\Crush\Permissions\DenialKind::Refused} versus
 * {@see \SugarCraft\Crush\Permissions\DenialKind::Unanswered} — and a
 * consumer that folds them together re-reports a hang-up as a "no".
 */
final readonly class PermissionResolved
{
    /**
     * @param string           $askId     the {@see \SugarCraft\Crush\Backend\PendingAsk::$askId} this settles
     * @param ?PermissionReply $reply     the answer; null exactly when $cancelled
     * @param string           $note      on a reject, the feedback the model is meant
     *                                    to read; on a cancellation, why nobody
     *                                    answered. Clipped to
     *                                    {@see \SugarCraft\Crush\Backend\ChildChannel::MAX_NOTE_BYTES}.
     * @param bool             $cancelled true when nobody answered
     */
    public function __construct(
        public string $askId,
        public ?PermissionReply $reply,
        public string $note = '',
        public bool $cancelled = false,
    ) {
    }

    public static function replied(string $askId, PermissionReply $reply, string $note = ''): self
    {
        return new self($askId, $reply, $note);
    }

    public static function cancelled(string $askId, string $note = ''): self
    {
        return new self($askId, null, $note, true);
    }

    /**
     * True only for an actual `once`/`always` answer. A cancellation never
     * permits, which is the fail-closed half of "nobody answered".
     */
    public function permits(): bool
    {
        return !$this->cancelled && $this->reply?->permits() === true;
    }
}

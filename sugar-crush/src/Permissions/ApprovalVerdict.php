<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Events\PermissionResolved;

/**
 * What an approver answers about one ASK (roadmap 1.C-2): allow once, allow
 * always, refuse — or nobody answered at all — plus the text the MODEL reads
 * about it.
 *
 * WHY NOT `bool`, which is what {@see \SugarCraft\Crush\Runtime::settleAsk()}
 * took until this step. One bit cannot carry the two facts the frame channel
 * (1.C-1) already delivers to the turn child:
 *
 * - **Who refused.** A reply of `reject` is somebody's decision about this
 *   call ({@see DenialKind::Refused}, `Permission denied:`). A question the
 *   turn ended underneath — the parent's end of the socket gone, a corrupt
 *   stream — is not a decision at all ({@see DenialKind::Unanswered},
 *   `Permission required:`). Folded into `false`, the second used to reach the
 *   model and every refusal surface as the first.
 * - **Why.** A rejection can carry feedback ("use rg instead"), and the
 *   grandchild of a parallel Task batch is refused with a reason
 *   ({@see ChildChannel::GRANDCHILD_REFUSAL}). Both reach the model through
 *   {@see \SugarCraft\Crush\Hooks\HookManager::resolveAsk()}'s `$feedback`,
 *   which existed and had no producer.
 *
 * The approver contract stays BACKWARD COMPATIBLE: {@see of()} reads a
 * literal `true` as {@see once()} and anything else that is not a verdict as
 * a feedback-less {@see reject()}, so a `bool` approver (the console prompt,
 * an embedder's closure) settles exactly as it always did — and a truthy
 * object that is not a verdict (a {@see PermissionReply}, which every case of
 * is) still grants nothing.
 */
final readonly class ApprovalVerdict
{
    /**
     * @param ?PermissionReply $reply    the answer; null exactly when nobody answered
     * @param string           $feedback model-visible text about a refusal — a
     *                                   user's note (already labelled as theirs)
     *                                   or the reason nobody could answer; empty
     *                                   when there is nothing to add. Never shown
     *                                   on a permitting verdict.
     */
    private function __construct(
        public ?PermissionReply $reply,
        public string $feedback = '',
    ) {
    }

    /** Permit this one call. */
    public static function once(): self
    {
        return new self(PermissionReply::Once);
    }

    /** Permit this call and, where the asker allows it, the same question again. */
    public static function always(): self
    {
        return new self(PermissionReply::Always);
    }

    /**
     * Refuse the call. $reason is model-visible as it stands — use
     * {@see rejectedByUser()} for a note a person typed.
     */
    public static function reject(string $reason = ''): self
    {
        return new self(PermissionReply::Reject, trim($reason));
    }

    /**
     * Refuse the call with the user's own feedback, labelled as theirs so the
     * model does not read a person's instruction as the harness's.
     */
    public static function rejectedByUser(string $note): self
    {
        $note = trim($note);

        return new self(PermissionReply::Reject, $note === '' ? '' : 'the user said: ' . $note);
    }

    /** Nobody answered — the question outlived whoever could have. */
    public static function unanswered(string $reason = ''): self
    {
        return new self(null, trim($reason));
    }

    /**
     * A {@see PermissionReply} with its optional note, as a person gave it.
     */
    public static function fromReply(PermissionReply $reply, string $note = ''): self
    {
        return match ($reply) {
            PermissionReply::Once => self::once(),
            PermissionReply::Always => self::always(),
            PermissionReply::Reject => self::rejectedByUser($note),
        };
    }

    /**
     * The settlement the frame channel delivered to the turn child.
     *
     * The note on a `reject` is the user's, EXCEPT the one refusal the child
     * writes itself — a parallel Task grandchild that has no channel of its
     * own — which is a reason, not a person speaking.
     */
    public static function fromResolution(PermissionResolved $resolution): self
    {
        if ($resolution->cancelled || $resolution->reply === null) {
            return self::unanswered($resolution->note);
        }

        if ($resolution->reply === PermissionReply::Reject && $resolution->note === ChildChannel::GRANDCHILD_REFUSAL) {
            return self::reject($resolution->note);
        }

        return self::fromReply($resolution->reply, $resolution->note);
    }

    /**
     * Normalise whatever an approver returned. Only a literal `true` or a
     * permitting verdict grants — see the class docblock.
     */
    public static function of(mixed $answer): self
    {
        if ($answer instanceof self) {
            return $answer;
        }

        return $answer === true ? self::once() : self::reject();
    }

    public function permits(): bool
    {
        return $this->reply?->permits() === true;
    }

    public function isUnanswered(): bool
    {
        return $this->reply === null;
    }

    /**
     * The denial kind this verdict produces, or null when it permits.
     */
    public function denialKind(): ?DenialKind
    {
        if ($this->permits()) {
            return null;
        }

        return $this->isUnanswered() ? DenialKind::Unanswered : DenialKind::Refused;
    }

    /**
     * The refusal text the model reads: the hook's question, then this
     * verdict's feedback when there is any.
     */
    public function denialMessage(string $question): string
    {
        if ($this->feedback === '') {
            return $question;
        }

        return $question === '' ? $this->feedback : $question . ' — ' . $this->feedback;
    }
}

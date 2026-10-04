<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

/**
 * One message to a running sub-agent (roadmap P-D1, Appendix P §5.3–5.4):
 * who sent it, how it is delivered, what it says, and — for a message from
 * the user — the HMAC that proves the user's side of the harness wrote it.
 *
 * `from` is one of three senders, and the sender decides the message's
 * authority when it reaches the agent ({@see \SugarCraft\Crush\Backend\MailboxTurnInbox::frame()}):
 *  - {@see FROM_USER}: the person, through the Agent View composer or a
 *    server's authenticated user channel. Carries user authority, so it is
 *    accepted only with a valid {@see $hmac} ({@see AgentInbox}).
 *  - {@see FROM_PARENT}: the agent that delegated the run (roadmap 4.4's
 *    `SendMessage`). Task direction, never user approval.
 *  - `agent:<id>` ({@see FROM_AGENT_PREFIX}): a sibling agent. Same authority
 *    as the parent's.
 *
 * Immutable; {@see toArray()} / {@see fromArray()} are the mailbox line's
 * payload, and {@see fromArray()} answers null for anything that is not one,
 * because a mailbox file is writable by any process of the user.
 */
final class AgentMessage
{
    public const FROM_USER = 'user';
    public const FROM_PARENT = 'parent';
    public const FROM_AGENT_PREFIX = 'agent:';

    /** The longest text one message may carry; longer text is refused on send and dropped on read. */
    public const MAX_TEXT_BYTES = 16384;

    private const FROM_PATTERN = '/^(?:user|parent|agent:[A-Za-z0-9._-]{1,128})$/';
    private const ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    private function __construct(
        public readonly string $msgId,
        public readonly string $from,
        public readonly MessageMode $mode,
        public readonly string $text,
        /** When it was sent, in Unix milliseconds (an integer, so it signs byte-identically after a JSON round trip). */
        public readonly int $ts,
        /** Hex HMAC-SHA256 over {@see signingPayload()}, set by {@see AgentInbox::send()} on a user message. */
        public readonly ?string $hmac = null,
    ) {
    }

    /**
     * A new, unsigned message. `$msgId` and `$ts` default to a fresh id and
     * now; a caller passes them only to rebuild a known message.
     *
     * @throws \InvalidArgumentException for an unknown sender, an invalid id,
     *                                   or text that is empty or over {@see MAX_TEXT_BYTES}
     */
    public static function new(string $from, string $text, MessageMode $mode = MessageMode::Steer, ?string $msgId = null, ?int $ts = null): self
    {
        $msgId ??= 'am_' . bin2hex(random_bytes(8));
        if (preg_match(self::FROM_PATTERN, $from) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a message sender: use "user", "parent" or "agent:<id>"', $from));
        }
        if (preg_match(self::ID_PATTERN, $msgId) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a message id', $msgId));
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('a message to an agent needs text');
        }
        if (\strlen($text) > self::MAX_TEXT_BYTES) {
            throw new \InvalidArgumentException(sprintf('a message to an agent is at most %d bytes', self::MAX_TEXT_BYTES));
        }

        return new self($msgId, $from, $mode, $text, $ts ?? (int) floor(microtime(true) * 1000));
    }

    /** A message from the user (signed when it is sent). */
    public static function fromUser(string $text, MessageMode $mode = MessageMode::Steer): self
    {
        return self::new(self::FROM_USER, $text, $mode);
    }

    /** A message from the agent that delegated the run. */
    public static function fromParent(string $text, MessageMode $mode = MessageMode::Steer): self
    {
        return self::new(self::FROM_PARENT, $text, $mode);
    }

    public function withHmac(?string $hmac): self
    {
        return new self($this->msgId, $this->from, $this->mode, $this->text, $this->ts, $hmac);
    }

    public function isFromUser(): bool
    {
        return $this->from === self::FROM_USER;
    }

    /**
     * Who to name in the agent's framing: `main` for the parent, the sibling's
     * id for another agent, `user` for the person.
     */
    public function senderName(): string
    {
        return match (true) {
            $this->from === self::FROM_PARENT => 'main',
            str_starts_with($this->from, self::FROM_AGENT_PREFIX) => substr($this->from, \strlen(self::FROM_AGENT_PREFIX)),
            default => $this->from,
        };
    }

    /**
     * The bytes the HMAC covers: every field but the HMAC, plus the RECIPIENT,
     * so a signed line copied into another agent's mailbox does not verify.
     */
    public function signingPayload(string $agentId): string
    {
        return json_encode(
            ['sugarcrush.agent-inbox.v1', $agentId, $this->msgId, $this->from, $this->mode->value, $this->ts, $this->text],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * @return array{msgId: string, from: string, mode: string, text: string, ts: int, hmac: ?string}
     */
    public function toArray(): array
    {
        return [
            'msgId' => $this->msgId,
            'from' => $this->from,
            'mode' => $this->mode->value,
            'text' => $this->text,
            'ts' => $this->ts,
            'hmac' => $this->hmac,
        ];
    }

    /**
     * The message a mailbox payload describes, or null when it is not a
     * well-formed one — an unknown sender or mode, a missing field, a wrong
     * type, text out of bounds. Validity of the HMAC is {@see AgentInbox}'s
     * check, not this one's.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $hmac = $data['hmac'] ?? null;
        if (!\is_string($data['msgId'] ?? null)
            || !\is_string($data['from'] ?? null)
            || !\is_string($data['mode'] ?? null)
            || !\is_string($data['text'] ?? null)
            || !\is_int($data['ts'] ?? null)
            || ($hmac !== null && (!\is_string($hmac) || preg_match('/^[0-9a-f]{64}$/', $hmac) !== 1))
        ) {
            return null;
        }
        $mode = MessageMode::tryFrom($data['mode']);
        if ($mode === null) {
            return null;
        }

        try {
            return self::new($data['from'], $data['text'], $mode, $data['msgId'], $data['ts'])->withHmac($hmac);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}

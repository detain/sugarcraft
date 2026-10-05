<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\TeamMessage;

/**
 * Messages to running sub-agents, over the {@see Mailbox} (roadmap P-D1,
 * Appendix P §5.3–5.4).
 *
 * WHY THE MAILBOX. A message is a file line, and the sub-agent reads it at its
 * own step boundaries ({@see \SugarCraft\Crush\Backend\MailboxTurnInbox}). No
 * socket hop is needed, so delivery works the same for a lone Task running in
 * the turn child, a parallel member in its grandchild, and a later resume of
 * the same conversation. Messages arrive at human rate, so one `stat` per step
 * is the whole polling cost. The {@see Mailbox} is teammate-shaped
 * (`TeamMessage`, keyed by teammate id), so this class wraps an
 * {@see AgentMessage} in a `TeamMessage` of type {@see MESSAGE_TYPE} rather
 * than widening the team type.
 *
 * WHERE: `~/.sugar-crush/mailboxes/<session>/<agentId>/inbox.jsonl`, the
 * directories `0700` and the files `0600`. `~` is the same owned home the
 * sub-agent transcript logs use ({@see defaultRoot()}), so a run with no
 * owned home keeps no mailbox.
 *
 * WHY AN HMAC. The directory is the user's, so any process the user runs can
 * append to it — including a Bash command a prompt injection talked the agent
 * into. A `from:'user'` message carries user authority ("go ahead and edit
 * X"), so it is accepted only when it carries an HMAC under the launch's key
 * ({@see launchKey()}). That key is minted once per process, in the parent,
 * before any turn forks ({@see \SugarCraft\Crush\Host\WorkspaceContext::new()}
 * pins it), so the forked turn child and its grandchildren inherit it in memory.
 * It never goes into the environment or a file, so a subprocess cannot read it.
 * A line that fails the check is DROPPED and reported, never delivered as a
 * lesser message. Parent and agent messages are unsigned. They carry no user
 * authority, and the framing says so.
 */
final class AgentInbox
{
    /** The directory under `~/.sugar-crush` every session's mailboxes live in. */
    public const DIR_NAME = 'mailboxes';

    /** The {@see TeamMessage::$type} an agent message travels as. */
    public const MESSAGE_TYPE = 'agent_message';

    private const AGENT_ID_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    /**
     * The verbs every surface may send (roadmap P-D3 acts on them) — the web
     * protocol's `agent.control` enum is derived from this list.
     */
    public const CONTROL_VERBS = ['cancel', 'pause', 'resume'];

    /**
     * Promote the running run to a background session (roadmap P-E3,
     * `Ctrl+X b` in the Agent View). {@see control()} sends it like the
     * others, but it is kept out of {@see CONTROL_VERBS}: that list is the
     * web protocol's wire enum, and the web client does not offer it yet.
     */
    public const BACKGROUND_VERB = 'background';

    private static ?string $launchKey = null;

    private function __construct(
        private readonly Mailbox $mailbox,
        #[\SensitiveParameter]
        private readonly ?string $key,
    ) {
    }

    /**
     * An inbox over $mailbox. `$key` signs and verifies `from:'user'`
     * messages; with no key, user messages can be neither sent nor accepted.
     */
    public static function new(Mailbox $mailbox, #[\SensitiveParameter] ?string $key = null): self
    {
        return new self($mailbox, $key === '' ? null : $key);
    }

    /**
     * The inbox of session $sessionId's sub-agents, below $root (default
     * {@see defaultRoot()}), signing with $key (default {@see launchKey()}).
     * Null when there is no root to keep it under.
     */
    public static function forSession(string $sessionId, #[\SensitiveParameter] ?string $key = null, ?string $root = null): ?self
    {
        $root ??= self::defaultRoot();
        if ($root === null) {
            return null;
        }

        return new self(new Mailbox(rtrim($root, '/') . '/' . self::segment($sessionId)), $key ?? self::launchKey());
    }

    /**
     * `~/.sugar-crush/mailboxes`, beside the sub-agent transcript logs:
     * derived from {@see SubAgentTranscriptLog::defaultRoot()}, so it is under
     * the same owned home, or the test bootstrap's sandbox when that is pinned.
     * Null when there is no owned home.
     */
    public static function defaultRoot(): ?string
    {
        $logs = SubAgentTranscriptLog::defaultRoot();

        return $logs === null ? null : \dirname($logs) . '/' . self::DIR_NAME;
    }

    /**
     * This launch's message key: 32 random bytes, minted on the first call
     * and then fixed for the life of the process and every process forked
     * from it. See the class doc for why it must be minted in the parent.
     */
    public static function launchKey(): string
    {
        return self::$launchKey ??= random_bytes(32);
    }

    /**
     * Put $message in agent $agentId's mailbox, signed when it is from the
     * user. Answers the message as written.
     *
     * @throws \InvalidArgumentException for an id that cannot name a mailbox
     * @throws \LogicException            for a user message on an inbox with no key
     * @throws \RuntimeException          when the mailbox cannot be written
     */
    public function send(string $agentId, AgentMessage $message): AgentMessage
    {
        self::assertAgentId($agentId);
        if ($message->isFromUser()) {
            if ($this->key === null) {
                throw new \LogicException('this inbox has no key, so it cannot send a message from the user');
            }
            $message = $message->withHmac($this->sign($agentId, $message));
        } else {
            // Only the user's messages are signed; a stray HMAC on another
            // sender's message would only mislead a reader of the file.
            $message = $message->withHmac(null);
        }

        $this->mailbox->send($message->from, $agentId, new TeamMessage(
            id: $message->msgId,
            fromTeammateId: $message->from,
            toTeammateId: $agentId,
            type: self::MESSAGE_TYPE,
            payload: $message->toArray(),
            sentAt: (new \DateTimeImmutable('@' . intdiv($message->ts, 1000)))->setTimezone(new \DateTimeZone('UTC')),
        ));

        return $message;
    }

    /**
     * Send agent $agentId a harness verb ({@see CONTROL_VERBS}, or
     * {@see BACKGROUND_VERB}) as a
     * {@see MessageMode::Control} message. The agent's run reads it with
     * {@see takeControls()}, never as conversation text.
     *
     * @throws \InvalidArgumentException for an unknown verb or id
     */
    public function control(string $agentId, string $verb, string $from = AgentMessage::FROM_USER): AgentMessage
    {
        $verbs = [...self::CONTROL_VERBS, self::BACKGROUND_VERB];
        if (!\in_array($verb, $verbs, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an agent control verb (%s)', $verb, implode(', ', $verbs)));
        }

        return $this->send($agentId, AgentMessage::new($from, $verb, MessageMode::Control));
    }

    /**
     * Every unread message for $agentId that the running agent reads now
     * ({@see MessageMode::deliveredMidRun()}), oldest first, each handed out
     * once. Follow-ups and controls stay unread for their own readers
     * ({@see takeFollowups()}, {@see takeControls()}).
     *
     * A message that fails validation (a forged or tampered user message, a
     * malformed payload) is consumed and NOT returned. `$onRejected` is told
     * its id, its claimed sender and why.
     *
     * @param (\Closure(string $msgId, string $from, string $reason): void)|null $onRejected
     *
     * @return list<AgentMessage>
     */
    public function drain(string $agentId, ?\Closure $onRejected = null): array
    {
        return $this->take($agentId, static fn (MessageMode $mode): bool => $mode->deliveredMidRun(), $onRejected);
    }

    /**
     * Every unread {@see MessageMode::Control} message for $agentId, oldest
     * first, each handed out once and validated exactly as {@see drain()}
     * validates.
     *
     * @param (\Closure(string $msgId, string $from, string $reason): void)|null $onRejected
     *
     * @return list<AgentMessage>
     */
    public function takeControls(string $agentId, ?\Closure $onRejected = null): array
    {
        return $this->take($agentId, static fn (MessageMode $mode): bool => $mode === MessageMode::Control, $onRejected);
    }

    /**
     * Every unread {@see MessageMode::Followup} message for $agentId, oldest
     * first, each handed out once and validated exactly as {@see drain()}
     * validates (roadmap 4.4). A followup is for the conversation's NEXT run,
     * so the run it was sent to never reads it: the run that continues the
     * conversation does ({@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool} on a
     * resume), from the mailbox of every earlier run of that conversation.
     *
     * @param (\Closure(string $msgId, string $from, string $reason): void)|null $onRejected
     *
     * @return list<AgentMessage>
     */
    public function takeFollowups(string $agentId, ?\Closure $onRejected = null): array
    {
        return $this->take($agentId, static fn (MessageMode $mode): bool => $mode === MessageMode::Followup, $onRejected);
    }

    /**
     * Whether an unread, valid message for $agentId asks to skip the current
     * step's unstarted calls ({@see MessageMode::skipsUnstartedCalls()}).
     * It hands nothing out.
     */
    public function pending(string $agentId): bool
    {
        foreach ($this->unread($agentId) as [$message, $rejection]) {
            if ($message !== null && $rejection === null && $message->mode->skipsUnstartedCalls()) {
                return true;
            }
        }

        return false;
    }

    /** The size of $agentId's mailbox file in bytes (0 when it has none); a stat, not a read. */
    public function size(string $agentId): int
    {
        self::assertAgentId($agentId);

        return $this->mailbox->inboxSize($agentId);
    }

    /**
     * Why $message, read from $agentId's mailbox, must not be delivered, or
     * null when it may. A user message needs this inbox's key and an HMAC that
     * verifies under it; any other sender must not claim one.
     */
    public function rejection(string $agentId, AgentMessage $message): ?string
    {
        if (!$message->isFromUser()) {
            return null;
        }
        if ($this->key === null) {
            return 'a message from the user cannot be verified without the launch key';
        }
        if ($message->hmac === null) {
            return 'a message claiming to be from the user carried no signature';
        }

        return hash_equals($this->sign($agentId, $message), $message->hmac)
            ? null
            : 'a message claiming to be from the user failed its signature check';
    }

    /**
     * @param \Closure(MessageMode): bool $wanted
     * @param (\Closure(string, string, string): void)|null $onRejected
     *
     * @return list<AgentMessage>
     */
    private function take(string $agentId, \Closure $wanted, ?\Closure $onRejected): array
    {
        $taken = [];
        foreach ($this->unread($agentId) as [$message, $rejection, $team]) {
            if ($message !== null && $rejection === null && !$wanted($message->mode)) {
                continue;
            }
            // Consumed either way: a rejected line is reported once, not on
            // every step for the rest of the run.
            $this->mailbox->markRead($agentId, $team->id);
            if ($message === null || $rejection !== null) {
                if ($onRejected !== null) {
                    $onRejected($team->id, $team->fromTeammateId, $rejection ?? 'the message was malformed');
                }
                continue;
            }
            $taken[] = $message;
        }

        return $taken;
    }

    /**
     * Each unread agent message, decoded and checked, with the team message
     * it travelled as. `[null, reason, team]` for a payload that is not an
     * agent message or names another recipient.
     *
     * @return list<array{0: ?AgentMessage, 1: ?string, 2: TeamMessage}>
     */
    private function unread(string $agentId): array
    {
        self::assertAgentId($agentId);
        if ($this->mailbox->inboxSize($agentId) === 0) {
            return [];
        }

        $rows = [];
        foreach ($this->mailbox->receive($agentId) as $team) {
            if ($team->read || $team->type !== self::MESSAGE_TYPE) {
                continue;
            }
            $message = AgentMessage::fromArray($team->payload);
            if ($message === null) {
                $rows[] = [null, 'the message was malformed', $team];
                continue;
            }
            if ($team->toTeammateId !== $agentId || $team->id !== $message->msgId || $team->fromTeammateId !== $message->from) {
                $rows[] = [null, 'the message envelope did not match its payload', $team];
                continue;
            }
            $rows[] = [$message, $this->rejection($agentId, $message), $team];
        }

        return $rows;
    }

    private function sign(string $agentId, AgentMessage $message): string
    {
        return hash_hmac('sha256', $message->signingPayload($agentId), (string) $this->key);
    }

    private static function assertAgentId(string $agentId): void
    {
        if (preg_match(self::AGENT_ID_PATTERN, $agentId) !== 1 || $agentId === '.' || $agentId === '..') {
            throw new \InvalidArgumentException(sprintf('"%s" is not an agent id', $agentId));
        }
    }

    /** One path segment: anything outside `[A-Za-z0-9._-]` replaced, and never `.`/`..`. */
    private static function segment(string $id): string
    {
        $segment = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $id);

        return $segment === '' || $segment === '.' || $segment === '..' ? '_' : $segment;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Events\PermissionResolved;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * The turn child's half of the two-way frame channel (roadmap 1.C-1 =
 * Appendix O §5.1, frame vocabulary decision D1).
 *
 * {@see EngineBackend::completeAsync()} forks the turn and, until this class,
 * used the socketpair ONE way: the child wrote `token`/`reasoning`/`started`/
 * `finished`/`subagent`/`spend_cap`/`result` frames and the parent never wrote
 * at all. That is why an ASK raised inside a TUI turn could only be refused —
 * the question had no way out of the child and an answer had no way back in.
 *
 * Same framing in both directions (4-byte big-endian length + `serialize()`,
 * decoded with `allowed_classes => false`), so the parent's existing reader
 * needed new KINDS rather than a new codec:
 *
 * | Direction     | `kind`        | Fields                                                    |
 * |---------------|---------------|-----------------------------------------------------------|
 * | child→parent  | `ask`         | `askId`, `toolCallId`, `tool`, `arguments`, `reason`, `source`, `mode`, `suggestions`, `alwaysScope` |
 * | parent→child  | `ask_reply`   | `askId`, `reply` (`once`/`always`/`reject`), `note`       |
 * | parent→child  | `steer`       | `steerId`, `text`                                         |
 * | parent→child  | `cancel_soft` | —                                                         |
 * | parent→child  | `cancel_tool` | `callId`                                                  |
 * | child→parent  | `steer_ack`   | `steerId`, `step`                                         |
 * | child→parent  | `usage`       | `step`, `usage`                                           |
 * | child→parent  | `step`        | `step`, `maxSteps`                                        |
 *
 * This step builds the channel and the ask round trip. Steering (1.C-3) and
 * the usage/step/soft-cancel frames (1.C-4) consume the same seam: inbound
 * `steer`/`cancel_*` frames are already parsed and BUFFERED here (an ask
 * blocked on its reply must not drop a steer that overtook the reply), and
 * {@see send()} already writes the child→parent kinds.
 *
 * ## No deadline in the child
 *
 * {@see ask()} blocks on the socket for as long as it takes. The parent owns
 * the policy — it pauses its idle ceiling while a question is open, and tears
 * the turn down on cancel — and the parent going away shows up here as EOF,
 * which settles the question as unanswered (a refusal), never as a grant.
 *
 * ## Owned by one process
 *
 * A parallel Task sub-agent runs in a GRANDCHILD forked below the turn child,
 * and it inherits this object (through the approver the turn's engine copies
 * into the sub-engine) and the socket under it. Two processes writing one
 * stream interleave frames, which is the same reason the sub-agent emitter is
 * pid-bound in {@see EngineBackend}. So every write checks the pid it was
 * built in, and an ask from any other process is refused with
 * {@see GRANDCHILD_REFUSAL} until the per-job relay (1.C-5) gives it a
 * channel of its own.
 */
final class ChildChannel
{
    public const ASK = 'ask';
    public const ASK_REPLY = 'ask_reply';
    public const STEER = 'steer';
    public const STEER_ACK = 'steer_ack';
    public const CANCEL_SOFT = 'cancel_soft';
    public const CANCEL_TOOL = 'cancel_tool';
    public const USAGE = 'usage';
    public const STEP = 'step';

    /** The D1 kinds the child writes. */
    public const TO_PARENT = [self::ASK, self::STEER_ACK, self::USAGE, self::STEP];

    /** The D1 kinds the parent writes. */
    public const TO_CHILD = [self::ASK_REPLY, self::STEER, self::CANCEL_SOFT, self::CANCEL_TOOL];

    /** A reply's `note` and a cancellation's reason are clipped to this many bytes (§5.1). */
    public const MAX_NOTE_BYTES = 2048;

    public const GRANDCHILD_REFUSAL = 'approval from a parallel sub-agent is not yet supported; run it alone or allow it by rule';

    public const PARENT_GONE = 'the turn ended before the question was answered';

    /**
     * Consecutive failed `stream_select()` calls (EINTR and friends) tolerated
     * before the socket is treated as gone, so a select that fails for good
     * cannot spin the child forever.
     */
    private const MAX_SELECT_FAILURES = 100;

    private const MAX_HELD_REPLIES = 64;

    private string $inbound = '';

    private bool $closed = false;

    /** @var array<string, true> per-turn `always` grants, keyed by {@see grantKey()} */
    private array $grants = [];

    /** @var list<array{steerId: string, text: string}> */
    private array $steers = [];

    /** @var list<array<string, mixed>> buffered `cancel_soft` / `cancel_tool` frames */
    private array $controls = [];

    /**
     * @var array<string, array<string, mixed>> `ask_reply` frames that arrived
     *      for a question other than the one being waited on, by askId — kept,
     *      not dropped, so a read that happens to carry two replies cannot
     *      lose the second; bounded by {@see MAX_HELD_REPLIES}
     */
    private array $heldReplies = [];

    /**
     * @param resource                                              $socket      the child's end
     * @param \Closure(array<string, mixed>): void                  $writeFrame  writes one frame (and ends the
     *                                                                           process on a dead stream, as
     *                                                                           every other child write does)
     * @param \Closure(string, bool): list<array<string, mixed>>    $drainFrames by-reference `(string &$buffer,
     *                                                                           bool &$corrupt)`: the parent's
     *                                                                           own frame decoder
     */
    public function __construct(
        private $socket,
        private readonly \Closure $writeFrame,
        private readonly \Closure $drainFrames,
        private readonly string $mode,
        private readonly int $ownerPid,
    ) {
        // Reads go select-then-fread. With PHP's own read buffer in between,
        // bytes it had already pulled off the fd would be invisible to the
        // next select and the child would block on a reply it already has.
        if (is_resource($this->socket)) {
            stream_set_read_buffer($this->socket, 0);
        }
    }

    /**
     * @param resource                                           $socket
     * @param \Closure(array<string, mixed>): void               $writeFrame
     * @param \Closure(string, bool): list<array<string, mixed>> $drainFrames
     */
    public static function new($socket, \Closure $writeFrame, \Closure $drainFrames, string $mode = ''): self
    {
        return new self($socket, $writeFrame, $drainFrames, $mode, (int) getmypid());
    }

    /**
     * The approver {@see EngineBackend::withPermissionApprover()} takes: put
     * the question to the parent and grant only on an actual `once`/`always`.
     *
     * @return \Closure(ToolCall, HookResult): bool
     */
    public function approver(): \Closure
    {
        return fn (ToolCall $call, HookResult $ask): bool => $this->ask($call, $ask)->permits();
    }

    /**
     * Put one ASK to the parent and block until it is answered or the parent
     * is gone.
     *
     * $call is the call that will RUN — {@see \SugarCraft\Crush\Runtime::settleAsk()}
     * applies the question's rewrite before it reaches an approver.
     */
    public function ask(ToolCall $call, HookResult $ask): PermissionResolved
    {
        $frame = PendingAsk::describe($call, $ask, $this->mode);
        $askId = (string) $frame['askId'];

        if ((int) getmypid() !== $this->ownerPid) {
            return PermissionResolved::replied($askId, PermissionReply::Reject, self::GRANDCHILD_REFUSAL);
        }

        $grantKey = $ask->askedOnlyBy(PermissionGateHook::NAME) ? self::grantKey($call) : null;
        if ($grantKey !== null && isset($this->grants[$grantKey])) {
            return PermissionResolved::replied($askId, PermissionReply::Always);
        }

        if ($this->closed) {
            return PermissionResolved::cancelled($askId, self::PARENT_GONE);
        }

        ($this->writeFrame)($frame);

        $reply = $this->heldReplies[$askId] ?? null;
        unset($this->heldReplies[$askId]);
        while ($reply === null) {
            if (!$this->readInbound(true, $askId, $reply)) {
                return PermissionResolved::cancelled($askId, self::PARENT_GONE);
            }
        }

        $parsed = PermissionReply::tryFrom(is_string($reply['reply'] ?? null) ? $reply['reply'] : '');
        $note = self::clipNote(is_string($reply['note'] ?? null) ? $reply['note'] : '');
        // An answer this build cannot read is not consent.
        $resolution = PermissionResolved::replied($askId, $parsed ?? PermissionReply::Reject, $note);

        if ($parsed === PermissionReply::Always && $grantKey !== null) {
            $this->grants[$grantKey] = true;
        }

        return $resolution;
    }

    /**
     * Write one child→parent frame that is not an ask (`steer_ack`, `usage`,
     * `step`).
     *
     * @param array<string, mixed> $fields
     *
     * @return bool false when it was not written: an unknown kind, or a
     *              process that does not own the socket
     */
    public function send(string $kind, array $fields = []): bool
    {
        if ($kind === self::ASK || !in_array($kind, self::TO_PARENT, true)) {
            return false;
        }
        if ((int) getmypid() !== $this->ownerPid) {
            return false;
        }

        ($this->writeFrame)(['kind' => $kind] + $fields);

        return true;
    }

    /**
     * Steers the parent sent, in arrival order, each handed out once. Polls
     * the socket without blocking first, so a steer is seen at a step
     * boundary even when no ask was open to buffer it.
     *
     * @return list<array{steerId: string, text: string}>
     */
    public function takeSteers(): array
    {
        $this->poll();
        $steers = $this->steers;
        $this->steers = [];

        return $steers;
    }

    /**
     * Buffered `cancel_soft` / `cancel_tool` frames, each handed out once.
     *
     * @return list<array<string, mixed>>
     */
    public function takeControls(): array
    {
        $this->poll();
        $controls = $this->controls;
        $this->controls = [];

        return $controls;
    }

    /**
     * True once the parent's end has been seen closed (or the stream stopped
     * parsing): every later ask settles unanswered without writing.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    public static function clipNote(string $note): string
    {
        return strlen($note) <= self::MAX_NOTE_BYTES ? $note : mb_strcut($note, 0, self::MAX_NOTE_BYTES, 'UTF-8');
    }

    private static function grantKey(ToolCall $call): string
    {
        return hash('sha256', serialize([$call->name(), $call->arguments()]));
    }

    private function poll(): void
    {
        if ($this->closed || (int) getmypid() !== $this->ownerPid) {
            return;
        }
        $reply = null;
        $this->readInbound(false, null, $reply);
    }

    /**
     * Read what the socket has and route every whole frame in it.
     *
     * @param ?array<string, mixed> $reply set to the `ask_reply` for $awaiting when one arrives
     *
     * @return bool false once the parent's end is gone
     */
    private function readInbound(bool $block, ?string $awaiting, ?array &$reply): bool
    {
        $failures = 0;
        while (true) {
            if (!is_resource($this->socket)) {
                $this->closed = true;

                return false;
            }
            $read = [$this->socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, $block ? null : 0);
            if ($ready === false) {
                if (++$failures >= self::MAX_SELECT_FAILURES || feof($this->socket)) {
                    $this->closed = true;

                    return false;
                }

                continue;
            }
            if ($ready === 0) {
                return true;
            }

            $chunk = @fread($this->socket, 65536);
            if ($chunk === '' || $chunk === false) {
                $this->closed = true;

                return false;
            }

            $this->inbound .= $chunk;
            $corrupt = false;
            foreach (($this->drainFrames)($this->inbound, $corrupt) as $frame) {
                $this->route($frame, $awaiting, $reply);
            }
            if ($corrupt) {
                // The offset no longer means anything, so no later reply can
                // be trusted either: the question settles unanswered.
                $this->closed = true;

                return false;
            }

            if (!$block || $reply !== null) {
                return true;
            }
        }
    }

    /**
     * @param array<string, mixed>  $frame
     * @param ?array<string, mixed> $reply
     */
    private function route(array $frame, ?string $awaiting, ?array &$reply): void
    {
        $kind = $frame['kind'] ?? null;

        if ($kind === self::ASK_REPLY) {
            $askId = $frame['askId'] ?? null;
            if (!is_string($askId)) {
                return;
            }
            if ($awaiting !== null && $reply === null && $askId === $awaiting) {
                $reply = $frame;

                return;
            }
            // The parent settles each question once, so a reply for another
            // id is held for the ask that will want it rather than dropped.
            if (!isset($this->heldReplies[$askId]) && count($this->heldReplies) < self::MAX_HELD_REPLIES) {
                $this->heldReplies[$askId] = $frame;
            }

            return;
        }

        if ($kind === self::STEER) {
            $text = $frame['text'] ?? null;
            if (is_string($text) && $text !== '') {
                $steerId = $frame['steerId'] ?? null;
                $this->steers[] = ['steerId' => is_string($steerId) ? $steerId : '', 'text' => $text];
            }

            return;
        }

        if ($kind === self::CANCEL_SOFT || $kind === self::CANCEL_TOOL) {
            $this->controls[] = $frame;
        }
    }
}

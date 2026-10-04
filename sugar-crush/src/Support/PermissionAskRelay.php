<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * The wire one forked member of a concurrent tool group uses to put a
 * permission question to the process that forked it (roadmap 1.C-5).
 *
 * WHY IT EXISTS. A parallel `Task` sub-agent runs in a GRANDCHILD below the
 * turn child, and the approver it inherits is the turn child's
 * {@see ChildChannel}, which only answers in the process that built it — the
 * turn socket has one writer. So until this relay every ask a parallel
 * sub-agent raised was refused with {@see ChildChannel::GRANDCHILD_REFUSAL},
 * and since the TUI's default mode became `default` (DEF-MODE) that meant
 * every parallel Task write. Now {@see \SugarCraft\Crush\Runtime::executeConcurrently()}
 * opens one of these per member that {@see \SugarCraft\Crush\Tools\RelaysPermissionAsks},
 * the member runs on the approver {@see childApprover()} returns, and the
 * turn child answers each question with ITS approver — the channel — so the
 * question reaches the user's modal, a turn-wide `always` covers the
 * siblings too, and the answer comes back down verbatim.
 *
 * A UNIX STREAM PAIR with the engine's framing (4-byte big-endian length +
 * `serialize()`, decoded with `allowed_classes => false`), not datagrams: an
 * ask carries the call's arguments, and a `Write`'s content can be far larger
 * than one datagram may be.
 *
 * | Direction           | `kind`      | Fields                                              |
 * |---------------------|-------------|-----------------------------------------------------|
 * | member → turn child | `ask`       | `askId`, `call{id,name,arguments}`, `reason`, `askedBy` |
 * | turn child → member | `ask_reply` | `askId`, `reply` (`once`/`always`/`reject`/null), `feedback` |
 *
 * The reply carries the turn child's {@see ApprovalVerdict} EXACTLY — a
 * question nobody answered stays unanswered (`Permission required:`), and a
 * refusal's feedback is passed on as already labelled, never relabelled.
 *
 * ONE QUESTION AT A TIME PER MEMBER. A member's asks are raised by its own
 * runtime's gate, which settles calls one by one before it forks anything, so
 * the member blocks on exactly one reply. The turn child settles asks from
 * different members in arrival order — one modal at a time, as a sequential
 * turn would.
 */
final class PermissionAskRelay
{
    public const ASK = 'ask';

    public const ASK_REPLY = 'ask_reply';

    /** Same ceiling as the turn socket's frames ({@see \SugarCraft\Crush\Backend\EngineBackend::MAX_FRAME_BYTES}). */
    public const MAX_FRAME_BYTES = 64 * 1024 * 1024;

    public const PARENT_GONE = 'the turn ended before the sub-agent\'s question was answered';

    /** Consecutive failed selects tolerated before the stream counts as gone. */
    private const MAX_SELECT_FAILURES = 100;

    private string $inbound = '';

    /**
     * @param resource|null $reader the turn child's end
     * @param resource|null $writer the member's end
     */
    private function __construct(
        private mixed $reader,
        private mixed $writer,
    ) {
    }

    /**
     * Open a relay — the forking side, before the fork. Null when no socket
     * pair can be made; the member then keeps the approver it inherited,
     * which refuses with {@see ChildChannel::GRANDCHILD_REFUSAL}: a stated
     * refusal, never a silent grant.
     */
    public static function open(): ?self
    {
        if (!function_exists('stream_socket_pair')) {
            return null;
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            return null;
        }

        return new self($pair[0], $pair[1]);
    }

    /**
     * The member's half: drop the turn child's end, give up the inherited
     * copy of the turn socket ({@see ChildChannel::releaseInherited()} —
     * this process now has a channel of its own, so the turn's can only be
     * written by accident), and return the approver that puts each question
     * up this relay and blocks until it is answered.
     *
     * @return \Closure(ToolCall, HookResult): ApprovalVerdict
     */
    public function childApprover(): \Closure
    {
        self::closeStream($this->reader);
        $this->reader = null;
        ChildChannel::releaseInherited();

        return fn (ToolCall $call, HookResult $ask): ApprovalVerdict => $this->ask($call, $ask);
    }

    /**
     * The forking side's half, after the fork: drop the member's end and
     * make the read end non-blocking so a poll never stalls the reap loop.
     */
    public function becomeReader(): void
    {
        self::closeStream($this->writer);
        $this->writer = null;
        if (is_resource($this->reader)) {
            stream_set_blocking($this->reader, false);
            stream_set_read_buffer($this->reader, 0);
        }
    }

    /**
     * The read end, for the reap loop's `stream_select()`; null once closed.
     *
     * @return resource|null
     */
    public function readStream(): mixed
    {
        return is_resource($this->reader) ? $this->reader : null;
    }

    /**
     * Every question the member has put since the last call, each as the
     * call it is about and the ASK it carries, keyed by ask id. A frame that
     * is not a well-formed ask is skipped; a stream that stopped parsing is
     * closed (the member then sees EOF and settles its question unanswered).
     *
     * @return array<string, array{call: ToolCall, ask: HookResult}>
     */
    public function takeAsks(): array
    {
        if (!is_resource($this->reader)) {
            return [];
        }

        while (true) {
            $chunk = @fread($this->reader, 65536);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $this->inbound .= $chunk;
        }

        $corrupt = false;
        $asks = [];
        foreach (self::drainFrames($this->inbound, $corrupt) as $frame) {
            $decoded = self::decodeAsk($frame);
            if ($decoded !== null) {
                $asks[$decoded[0]] = ['call' => $decoded[1], 'ask' => $decoded[2]];
            }
        }
        if ($corrupt) {
            $this->close();
        }

        return $asks;
    }

    /**
     * Answer one question with the turn child's verdict. A member that is
     * already gone costs nothing: the write fails quietly and the reap loop
     * reports the member's own exit.
     */
    public function reply(string $askId, ApprovalVerdict $verdict): void
    {
        if (!is_resource($this->reader)) {
            return;
        }

        self::writeAll($this->reader, [
            'kind' => self::ASK_REPLY,
            'askId' => $askId,
            'reply' => $verdict->reply?->value,
            'feedback' => $verdict->feedback,
        ]);
    }

    public function close(): void
    {
        self::closeStream($this->reader);
        self::closeStream($this->writer);
        $this->reader = null;
        $this->writer = null;
    }

    /**
     * The ask id both ends name a question by — the D1 hash, so it is the id
     * the turn child's own channel will put to the parent.
     */
    public static function askId(ToolCall $call): string
    {
        return \SugarCraft\Crush\Backend\PendingAsk::askId($call->id(), $call->name(), $call->arguments());
    }

    /**
     * The verdict a reply frame carries, exactly as the turn child settled it.
     *
     * @param array<string, mixed> $frame
     */
    public static function verdictFromReply(array $frame): ApprovalVerdict
    {
        $feedback = is_string($frame['feedback'] ?? null) ? ChildChannel::clipNote($frame['feedback']) : '';
        $raw = $frame['reply'] ?? null;
        if ($raw === null) {
            return ApprovalVerdict::unanswered($feedback);
        }

        // An answer this build cannot read is not consent.
        return match (is_string($raw) ? PermissionReply::tryFrom($raw) : null) {
            PermissionReply::Once => ApprovalVerdict::once(),
            PermissionReply::Always => ApprovalVerdict::always(),
            default => ApprovalVerdict::reject($feedback),
        };
    }

    /**
     * The member's side of one question: write it, then block until its
     * reply arrives or the turn child's end is gone (unanswered — never a
     * grant). No deadline here, for the reason {@see ChildChannel} gives:
     * the parent owns the policy and pauses its idle ceiling while a
     * question is open.
     */
    private function ask(ToolCall $call, HookResult $ask): ApprovalVerdict
    {
        $askId = self::askId($call);
        $socket = $this->writer;
        if (!is_resource($socket)) {
            return ApprovalVerdict::unanswered(self::PARENT_GONE);
        }

        if (!self::writeAll($socket, [
            'kind' => self::ASK,
            'askId' => $askId,
            'call' => ['id' => $call->id(), 'name' => $call->name(), 'arguments' => $call->arguments()],
            'reason' => $ask->message,
            'askedBy' => $ask->askedBy,
        ])) {
            return ApprovalVerdict::unanswered(self::PARENT_GONE);
        }

        $buffer = '';
        $failures = 0;
        while (true) {
            if (!is_resource($socket)) {
                return ApprovalVerdict::unanswered(self::PARENT_GONE);
            }
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, null);
            if ($ready === false) {
                if (++$failures >= self::MAX_SELECT_FAILURES || feof($socket)) {
                    return ApprovalVerdict::unanswered(self::PARENT_GONE);
                }

                continue;
            }
            $failures = 0;
            $chunk = @fread($socket, 65536);
            if (!is_string($chunk) || $chunk === '') {
                return ApprovalVerdict::unanswered(self::PARENT_GONE);
            }
            $buffer .= $chunk;
            $corrupt = false;
            foreach (self::drainFrames($buffer, $corrupt) as $frame) {
                if (($frame['kind'] ?? null) === self::ASK_REPLY && ($frame['askId'] ?? null) === $askId) {
                    return self::verdictFromReply($frame);
                }
            }
            if ($corrupt) {
                return ApprovalVerdict::unanswered(self::PARENT_GONE);
            }
        }
    }

    /**
     * @param array<string, mixed> $frame
     *
     * @return array{0: string, 1: ToolCall, 2: HookResult}|null
     */
    private static function decodeAsk(array $frame): ?array
    {
        if (($frame['kind'] ?? null) !== self::ASK) {
            return null;
        }
        $call = $frame['call'] ?? null;
        if (!is_array($call)) {
            return null;
        }
        $id = $call['id'] ?? null;
        $name = $call['name'] ?? null;
        $arguments = $call['arguments'] ?? null;
        if (!is_string($id) || !is_string($name) || $name === '' || !is_array($arguments)) {
            return null;
        }
        $toolCall = new ToolCall($id, $name, $arguments);
        $askId = self::askId($toolCall);
        // The id is content-derived, so one that does not match the call it
        // rides with is a frame that was not written by this relay.
        if (($frame['askId'] ?? null) !== $askId) {
            return null;
        }

        $askedBy = [];
        foreach ((array) ($frame['askedBy'] ?? []) as $asker) {
            if (is_string($asker) && $asker !== '') {
                $askedBy[] = $asker;
            }
        }
        $reason = is_string($frame['reason'] ?? null) ? $frame['reason'] : '';

        return [$askId, $toolCall, HookResult::ask($reason)->withAskedBy($askedBy)];
    }

    /**
     * @param resource             $socket
     * @param array<string, mixed> $frame
     */
    private static function writeAll($socket, array $frame): bool
    {
        $body = serialize($frame);
        $out = pack('N', strlen($body)) . $body;
        $total = strlen($out);
        $stalls = 0;

        for ($written = 0; $written < $total;) {
            $n = @fwrite($socket, substr($out, $written));
            if ($n === false) {
                return false;
            }
            if ($n === 0) {
                // A non-blocking end with a full buffer: wait for room rather
                // than spin, and give up on an end nobody drains.
                $read = null;
                $write = [$socket];
                $except = null;
                if (@stream_select($read, $write, $except, 1) !== 1 && ++$stalls >= 30) {
                    return false;
                }

                continue;
            }
            $written += $n;
        }

        return true;
    }

    /**
     * @param bool $corrupt set when the stream stopped being parseable
     *
     * @return list<array<string, mixed>>
     */
    private static function drainFrames(string &$buffer, bool &$corrupt): array
    {
        $frames = [];
        while (strlen($buffer) >= 4) {
            $header = unpack('N', substr($buffer, 0, 4));
            $length = is_array($header) ? (int) ($header[1] ?? 0) : 0;
            if ($length <= 0 || $length > self::MAX_FRAME_BYTES) {
                $buffer = '';
                $corrupt = true;
                break;
            }
            if (strlen($buffer) < 4 + $length) {
                break;
            }
            $body = substr($buffer, 4, $length);
            $buffer = substr($buffer, 4 + $length);
            $decoded = @unserialize($body, ['allowed_classes' => false]);
            if (!is_array($decoded)) {
                $buffer = '';
                $corrupt = true;
                break;
            }
            $frames[] = $decoded;
        }

        return $frames;
    }

    private static function closeStream(mixed $stream): void
    {
        if (is_resource($stream)) {
            @fclose($stream);
        }
    }
}

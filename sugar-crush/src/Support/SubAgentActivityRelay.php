<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SubAgentActivity;

/**
 * The wire one forked member of a concurrent tool group uses to hand its
 * {@see SubAgentActivity} beats back to the process that forked it.
 *
 * WHY IT EXISTS. {@see \SugarCraft\Crush\Runtime::executeConcurrently()} runs
 * every member in its own child, but the emitter a Task call is bound with
 * belongs to the turn process — the only one allowed to write the turn's
 * socket — and drops every beat from anywhere else. So a batch of parallel
 * Task calls ran with no row in the Agents pane at all. A relay is opened per
 * member right before its fork: the child writes beats onto it, the parent
 * drains it on each reap pass and replays them through the real emitter
 * (see {@see \SugarCraft\Crush\Tools\RelaysSubAgentActivity}).
 *
 * ONE-WAY OBSERVATION, NEVER CONTROL — the {@see SubAgentActivity} channel
 * contract. A write that fails (the parent stopped reading) marks the relay
 * broken and every later beat is skipped; the delegated run carries on. A
 * frame the reader cannot parse drops the rest of the stream the same way. No
 * branch here ever ends a run over a display frame.
 *
 * Frames are a 4-byte big-endian length then a serialized plain array —
 * the shape {@see \SugarCraft\Crush\Backend\EngineBackend}'s own wire uses,
 * and decoded with `allowed_classes => false` for the same reason: the bytes
 * crossed a process boundary.
 */
final class SubAgentActivityRelay
{
    /**
     * Ceiling on one frame's body — the engine's, because these beats are
     * re-emitted onto the engine's own wire, and a frame this relay accepted
     * but the engine refused would be truncated one process further down.
     */
    private const MAX_FRAME_BYTES = EngineBackend::MAX_FRAME_BYTES;

    private string $buffer = '';

    private bool $broken = false;

    /**
     * @param resource|null $reader
     * @param resource|null $writer
     */
    private function __construct(
        private mixed $reader,
        private mixed $writer,
    ) {}

    /**
     * Open a relay — the parent's half, called before the fork. Null when no
     * socket pair can be made; that member then runs exactly as it did before
     * this class existed (its beats are dropped), which is a blank row, not a
     * broken turn.
     */
    public static function open(): ?self
    {
        if (!function_exists('stream_socket_pair')) {
            return null;
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            return null;
        }

        return new self($pair[0], $pair[1]);
    }

    /**
     * The child's half: drop the read end and return the emitter that writes
     * each beat onto the relay.
     *
     * @return \Closure(SubAgentActivity): void
     */
    public function childEmitter(): \Closure
    {
        self::closeStream($this->reader);
        $this->reader = null;

        return function (SubAgentActivity $activity): void {
            $this->write($activity);
        };
    }

    /**
     * The parent's half, called after the fork: drop the write end (the child
     * holds its own copy) and make the read end non-blocking so a drain never
     * stalls the reap loop.
     */
    public function becomeReader(): void
    {
        self::closeStream($this->writer);
        $this->writer = null;
        if (is_resource($this->reader)) {
            stream_set_blocking($this->reader, false);
        }
    }

    /**
     * Every complete beat that has arrived since the last drain, in wire
     * order. A trailing partial frame stays buffered for the next call.
     *
     * @return list<SubAgentActivity>
     */
    public function drain(): array
    {
        if ($this->broken || !is_resource($this->reader)) {
            return [];
        }

        while (true) {
            $chunk = @fread($this->reader, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->buffer .= $chunk;
        }

        $beats = [];
        while (strlen($this->buffer) >= 4) {
            $header = unpack('N', substr($this->buffer, 0, 4));
            $length = is_array($header) ? (int) ($header[1] ?? 0) : 0;
            if ($length <= 0 || $length > self::MAX_FRAME_BYTES) {
                return $this->breakStream($beats);
            }
            if (strlen($this->buffer) < 4 + $length) {
                break;
            }

            $body = substr($this->buffer, 4, $length);
            $this->buffer = substr($this->buffer, 4 + $length);

            $beat = self::decode($body);
            if ($beat === null) {
                return $this->breakStream($beats);
            }
            $beats[] = $beat;
        }

        return $beats;
    }

    public function close(): void
    {
        self::closeStream($this->reader);
        self::closeStream($this->writer);
        $this->reader = null;
        $this->writer = null;
        $this->buffer = '';
    }

    private function write(SubAgentActivity $activity): void
    {
        if ($this->broken || !is_resource($this->writer)) {
            return;
        }

        $body = serialize([
            'op' => $activity->op,
            'id' => $activity->id,
            'name' => $activity->name,
            'task' => $activity->task,
            'seq' => $activity->seq,
            'tail' => $activity->tail,
            'tokens' => $activity->tokensUsed,
            'cost' => $activity->costUsd,
            'lines' => $activity->lines,
            'model' => $activity->model,
            'context' => $activity->contextTokens,
            'calls' => $activity->calls,
        ]);
        $out = pack('N', strlen($body)) . $body;
        $total = strlen($out);

        for ($written = 0; $written < $total;) {
            $n = @fwrite($this->writer, substr($out, $written));
            if ($n === false || $n === 0) {
                // The reader may now hold half a frame, so nothing written
                // after this could be parsed — stop relaying, keep running.
                $this->broken = true;

                return;
            }
            $written += $n;
        }
    }

    /**
     * @param list<SubAgentActivity> $beats the whole frames decoded before the bad one
     * @return list<SubAgentActivity>
     */
    private function breakStream(array $beats): array
    {
        $this->broken = true;
        $this->buffer = '';

        return $beats;
    }

    private static function decode(string $body): ?SubAgentActivity
    {
        $frame = @unserialize($body, ['allowed_classes' => false]);
        if (!is_array($frame)) {
            return null;
        }

        $op = $frame['op'] ?? null;
        $id = $frame['id'] ?? null;
        $name = $frame['name'] ?? null;
        $task = $frame['task'] ?? null;
        $seq = $frame['seq'] ?? null;
        $tail = $frame['tail'] ?? null;
        if (!is_string($op) || !in_array($op, SubAgentActivity::OPS, true)
            || !is_string($id) || $id === '' || !is_string($name) || !is_string($task)
            || !is_int($seq) || !is_string($tail)) {
            return null;
        }

        return new SubAgentActivity($op, $id, $name, $task, $seq, $tail, ...EngineBackend::subAgentTotals($frame));
    }

    private static function closeStream(mixed $stream): void
    {
        if (is_resource($stream)) {
            @fclose($stream);
        }
    }
}

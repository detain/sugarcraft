<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Tools\ActivitySink;
use SugarCraft\Crush\Tools\DatagramActivitySink;

/**
 * The wire one forked member of a concurrent tool group uses to hand its
 * {@see SubAgentActivity} beats back to the process that forked it.
 *
 * WHY IT EXISTS. {@see \SugarCraft\Crush\Runtime::executeConcurrently()} runs
 * every member in its own child, but the emitter a Task call is bound with
 * belongs to the turn process — the only one allowed to write the turn's
 * socket — and drops every beat from anywhere else. So a relay is opened per
 * member right before its fork: the child writes beats onto it through a
 * {@see DatagramActivitySink}, the parent drains it on each reap pass and
 * replays them through the real emitter
 * (see {@see \SugarCraft\Crush\Tools\StreamsActivity}).
 *
 * A UNIX DATAGRAM PAIR, one beat per datagram: see
 * {@see DatagramActivitySink} for why. A datagram that does not decode costs
 * that one beat and nothing after it.
 *
 * ONE-WAY OBSERVATION, NEVER CONTROL — the {@see SubAgentActivity} channel
 * contract. No branch here ever ends a run over a display frame.
 *
 * THE READER REMEMBERS WHICH RUNS ARE STILL OPEN. A run that was seen to
 * start but never seen to finish — its process was killed, it died on a
 * fatal error, or its last datagram was dropped on a full queue — is listed
 * by {@see unfinished()}, so the forking parent can close the row itself
 * before it releases the member's result instead of leaving it "running"
 * for the rest of the session.
 */
final class SubAgentActivityRelay
{
    /**
     * Each run this reader has seen start and not yet seen finish, keyed by
     * its id: the latest beat it sent.
     *
     * @var array<string, SubAgentActivity>
     */
    private array $open = [];

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
     * socket pair can be made; that member then runs exactly as it would
     * without one (its beats are dropped), which is a blank row, not a broken
     * turn.
     */
    public static function open(): ?self
    {
        if (!function_exists('stream_socket_pair') || !function_exists('stream_socket_recvfrom')) {
            return null;
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);
        if ($pair === false) {
            return null;
        }

        return new self($pair[0], $pair[1]);
    }

    /**
     * The child's half: drop the read end and return the sink that writes
     * each beat onto the relay.
     */
    public function childSink(): ActivitySink
    {
        self::closeStream($this->reader);
        $this->reader = null;

        return DatagramActivitySink::over($this->writer);
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
     * The read end, for a `stream_select()` that waits on beats instead of
     * sleeping; null once closed.
     *
     * @return resource|null
     */
    public function readStream(): mixed
    {
        return is_resource($this->reader) ? $this->reader : null;
    }

    /**
     * Every beat that has arrived since the last drain, in wire order. A
     * datagram that does not decode is skipped; the ones around it are kept.
     *
     * @return list<SubAgentActivity>
     */
    public function drain(): array
    {
        if (!is_resource($this->reader)) {
            return [];
        }

        $beats = [];
        while (true) {
            $datagram = @stream_socket_recvfrom($this->reader, DatagramActivitySink::MAX_DATAGRAM_BYTES);
            if (!is_string($datagram) || $datagram === '') {
                break;
            }

            $beat = self::decode($datagram);
            if ($beat === null) {
                continue;
            }

            if ($beat->op === SubAgentActivity::OP_FINISHED) {
                unset($this->open[$beat->id]);
            } else {
                $this->open[$beat->id] = $beat;
            }
            $beats[] = $beat;
        }

        return $beats;
    }

    /**
     * The latest beat of every run this reader saw start but never saw
     * finish, in the order they started. Meaningful once the member's
     * process has exited and a final {@see drain()} has run: then nothing
     * more can arrive, and each of these runs ended without saying so.
     *
     * @return list<SubAgentActivity>
     */
    public function unfinished(): array
    {
        return array_values($this->open);
    }

    public function close(): void
    {
        self::closeStream($this->reader);
        self::closeStream($this->writer);
        $this->reader = null;
        $this->writer = null;
    }

    private static function decode(string $datagram): ?SubAgentActivity
    {
        $frame = @unserialize($datagram, ['allowed_classes' => false]);

        return is_array($frame) ? SubAgentActivity::fromArray($frame) : null;
    }

    private static function closeStream(mixed $stream): void
    {
        if (is_resource($stream)) {
            @fclose($stream);
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Diagnostics;

use React\EventLoop\Loop;

/**
 * One session's runtime-notice inbox: the per-instance half of the mid-session
 * transcript seam (Appendix O §4.2, roadmap O-2a).
 *
 * WHY AN INSTANCE NOW. {@see RuntimeNoticeSink} used to BE the inbox, as a
 * process-global static queue plus the cross-fork transport. That is right for
 * a TUI, which runs one session per process, and wrong for any host that runs
 * two: concurrent turns in two sessions would write into one queue, and
 * whichever session drained first would show the other's warnings. So the
 * state — the arming, the in-process queue, the datagram pair, the E199 turn
 * budget and the E193 read watcher — lives here, one instance per session, and
 * {@see RuntimeNoticeSink} keeps its whole static surface as a FACADE over the
 * instance it calls current. The emitters do not change: the two `final
 * readonly` tool-call parsers that are the reason the facade is static (see
 * that class's doc-block) still call `RuntimeNoticeSink::warn()`, and the
 * notice lands in whichever session's sink is current in the process that
 * raised it.
 *
 * MUTABLE ON PURPOSE, against the house immutable-and-fluent rule: this is a
 * live resource (two socket fds and a loop watcher), not a value. A `with*()`
 * copy would share the fds and split the queue, which is the same split-state
 * bug a second {@see \SugarCraft\Code\Permissions\PermissionGate} is.
 *
 * WHAT IS NOT HERE: the `error_log()` forensic copy. Where `error_log` writes
 * is a property of the PROCESS (one ini, one fd 2), not of a session, so
 * {@see RuntimeNoticeSink::warn()} keeps it and consults the current sink only
 * for the C2a "transport armed and still on stderr" decision. The semantics of
 * every method below are documented once, on the facade method of the same
 * name, because that is where four years of call sites and tests cite them.
 *
 * A FORKED CHILD IS A WRITER ONLY. The read end is inherited by every child
 * forked after {@see arm()}, and a child that read it would consume datagrams
 * the PARENT's transcript is waiting for — theft across processes rather than
 * across sessions. So the reading methods answer "nothing" in any process
 * other than the one that armed the sink, keyed by pid exactly as
 * `Bootstrap::$mcpClients` keys ownership, and
 * {@see RuntimeNoticeSink::enterForkedChild()} drops an inherited watcher
 * without touching the parent's loop.
 */
final class NoticeSink
{
    private bool $armed = false;

    /** @var list<string> The in-process backend. See {@see RuntimeNoticeSink}'s doc-block. */
    private array $queue = [];

    /** Notices the in-process backend refused because {@see RuntimeNoticeSink::NOTICE_LIMIT} was reached. */
    private int $dropped = 0;

    /** Whether the per-turn budget (E199) is armed — true from the first {@see beginTurn()} until {@see reset()}. */
    private bool $turnAccounting = false;

    /** Notice rows surfaced to the transcript since {@see beginTurn()}, overflow row excluded. */
    private int $turnSurfaced = 0;

    /** Whether this turn has already had its single {@see RuntimeNoticeSink::OVERFLOW_FORMAT} row. */
    private bool $turnOverflowAnnounced = false;

    /** @var resource|null The read end of the transport, owned by {@see $readerPid}. */
    private $transportRead = null;

    /** @var resource|null The write end, inherited by every child forked after {@see arm()}. */
    private $transportWrite = null;

    /**
     * The pid that armed this sink and is therefore its only reader; null while
     * unarmed. See this class's doc-block on why a forked child never reads.
     */
    private ?int $readerPid = null;

    /**
     * Removes the readable-watcher {@see notifyOnceWhenPending()} installed, or
     * null when none is installed. A closure rather than a bool so the fd and
     * the loop it was registered on travel with the canceller — see
     * {@see RuntimeNoticeSink::reset()}.
     */
    private ?\Closure $pendingWatcher = null;

    private function __construct()
    {
    }

    /** A fresh, unarmed sink: it drops every notice until {@see arm()} runs. */
    public static function new(): self
    {
        return new self();
    }

    /** See {@see RuntimeNoticeSink::record()}. */
    public function record(string $message): bool
    {
        if (!$this->armed) {
            return false;
        }

        $notice = $this->clip(trim($message));

        if ($notice === '') {
            return false;
        }

        if ($this->transportWrite !== null) {
            // `@` is load-bearing — see RuntimeNoticeSink's doc-block on the
            // measured `errno=111` diagnostic. A datagram is all-or-nothing.
            $written = @fwrite($this->transportWrite, $notice);

            return $written !== false && $written > 0;
        }

        if (count($this->queue) >= RuntimeNoticeSink::NOTICE_LIMIT) {
            $this->dropped++;

            return false;
        }

        $this->queue[] = $notice;

        return true;
    }

    /**
     * Record a STRUCTURED notice frame (C-CTX-6.e): $kind names a reader
     * contract and $payload carries its fields, framed as
     * {@see RuntimeNoticeSink::STRUCTURED_PREFIX} + a JSON object. The
     * prefix head is 0x01, a byte no plain runtime row can begin with —
     * chosen deliberately because the default {@see record()} trim eats
     * NUL (its charlist contains "\0"), so a \x00-head frame would arrive
     * mangled. Only {@see RuntimeNoticeSink::KIND_MEMORY_RECALL} is
     * defined; an unknown kind is refused, because a producer inventing
     * kinds without a reader mapping would park datagrams in drains that
     * can only ever drop them.
     *
     * The bound is settled HERE rather than left to {@see record()}'s
     * clip: a clipped frame would arrive as broken JSON and be dropped
     * whole, silently losing the row. So an over-budget frame sheds its
     * TAIL titles — the head titles are the best-ranked notes, and the
     * count keeps its true value — and only a skeleton that cannot fit
     * on its own (an absurd count's digits) is refused.
     *
     * @param array<string, mixed> $payload
     */
    public function recordStructured(string $kind, array $payload): bool
    {
        if ($kind !== RuntimeNoticeSink::KIND_MEMORY_RECALL) {
            return false;
        }

        $count = isset($payload['count']) && is_int($payload['count'])
            ? max(0, $payload['count'])
            : 0;

        $titles = [];
        foreach (isset($payload['titles']) && is_array($payload['titles']) ? $payload['titles'] : [] as $title) {
            if (!is_string($title)) {
                continue;
            }
            // Control bytes would forge framing inside the JSON string,
            // and a newline inside a title would split the one-line
            // expanded row: every whitespace run folds to a single space
            // and stray controls are stripped (producers already send a
            // first line — this is defense against the frame forger).
            $clean = trim((string) preg_replace(
                '/\s+/u',
                ' ',
                (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $title),
            ));
            $clean = mb_substr($clean, 0, RuntimeNoticeSink::STRUCTURED_TITLE_MAX_CHARS, 'UTF-8');
            if ($clean !== '') {
                $titles[] = $clean;
            }
        }

        $build = static function (array $titles) use ($kind, $count): string {
            return RuntimeNoticeSink::STRUCTURED_PREFIX . json_encode(
                ['kind' => $kind, 'count' => $count, 'titles' => array_values($titles)],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        };

        $frame = $build($titles);
        while (mb_strlen($frame, 'UTF-8') > RuntimeNoticeSink::MAX_CHARS && $titles !== []) {
            array_pop($titles);
            $frame = $build($titles);
        }
        if (mb_strlen($frame, 'UTF-8') > RuntimeNoticeSink::MAX_CHARS) {
            // Even the skeleton (no titles) does not fit — loud refusal
            // through the return, never a corrupt half-frame on the wire.
            // DEFENSIVE RATHER THAN LIVE: at MAX_CHARS=400 the skeleton
            // (prefix + kind + an int count) cannot reach the bound today;
            // this arm earns its place only against a future shrink of the
            // bound, and pins the refusal so a shrunk bound fails loud here
            // rather than silently shipping clipped JSON.
            return false;
        }

        return $this->record($frame);
    }

    /**
     * Parse a drained line back into its structured fields, or null when
     * it is a plain row, a malformed frame, or a frame whose payload
     * shape fails the $kind's contract. Readers branch on this BEFORE
     * minting a row so a dropped frame parks nothing: the worst case is
     * the notice row's own silence, never a half-parsed row.
     *
     * @return ?array{kind: string, count: int, titles: list<string>}
     */
    public static function decodeStructuredFrame(string $line): ?array
    {
        if (!str_starts_with($line, RuntimeNoticeSink::STRUCTURED_PREFIX)) {
            return null;
        }

        $json = substr($line, strlen(RuntimeNoticeSink::STRUCTURED_PREFIX));
        if (!json_validate($json)) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)
            || ($decoded['kind'] ?? null) !== RuntimeNoticeSink::KIND_MEMORY_RECALL
            || !isset($decoded['count']) || !is_int($decoded['count']) || $decoded['count'] < 0
        ) {
            return null;
        }

        $titles = [];
        foreach (isset($decoded['titles']) && is_array($decoded['titles']) ? $decoded['titles'] : [] as $title) {
            if (is_string($title)) {
                $titles[] = $title;
            }
        }

        return ['kind' => RuntimeNoticeSink::KIND_MEMORY_RECALL, 'count' => $decoded['count'], 'titles' => $titles];
    }

    /** See {@see RuntimeNoticeSink::hasPending()}. */
    public function hasPending(): bool
    {
        if (!$this->isReader()) {
            return false;
        }

        if ($this->queue !== [] || $this->dropped > 0) {
            return true;
        }

        if ($this->transportRead === null) {
            return false;
        }

        $read = [$this->transportRead];
        $write = null;
        $except = null;

        return @stream_select($read, $write, $except, 0, 0) > 0;
    }

    /**
     * See {@see RuntimeNoticeSink::drain()}.
     *
     * @return list<string>
     */
    public function drain(): array
    {
        if (!$this->isReader()) {
            return [];
        }

        if ($this->turnOverflowAnnounced) {
            // E199: this turn already got its overflow row; everything after
            // it is discarded at the read so hasPending() goes false.
            $this->discardPendingNotices();

            return [];
        }

        $notices = $this->queue;
        $this->queue = [];
        $dropped = $this->dropped;
        $this->dropped = 0;

        if ($this->transportRead !== null) {
            // Bounded per drain rather than "until empty": the socket is the
            // queue, and the next tick picks the rest up.
            for ($i = 0; $i < RuntimeNoticeSink::NOTICE_LIMIT; $i++) {
                $datagram = @stream_socket_recvfrom($this->transportRead, RuntimeNoticeSink::DATAGRAM_BYTES);
                if ($datagram === false || $datagram === '') {
                    break;
                }
                $notices[] = $datagram;
            }
        }

        $unique = [];
        foreach ($notices as $notice) {
            if (!in_array($notice, $unique, true)) {
                $unique[] = $notice;
            }
        }

        if ($this->turnAccounting) {
            $remaining = RuntimeNoticeSink::TURN_NOTICE_LIMIT - $this->turnSurfaced;

            if (count($unique) > $remaining) {
                // KEEP-MOST-RECENT (N-1, owner ruling round gap-6): the rows
                // that survive a truncation are the TAIL of the batch, in
                // recorded order — fresher context beats older context in a
                // transcript the model re-reads every later turn. Head-keeping
                // was reviewed-and-kept in fix-1017 and reversed here: the
                // array_slice bound is `min(count, remaining)` either way, so
                // the excess math below is unchanged by which end survives.
                $excess = count($unique) - max(0, $remaining);
                $unique = $remaining > 0 ? array_slice($unique, -$remaining) : [];
                $this->turnSurfaced += count($unique);
                $this->turnOverflowAnnounced = true;

                // ONE row for everything the turn will not surface: this
                // batch's unique excess, the transport's unread remainder,
                // and any array-backend refusals taken with it.
                $discarded = $excess + $this->discardPendingNotices() + $dropped;
                $unique[] = $this->overflowNotice($discarded);

                return $unique;
            }

            $this->turnSurfaced += count($unique);
        }

        if ($dropped > 0) {
            $unique[] = $this->overflowNotice($dropped);
        }

        return $unique;
    }

    /** See {@see RuntimeNoticeSink::beginTurn()}. */
    public function beginTurn(): void
    {
        $this->turnAccounting = true;
        $this->turnSurfaced = 0;
        $this->turnOverflowAnnounced = false;
    }

    /** See {@see RuntimeNoticeSink::arm()}. */
    public function arm(bool $crossFork = true): bool
    {
        if ($this->armed) {
            return $this->transportWrite !== null;
        }

        $this->armed = true;
        $this->readerPid = getmypid() ?: null;

        if (!$crossFork) {
            return false;
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);

        if ($pair === false) {
            return false;
        }

        [$this->transportRead, $this->transportWrite] = $pair;
        stream_set_blocking($this->transportRead, false);
        // Non-blocking on the WRITE end is the half that matters: a child that
        // blocked here would stall the turn behind a diagnostic nobody reads.
        stream_set_blocking($this->transportWrite, false);

        return true;
    }

    /** Whether {@see arm()} has run on this sink, on either backend. */
    public function isArmed(): bool
    {
        return $this->armed;
    }

    /** Whether {@see arm()} got the cross-fork transport rather than the array. */
    public function hasTransport(): bool
    {
        return $this->transportWrite !== null;
    }

    /** See {@see RuntimeNoticeSink::notifyOnceWhenPending()}. */
    public function notifyOnceWhenPending(\Closure $notify): bool
    {
        if ($this->transportRead === null || !$this->isReader()) {
            return false;
        }

        $this->cancelPendingNotification();

        $loop = Loop::get();
        $stream = $this->transportRead;

        $loop->addReadStream($stream, function () use ($notify): void {
            $this->cancelPendingNotification();
            $notify();
        });

        $this->pendingWatcher = static function () use ($loop, $stream): void {
            $loop->removeReadStream($stream);
        };

        return true;
    }

    /** See {@see RuntimeNoticeSink::cancelPendingNotification()}. */
    public function cancelPendingNotification(): void
    {
        $canceller = $this->pendingWatcher;
        $this->pendingWatcher = null;

        if ($canceller !== null) {
            $canceller();
        }
    }

    /** Whether a {@see notifyOnceWhenPending()} watcher is installed. */
    public function isNotificationArmed(): bool
    {
        return $this->pendingWatcher !== null;
    }

    /**
     * Forget an inherited read watcher WITHOUT running its canceller — the
     * forked-child half of {@see RuntimeNoticeSink::enterForkedChild()}.
     *
     * Not {@see cancelPendingNotification()}: that removes the stream from the
     * loop the watcher was registered on, and in a child that loop is a COPY
     * of the parent's, which this process must not service or edit (see
     * {@see \SugarCraft\Code\Runtime}'s note on the inherited loop). Dropping
     * the reference is all a writer-only process needs.
     */
    public function forgetInheritedWatcher(): void
    {
        $this->pendingWatcher = null;
    }

    /** See {@see RuntimeNoticeSink::reset()}. */
    public function reset(): void
    {
        // BEFORE the fclose() below: a watcher left on the loop over a closed
        // stream is handed to stream_select() on every later iteration.
        $this->cancelPendingNotification();

        $this->armed = false;
        $this->readerPid = null;
        $this->queue = [];
        $this->dropped = 0;

        $this->turnAccounting = false;
        $this->turnSurfaced = 0;
        $this->turnOverflowAnnounced = false;

        foreach ([$this->transportRead, $this->transportWrite] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->transportRead = null;
        $this->transportWrite = null;
    }

    /**
     * Whether this process may read the inbox: the one that armed it.
     *
     * An unarmed sink has no reader and nothing to read, so it answers true and
     * lets the empty queue speak for itself.
     */
    private function isReader(): bool
    {
        return $this->readerPid === null || $this->readerPid === getmypid();
    }

    /**
     * Take everything still waiting and throw it away; return how many rows
     * went (E199). Reads the transport dry so hasPending() goes false.
     */
    private function discardPendingNotices(): int
    {
        $discarded = count($this->queue) + $this->dropped;
        $this->queue = [];
        $this->dropped = 0;

        if ($this->transportRead !== null) {
            while (true) {
                $datagram = @stream_socket_recvfrom($this->transportRead, RuntimeNoticeSink::DATAGRAM_BYTES);
                if ($datagram === false || $datagram === '') {
                    break;
                }
                $discarded++;
            }
        }

        return $discarded;
    }

    /** Clip to {@see RuntimeNoticeSink::MAX_CHARS}, counting the suffix against the budget. */
    private function clip(string $message): string
    {
        if (mb_strlen($message, 'UTF-8') <= RuntimeNoticeSink::MAX_CHARS) {
            return $message;
        }

        $suffix = sprintf(RuntimeNoticeSink::CLIP_SUFFIX_FORMAT, RuntimeNoticeSink::fullTextPhrase($this));

        return mb_substr(
            $message,
            0,
            RuntimeNoticeSink::MAX_CHARS - mb_strlen($suffix, 'UTF-8'),
            'UTF-8',
        ) . $suffix;
    }

    private function overflowNotice(int $dropped): string
    {
        return sprintf(
            RuntimeNoticeSink::OVERFLOW_FORMAT,
            $dropped,
            $dropped === 1 ? '' : 's',
            RuntimeNoticeSink::fullTextPhrase($this),
        );
    }
}

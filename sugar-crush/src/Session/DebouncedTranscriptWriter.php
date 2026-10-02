<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

use SugarCraft\Crush\Message;

/**
 * Coalesces {@see \SugarCraft\Crush\Chat}'s transcript saves (audit R2).
 *
 * Chat used to call {@see EnhancedSessionStore::saveTranscript()} inside
 * `update()` on EVERY history change: each tool row, each placeholder swap,
 * each settled reply re-encoded and rewrote the whole conversation on the TUI
 * thread. This object holds the newest unsaved snapshot instead, and Chat
 * declares a tick subscription for as long as {@see hasPending()} is true;
 * each {@see \SugarCraft\Crush\TranscriptFlushMsg} it delivers writes the
 * snapshot once.
 *
 * A SUBSCRIPTION, NOT A RETURNED Cmd. `Program` keeps a keyed tick alive while
 * the model keeps declaring it and cancels it the reconcile after it stops, so
 * the first change in a quiet period starts the timer, later changes only
 * replace the snapshot, and the flush ends it. Returning a `Cmd::tick` from
 * `update()` would have meant batching it into every Cmd a history-changing
 * route returns, changing the shape of what those routes hand back.
 *
 * NOT A RESETTING DEBOUNCE. Changes do not push the write back, so a long
 * stream of them still saves every {@see DELAY_SECONDS} rather than never —
 * what a crash can lose is bounded by the window, not by how busy the session
 * was.
 *
 * SHARED, MUTABLE, ON PURPOSE. Chat is immutable and every keystroke is a new
 * instance; the snapshot has to be visible to whichever instance is on screen
 * when the tick lands, so it lives here and Chat carries this object by
 * identity, the way it carries its token tracker.
 *
 * WHEN IT FLUSHES SYNCHRONOUSLY: {@see flush()} is called before a session
 * switch, before a fork reads the stored transcript, and at process shutdown.
 * The shutdown hook covers `/quit`, Ctrl+C (candy-core turns SIGINT into an
 * orderly quit), `exit()` and a fatal error. A SIGKILL loses at most one
 * window. The hook acts only in the process that registered it: a forked turn
 * child that exits normally must not write its parent's snapshot, which may
 * be older than what the parent saves later.
 */
final class DebouncedTranscriptWriter
{
    /** How long a change may wait before it is written. */
    public const DELAY_SECONDS = 0.5;

    /**
     * A snapshot pending for longer than this many windows means no tick is
     * arriving — a host that drives `update()` but never runs
     * `subscriptions()` — so the next change writes synchronously instead of
     * waiting forever, and such a host still saves.
     */
    private const LOST_TICK_WINDOWS = 4;

    private ?EnhancedSessionStore $store = null;

    private ?string $sessionId = null;

    /** @var list<Message>|null */
    private ?array $history = null;

    private ?float $pendingSince = null;

    private bool $shutdownRegistered = false;

    private int $ownerPid;

    public function __construct(private readonly float $delaySeconds = self::DELAY_SECONDS)
    {
        $this->ownerPid = getmypid();
    }

    /**
     * Record $history as $sessionId's newest unsaved transcript.
     *
     * A pending snapshot for a DIFFERENT session is written first, so
     * switching sessions can never drop the one being left.
     *
     * @param list<Message> $history
     */
    public function schedule(EnhancedSessionStore $store, string $sessionId, array $history): void
    {
        if ($this->history !== null && ($this->sessionId !== $sessionId || $this->store !== $store)) {
            $this->flush();
        }

        if ($this->pendingSince !== null
            && microtime(true) - $this->pendingSince > $this->delaySeconds * self::LOST_TICK_WINDOWS
        ) {
            $this->flush();
        }

        $this->store = $store;
        $this->sessionId = $sessionId;
        $this->history = $history;
        $this->pendingSince ??= microtime(true);
        $this->registerShutdown();
    }

    /** How long the tick that writes a pending snapshot waits, in seconds. */
    public function delaySeconds(): float
    {
        return $this->delaySeconds;
    }

    /**
     * Write the pending snapshot now, if there is one. A failed save is
     * swallowed — the same best-effort contract the synchronous save had: a
     * full disk must not cost the user the turn. Safe to call at any time.
     */
    public function flush(): void
    {
        $this->pendingSince = null;

        if ($this->history === null || $this->store === null || $this->sessionId === null) {
            return;
        }

        $store = $this->store;
        $sessionId = $this->sessionId;
        $history = $this->history;
        $this->history = null;

        try {
            $store->saveTranscript($sessionId, $history);
        } catch (\Throwable) {
            // Best effort - see the doc-block.
        }
    }

    /** True while a snapshot is waiting to be written. */
    public function hasPending(): bool
    {
        return $this->history !== null;
    }

    /**
     * The other half of the shutdown hook. `bin/sugarcrush` discards the
     * model `Program::run()` returns, so the last Chat — and this object with
     * it — can be collected BEFORE shutdown functions run, and the weak hook
     * would then find nothing to flush. Same owner-pid rule as the hook.
     */
    public function __destruct()
    {
        if (getmypid() === $this->ownerPid) {
            $this->flush();
        }
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;

        // Weak, so a test process that builds a thousand Chats does not keep
        // a thousand stores (and their PDO handles) alive until it exits.
        $self = \WeakReference::create($this);
        $owner = $this->ownerPid;
        register_shutdown_function(static function () use ($self, $owner): void {
            if (getmypid() !== $owner) {
                return;
            }
            $self->get()?->flush();
        });
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

/**
 * Per-SESSION memo of the system prompt's session-stable layers (roadmap step
 * 1.A-1): the static `<env>` half, the repo map, project memory and the
 * standing instruction/rule slab.
 *
 * WHY PER SESSION AND NOT PER RUNTIME. {@see \SugarCraft\Crush\Runtime}
 * memoises its snapshots on itself, and EngineBackend builds a fresh Runtime
 * every user turn — so "PerSession" meant "per turn": each turn re-walked the
 * repo map, re-read memory, re-read CLAUDE.md and re-captured the date, and
 * any byte that moved in between (a new source file, a note written by a
 * previous turn, an edited instruction file, midnight) rewrote the middle of
 * message 0 and voided the cache for the whole conversation behind it. Claude
 * Code and Aider both freeze these layers for the session; this is the holder
 * that lets a Runtime do the same. A Runtime given one
 * ({@see \SugarCraft\Crush\Runtime::withSessionPromptMemo()}) reads and fills
 * it; a Runtime without one keeps a private memo of its own, which is the
 * pre-1.A-1 per-Runtime behaviour exactly.
 *
 * WHO HOLDS IT. The owner must outlive the Runtime and sit on the PARENT side
 * of the per-turn fork, or the warmed entries die with the child. That owner
 * is EngineBackend (step 1.A-2): one memo per backend, shared by every clone,
 * primed in the parent before `completeAsync()` forks, so each turn's child
 * inherits the warm entries instead of re-reading them.
 *
 * FRESHNESS POLICY — ONE RULE FOR EVERY STANDING LAYER, CLAUDE.md INCLUDED.
 * Instruction files (CLAUDE.md, AGENTS.md, `@import`s, forced globs), rules
 * and rulebooks, project memory, the repo map and the static `<env>` lines are
 * read ONCE per session, at the first prompt build, and stay frozen until the
 * session's entries are dropped with {@see forget()} — on `/clear`, after a
 * compaction, or when a different session id arrives. The owner does not
 * have to be told which of those happened: {@see observeTurn()} sees each of
 * them in the history the next turn runs on. An edit to CLAUDE.md
 * made mid-session therefore takes effect at the next of those points, not on
 * the next step; that trades immediacy for a prefix that does not move, which
 * is the trade the surveyed agents make (crush_report IV.4). The inputs that
 * DO legitimately change a layer mid-session are part of its slot key instead
 * of being frozen: the project root, the model name (the `<env>` "Model:"
 * line), the `/rules` toggle set and the instruction-loader instance — so a
 * `/model` switch or a rulebook toggle rebuilds just that layer.
 *
 * THE INSTRUCTION DOCUMENTS HAVE A SECOND, INNER CACHE: an
 * {@see InstructionFileLoader} keeps what it read for its own lifetime. So the
 * refresh point for CLAUDE.md is forget() TOGETHER WITH a loader that has not
 * read it yet — a fresh instance, or (on the forked turn path) the parent's
 * never-warmed one. The loader instance is in the slab's slot key, so handing
 * the App a new loader rebuilds the slab even without forget().
 *
 * BOUNDED. A long-lived process (the daemon, a server host) sees many
 * sessions: at most {@see MAX_SESSIONS} are kept, least recently used first
 * out, and each keeps at most {@see MAX_SLOTS_PER_SESSION} slots, oldest out.
 *
 * Mutable by design — it is a cache, not a value object; nothing about it is
 * part of any rendered byte except through the values it returns.
 */
final class SessionPromptMemo
{
    /** Most sessions kept at once; the least recently used is evicted. */
    public const MAX_SESSIONS = 8;

    /** Most slots kept per session; the oldest is evicted. */
    public const MAX_SLOTS_PER_SESSION = 32;

    /** @var array<string, array<string, mixed>> session id => slot => value, LRU order */
    private array $entries = [];

    /**
     * What the last observed turn of each session ran on: how many
     * agent-visible rows, and one hash over them ({@see observeTurn()}).
     *
     * @var array<string, array{int, string}>
     */
    private array $histories = [];

    /** The session the last observed turn belonged to, or null before any. */
    private ?string $lastSession = null;

    /**
     * Public so an owner can default a promoted constructor field to a fresh
     * memo (`= new SessionPromptMemo()`); {@see new()} is the spelling
     * everything else uses.
     */
    public function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * The value memoised for ($sessionId, $slot), building and storing it on
     * first use. A null session id is a session of its own (`''`): an App
     * with no id still gets per-build reuse, it just cannot be told apart from
     * another id-less App.
     *
     * @template T
     * @param \Closure(): T $build
     * @return T
     */
    public function remember(?string $sessionId, string $slot, \Closure $build): mixed
    {
        $session = $sessionId ?? '';

        if (\array_key_exists($session, $this->entries)) {
            // Touch: move the session to the most-recent end.
            $slots = $this->entries[$session];
            unset($this->entries[$session]);
            $this->entries[$session] = $slots;

            if (\array_key_exists($slot, $slots)) {
                return $slots[$slot];
            }
        } else {
            $this->entries[$session] = [];
            if (\count($this->entries) > self::MAX_SESSIONS) {
                unset($this->entries[array_key_first($this->entries)]);
            }
        }

        $value = $build();
        $this->entries[$session][$slot] = $value;
        if (\count($this->entries[$session]) > self::MAX_SLOTS_PER_SESSION) {
            unset($this->entries[$session][array_key_first($this->entries[$session])]);
        }

        return $value;
    }

    /** Whether ($sessionId, $slot) already holds a value. */
    public function has(?string $sessionId, string $slot): bool
    {
        return \array_key_exists($slot, $this->entries[$sessionId ?? ''] ?? []);
    }

    /**
     * Drop every layer memoised for one session — the refresh point of the
     * freshness policy (`/clear`, compaction, a session switch).
     */
    public function forget(?string $sessionId): void
    {
        unset($this->entries[$sessionId ?? '']);
    }

    /** Drop everything. */
    public function forgetAll(): void
    {
        $this->entries = [];
        $this->histories = [];
        $this->lastSession = null;
    }

    /**
     * Record the history a turn of $sessionId is about to run on, and forget
     * that session's layers first when this turn is a refresh point of the
     * freshness policy. Returns whether it was one — the owner then refreshes
     * whatever ELSE caches the same files (the shared instruction loader),
     * even when this memo held nothing yet for the session.
     *
     * A refresh point is either of:
     *  - A SESSION SWITCH: the previous observed turn belonged to another
     *    session (`/resume`, `/branch`, Ctrl+Tab). Switching back to a
     *    session this memo still holds would otherwise reuse layers frozen
     *    before the switch.
     *  - A REWRITTEN HISTORY: this session's history no longer starts with
     *    every row its previous turn ran on — `/clear` emptied it, a
     *    compaction replaced the old rows with a summary, `/rewind` cut it.
     *    An ordinary turn only ever APPENDS (the reply, its transcript rows,
     *    the next prompt), so that prefix test is the whole signal.
     *
     * WHY DETECT RATHER THAN BE TOLD: the three events live in three places
     * in Chat, each owned by a different feature, and a missed call site
     * would freeze CLAUDE.md for the rest of the session with no symptom.
     * The history the turn is handed is the one thing all three must change.
     * A false positive costs one re-read of layers whose bytes, unchanged on
     * disk, come back identical — the prefix cache does not notice; a false
     * negative is the frozen state the policy already accepts until the next
     * refresh point.
     *
     * @param list<string> $rowKeys one stable key per agent-visible history row,
     *                              in order (EngineBackend hashes role + content)
     */
    public function observeTurn(?string $sessionId, array $rowKeys): bool
    {
        $session = $sessionId ?? '';

        $previous = $this->histories[$session] ?? null;
        $switched = $this->lastSession !== null && $this->lastSession !== $session;
        $rewritten = $previous !== null
            && (\count($rowKeys) < $previous[0] || self::hashRows(\array_slice($rowKeys, 0, $previous[0])) !== $previous[1]);

        if ($switched || $rewritten) {
            $this->forget($sessionId);
        }

        $this->histories[$session] = [\count($rowKeys), self::hashRows($rowKeys)];
        $this->lastSession = $session;

        // Bounded with the entries: a session evicted from those needs no
        // history either, and a long-lived daemon must not grow this map.
        if (\count($this->histories) > self::MAX_SESSIONS * 2) {
            unset($this->histories[array_key_first($this->histories)]);
        }

        return $switched || $rewritten;
    }

    /** @param list<string> $rowKeys */
    private static function hashRows(array $rowKeys): string
    {
        return hash('xxh128', implode("\0", $rowKeys));
    }

    /**
     * The session ids currently held, least recently used first.
     *
     * @return list<string>
     */
    public function sessions(): array
    {
        return array_map('strval', array_keys($this->entries));
    }
}

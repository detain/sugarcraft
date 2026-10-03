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
 * is EngineBackend, wired by step 1.A-2 (it primes the memo before
 * `completeAsync()` forks). Until then nothing in `src/` shares one across
 * turns, and the freshness below is per turn in practice.
 *
 * FRESHNESS POLICY — ONE RULE FOR EVERY STANDING LAYER, CLAUDE.md INCLUDED.
 * Instruction files (CLAUDE.md, AGENTS.md, `@import`s, forced globs), rules
 * and rulebooks, project memory, the repo map and the static `<env>` lines are
 * read ONCE per session, at the first prompt build, and stay frozen until the
 * session's entries are dropped with {@see forget()} — on `/clear`, after a
 * compaction, or when a different session id arrives. An edit to CLAUDE.md
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

    private function __construct()
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

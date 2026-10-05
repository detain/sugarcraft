<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Context\ContextBreakdown;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Providers\CacheBreakpoints;
use SugarCraft\Crush\Usage;

/**
 * The turn loop's consumer of {@see CacheBreakpoints::observeCacheHealth()}:
 * every step's {@see Usage} goes in, and the first time the diagnostic names
 * three consecutive zero-cache reports the sentence goes out ONCE through
 * {@see RuntimeNoticeSink}.
 *
 * ONCE, NOT ON EVERY REPORT AFTER THE THIRD. observeCacheHealth() keeps
 * returning its sentence for as long as the zeros continue, and leaves the
 * suppression to its consumer. A broken cache does not heal mid-session, so
 * repeating the row on every later step would bury the transcript in one
 * fact; said once, it stays readable.
 *
 * MUTABLE AND SHARED, ON PURPOSE. {@see EngineBackend} is immutable and every
 * wither rebuilds it, but the streak is session state: a backend clone must
 * add to the same count, and a notice one clone raised must not be raised
 * again by another. So the backend holds one instance and every clone carries
 * the same reference forward.
 *
 * ACROSS THE FORK. On the TUI path the turn runs in a `pcntl_fork()`ed child,
 * whose copy of this object dies with it. {@see state()} and {@see adopt()}
 * are the plain-array carry for the turn's result frame, the same rule
 * {@see Usage::toArray()} follows (the parent unserializes with
 * `allowed_classes => false`).
 *
 * CACHE BREAKS (roadmap 3.B-5, DCP §13.2 P2-10). The zero streak above only
 * sees a cache that never works. The failure DCP #614 was diagnosed from is
 * the other one: a cache that works until a context rewrite — a prune, a
 * compression, a reminder — and then misses on every request after it,
 * because the rewrite was not byte-stable. {@see observeReuse()} reads every
 * provider's split (not only the marking ones: SGLang's radix cache reports
 * it too) and compares each request with the one before it: within a few
 * minutes the earlier request's whole prompt is a prefix the provider should
 * still hold, so reading under half of it is a BREAK — compared only within
 * one conversation (the caller names it): a delegated run in the same
 * process is another conversation with another prefix, and its first
 * request missing the parent's cache is no break. One break after a
 * rewrite is the expected price of the rewrite and is only counted
 * ({@see cacheBreaks()}, {@see lastCacheBreak()}); two in a row means the
 * cache did not recover, and that is said once through the same sink.
 */
final class CacheHealthWatch
{
    /**
     * A request this long after the one before it is not compared: providers
     * evict an idle prefix after about five minutes, and a miss then is an
     * eviction, not a rewrite.
     */
    public const BREAK_IDLE_SECONDS = 240.0;

    /** Prefixes shorter than this are below what providers cache at all. */
    public const BREAK_MIN_TOKENS = 1024;

    private readonly CacheBreakpoints $breakpoints;

    private bool $noticed = false;

    /** @var array{read: int, prompt: int, at: float, conversation: string}|null the previous request's split */
    private ?array $lastSplit = null;

    /** Whether any request so far read from the cache — a break needs a cache that worked. */
    private bool $everCached = false;

    private int $breaks = 0;

    private int $breakStreak = 0;

    /** @var array{from: int, to: int}|null the newest break, as cached percentages */
    private ?array $lastBreak = null;

    private bool $breakNoticed = false;

    public function __construct()
    {
        // Used for its diagnostic only — apply() is never called on it, so
        // whether it would add marks is irrelevant.
        $this->breakpoints = new CacheBreakpoints();
    }

    /**
     * Feed one provider response's usage. Returns the notice raised by THIS
     * observation, or null — including every observation after the one-time
     * notice has gone out.
     */
    public function observe(?Usage $usage): ?string
    {
        $warning = $this->breakpoints->observeCacheHealth($usage);

        if ($warning === null || $this->noticed) {
            return null;
        }

        $this->noticed = true;
        RuntimeNoticeSink::warn($warning);

        return $warning;
    }

    /**
     * Feed one provider response's usage to the break tracker (see the class
     * doc). Returns the notice raised by THIS observation, or null. A
     * response that reported no cache split changes nothing. $conversation
     * names whose request it was; a request of another conversation than the
     * one before it is never compared with it.
     */
    public function observeReuse(?Usage $usage, ?float $now = null, string $conversation = ''): ?string
    {
        $split = $usage === null ? null : ContextBreakdown::cacheSplitOf($usage);
        if ($split === null) {
            return null;
        }
        $now ??= microtime(true);
        $previous = $this->lastSplit;
        $this->lastSplit = $split + ['at' => $now, 'conversation' => $conversation];
        if ($previous !== null && $previous['conversation'] !== $conversation) {
            $previous = null;
            $this->breakStreak = 0;
        }

        $reusable = $previous === null ? 0 : min($split['prompt'], $previous['prompt']);
        $broke = $previous !== null
            && $this->everCached
            && $now - $previous['at'] <= self::BREAK_IDLE_SECONDS
            && $reusable >= self::BREAK_MIN_TOKENS
            && $split['read'] * 2 < $reusable;
        $this->everCached = $this->everCached || $split['read'] > 0;
        if (!$broke) {
            $this->breakStreak = 0;

            return null;
        }

        $this->breaks++;
        $this->breakStreak++;
        $this->lastBreak = [
            'from' => self::percent($previous['read'], $previous['prompt']),
            'to' => self::percent($split['read'], $split['prompt']),
        ];
        if ($this->breakStreak < 2 || $this->breakNoticed) {
            return null;
        }

        $this->breakNoticed = true;
        $warning = sprintf(
            'Prompt cache: %d requests in a row read under half of the prefix the request before them had sent'
            . ' (cached share %d%% → %d%%). Something rewrites bytes the provider already cached on every'
            . ' request; a context rewrite that is not byte-stable (a prune, a compression, a moving reminder)'
            . ' is the usual cause, and each such request is billed at the full input price.',
            $this->breakStreak,
            $this->lastBreak['from'],
            $this->lastBreak['to'],
        );
        RuntimeNoticeSink::warn($warning);

        return $warning;
    }

    /** How many cache breaks this session has seen ({@see observeReuse()}). */
    public function cacheBreaks(): int
    {
        return $this->breaks;
    }

    /**
     * The newest cache break as the cached share before and after it, in
     * whole percent, or null when there has been none.
     *
     * @return array{from: int, to: int}|null
     */
    public function lastCacheBreak(): ?array
    {
        return $this->lastBreak;
    }

    private static function percent(int $read, int $prompt): int
    {
        return $prompt <= 0 ? 0 : (int) round($read * 100 / $prompt);
    }

    /** Whether the one-time notice has been raised this session. */
    public function noticed(): bool
    {
        return $this->noticed;
    }

    /**
     * The carry for the fork's result frame.
     *
     * `reuse` — the break tracker's tally — is written only once it counted
     * a break, so a session without one keeps the shape it had. The previous
     * split is not carried: a turn is one conversation in one process, so
     * the parent's next request is never compared with the child's last.
     *
     * @return array{zeroReports: int, noticed: bool, reuse?: array{breaks: int, lastBreak: ?array{from: int, to: int}, breakNoticed: bool}}
     */
    public function state(): array
    {
        $state = [
            'zeroReports' => $this->breakpoints->zeroReportStreak(),
            'noticed' => $this->noticed,
        ];
        if ($this->breaks > 0 || $this->breakNoticed) {
            $state['reuse'] = [
                'breaks' => $this->breaks,
                'lastBreak' => $this->lastBreak,
                'breakNoticed' => $this->breakNoticed,
            ];
        }

        return $state;
    }

    /**
     * Take over the state a forked child's copy reached. Anything but the
     * shape {@see state()} writes — a frame from a child that predates the
     * key, or garbage — leaves this watch as it was: the streak then restarts
     * at worst, and a notice can only be withheld, never fabricated.
     */
    public function adopt(mixed $state): void
    {
        if (!is_array($state)
            || !is_int($state['zeroReports'] ?? null)
            || !is_bool($state['noticed'] ?? null)) {
            return;
        }

        $this->breakpoints->resumeZeroReportStreak($state['zeroReports']);
        // A notice the child raised has crossed on the notice transport; it
        // must not be raised again here. One the parent already raised stays
        // raised whatever the child believed.
        $this->noticed = $this->noticed || $state['noticed'];
        $this->adoptReuse($state['reuse'] ?? null);
    }

    /**
     * The break tracker's half of {@see adopt()}: the child continued the
     * parent's tally, so the larger count stands; a notice either side raised
     * stays raised. Anything malformed leaves the tracker as it was.
     */
    private function adoptReuse(mixed $reuse): void
    {
        if (!is_array($reuse) || !is_int($reuse['breaks'] ?? null) || !is_bool($reuse['breakNoticed'] ?? null)) {
            return;
        }
        $break = $reuse['lastBreak'] ?? null;
        if ($break !== null && (!is_array($break) || !is_int($break['from'] ?? null) || !is_int($break['to'] ?? null))) {
            return;
        }

        $this->breaks = max($this->breaks, $reuse['breaks']);
        $this->lastBreak = $break === null ? $this->lastBreak : ['from' => $break['from'], 'to' => $break['to']];
        $this->breakNoticed = $this->breakNoticed || $reuse['breakNoticed'];
    }
}

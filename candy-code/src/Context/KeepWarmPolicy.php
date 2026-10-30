<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use SugarCraft\Code\Backend\CacheHealthWatch;
use SugarCraft\Code\Backend;

/**
 * The `promptCache.keepWarm` decision (C-CTX-5.d): warm the provider's
 * prompt cache of an idle session before its TTL lapses, so the next prompt
 * still rides the cached prefix instead of prefilling the whole conversation
 * at full price.
 *
 * WHERE IT SITS NEXT TO THE FOLD: `compaction.idleAuto=cacheTtl` (C-CTX-5.c)
 * buys a summarisation when an idle session is over its floor and the cache
 * clock is about to lapse. Below that floor — or above it but not yet at the
 * fold's hour — the fold predicate says no and the cache dies quietly. This
 * policy is what that `no` can do instead: send the frozen prefix back once,
 * with a single appended instruction row asking for a one-character reply,
 * so the provider refreshes the cache entry for roughly the price of a
 * cached read. It is consulted at most where the fold was declined, never
 * instead of it, and only while the session is genuinely silent — never
 * mid-turn, never past the per-stretch cap.
 *
 * WHY THE PING IS NOT LITERAL `max_tokens: 1`: the request that reuses the
 * cached prefix is exactly `SummarisesWithCache::summariseAsync()` — the
 * conversation as a turn would send it, plus one instruction row, tools
 * advertised but never dispatched. EngineBackend carries no per-call output
 * ceiling (the ceiling is a user-config value resolved at runtime build), so
 * the one-token reply is the instruction's job rather than the wire's, and
 * the ledger bills whatever tiny usage comes back like any other call. The
 * cost is real and the default is off for exactly that reason; no timeout
 * arms on the ping anywhere (E646) — a stalled warm call dies on the
 * engine's existing per-progress ceiling, like the fold's call does.
 *
 * WHO MOVES THE CLOCK: nobody of its own. The policy has no timer; the TUI
 * asks it on C-CTX-5.c's existing 60-second idle-watch tick and the served
 * host on its existing idle alarm, so keep-warm exists precisely while the
 * idle fold watch does (`compaction.idleAuto` on, engine present). Stretch
 * accounting lives on {@see CacheHealthWatch}, keyed by the asking session.
 */
final class KeepWarmPolicy
{
    /** The opt-in: warm pings allowed per idle stretch; 0 (default) is off. */
    public const SETTING = 'promptCache.keepWarm';

    /**
     * Ceiling on a configured cap: a typo'd `1000000` must not turn one
     * idle evening into an unbounded bill. The clamp only lowers; nonsense
     * clamps to 0, which is OFF — failing closed is the only safe direction
     * for a key that spends money unattended.
     */
    public const MAX_PINGS = 24;

    /**
     * The single appended instruction row of a warm ping. Byte-stable on
     * purpose: everything before it is the prefix the cache already holds,
     * and a row that changed per ping would still work (the prefix ends at
     * it) — but a constant row keeps every ping's wire shape identical to
     * its siblings', which is the property the cached-read economics rely on.
     */
    public const PING_INSTRUCTION = 'Prompt-cache keep-warm probe. Reply with exactly one period and nothing else.';

    private function __construct()
    {
    }

    /**
     * The configured cap, clamped. Read by both hosts from their layered
     * `userConfig` snapshot on every decision — the key is Live like the
     * fold keys beside it, so `/settings set promptCache.keepWarm 2` takes
     * effect on the next tick rather than the next launch.
     *
     * @param array<string, mixed> $userConfig
     */
    public static function limitFrom(array $userConfig): int
    {
        $raw = $userConfig[self::SETTING] ?? null;
        if ($raw === null || $raw === '' || is_bool($raw)) {
            return 0;
        }
        if (is_string($raw) && !is_numeric($raw)) {
            return 0;
        }
        if (!is_int($raw) && !is_float($raw) && !is_numeric($raw)) {
            return 0;
        }

        $limit = (int) $raw;
        if ($limit < 1) {
            return 0;
        }

        return min($limit, self::MAX_PINGS);
    }

    /**
     * Whether THIS silent moment earns a warm ping, and nothing else: the
     * caller owns sending it and {@see CacheHealthWatch} owns counting it.
     *
     * The gates, in the order cheap safety answers first:
     *  - off unless the cap is at least 1 ({@see limitFrom()});
     *  - never while a turn is in flight — a running turn writes the cache
     *    itself; the ping exists only for the silence that will not;
     *  - never while the session's previous ping still hangs (one request
     *    per cycle, the dispatch mirror's own rule);
     *  - never for an engine without a cache clock ({@see
     *    IdleCompactionPolicy::cacheTtlSecondsOf()} returning null — the
     *    provider reports it caches nothing, so there is nothing to warm);
     *  - never when the fold took the tick — warming what is already being
     *    compacted would pay for a prefix the fold is about to shorten;
     *  - never below the reuse tracker's own minimum prefix
     *    ({@see CacheHealthWatch::BREAK_MIN_TOKENS}) — a conversation under
     *    1024 tokens is below what providers cache at all, so the ping
     *    would be billed at full prefill, the exact harm it exists to avoid;
     *  - never past the cap counted against THIS idle stretch;
     *  - and on the cache clock: idle (from the later of the activity
     *    anchor and the stretch's last ping) past the same margin the fold
     *    uses, so a ping lands before the entry lapses, not after.
     *
     * @param array<string, mixed> $userConfig
     */
    public static function shouldPing(
        array $userConfig,
        string $sessionKey,
        ?Backend $backend,
        int $tokenCount,
        int $tokenLimit,
        bool $foldDue,
        bool $turnInFlight,
        ?int $idleAnchorTs,
        ?int $now = null,
    ): bool {
        $keepWarm = self::limitFrom($userConfig);
        if ($keepWarm < 1) {
            return false;
        }

        if ($turnInFlight || CacheHealthWatch::warmPingInFlight($sessionKey)) {
            return false;
        }

        $ttl = IdleCompactionPolicy::cacheTtlSecondsOf($backend);
        if ($ttl === null || $foldDue) {
            return false;
        }

        if ($tokenLimit <= 0 || $idleAnchorTs === null || $tokenCount < CacheHealthWatch::BREAK_MIN_TOKENS) {
            return false;
        }

        if (CacheHealthWatch::warmStretchPings($sessionKey, $idleAnchorTs) >= $keepWarm) {
            return false;
        }

        $since = max($idleAnchorTs, CacheHealthWatch::lastWarmPingAt($sessionKey) ?? 0);

        return ($now ?? \time()) - $since > $ttl - IdleCompactionPolicy::CACHE_TTL_MARGIN_SECONDS;
    }
}

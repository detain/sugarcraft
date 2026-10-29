<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workspace;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Throwable;

/**
 * C-AGT-6.e (R13): the PR + CI read-out behind the web's session chips.
 *
 * `gh pr view` is the only truth about a branch's open pull request, and it
 * is a network call — so it must never sit on the summary path: `session.list`
 * answers for the sidebar on every poll and must stay in-process. This cache
 * inverts the call. {@see observe()} registers the watched (root, branch) key
 * and answers purely from what the last refresh stored (null until then); the
 * gh run itself happens off that path, once per TTL, on the caller's loop
 * through {@see GhRunner} (the child-lifetime discipline rides that runner).
 *
 * Watching is self-retiring: an entry is refreshed only while a summary build
 * named it within the last WATCH_WINDOW_SECONDS, so a closed sidebar ends the
 * gh traffic with no explicit unregister. The first fetch is due only
 * FIRST_FETCH_DELAY_SECONDS after registration and an entry survives only as
 * long as it keeps being observed — a single observation can never outlive its
 * watch window before its first due time, which is what keeps one-shot test
 * loops (they pump well under that window, and their roots are deleted) from
 * ever spawning a real gh.
 *
 * Every failure shape — gh missing, non-zero exit, timeout, truncated or
 * malformed JSON, a throw from the runner itself — answers null and retries
 * only on the next TTL beat. The chip is decoration, never an error surface.
 */
final class PrStatusCache
{
    /** How long a fetched answer stands before a refresh is due. */
    public const TTL_SECONDS = 60.0;

    /** Quiet window after registration before a first fetch may run. */
    public const FIRST_FETCH_DELAY_SECONDS = 15.0;

    /** An entry nobody observed inside this window is dropped unrefreshed. */
    public const WATCH_WINDOW_SECONDS = 10.0;

    /** 64 KiB ceiling for one `gh pr view` document (stderr-tail family). */
    public const FETCH_MAX_BYTES = 65536;

    /** The fields one fetch asks gh to print. */
    private const STATUS_JSON_FIELDS = 'number,state,url,statusCheckRollup';

    /** Check-run conclusions that still count as green for the chip. */
    private const PASS_WORDS = ['SUCCESS', 'NEUTRAL', 'SKIPPED'];

    /**
     * Live watch entries keyed by `root . "\0" . branch`.
     *
     * @var array<string, array{branch: string, root: string, registeredAt: float, seenAt: float, fetchedAt: float|null, pr: array<string, mixed>|null}>
     */
    private static array $entries = [];

    private static ?TimerInterface $timer = null;

    private static ?LoopInterface $timerLoop = null;

    /** @var (callable(string, string): array<string, mixed>|null)|null */
    private static $fetcherOverride = null;

    /** @var (callable(): float)|null */
    private static $clockOverride = null;

    /**
     * Register the watch for one summary build and answer from the cache.
     *
     * Never fetches: the returned array is whatever a previous sweep stored,
     * null while the key has no answer. Returns null (and registers nothing)
     * for a session that names no branch or root.
     *
     * @return array{number: int, state: string, url: string, checks: string}|null
     */
    public static function observe(?string $branch, ?string $root, LoopInterface $loop): ?array
    {
        if ($branch === null || $branch === '' || $root === null || $root === '') {
            return null;
        }

        $key = $root . "\0" . $branch;
        $now = self::now();
        if (!isset(self::$entries[$key])) {
            self::$entries[$key] = [
                'branch' => $branch,
                'root' => $root,
                'registeredAt' => $now,
                'seenAt' => $now,
                'fetchedAt' => null,
                'pr' => null,
            ];
        } else {
            self::$entries[$key]['seenAt'] = $now;
        }

        self::arm($loop);

        return self::$entries[$key]['pr'];
    }

    /**
     * One refresh beat: drop dead watches, then fetch at most the one entry
     * whose due time passed longest ago. Bounded work by construction — a
     * summary storm cannot queue a gh run per row through one sweep.
     */
    public static function sweep(): void
    {
        $now = self::now();
        foreach (self::$entries as $key => $entry) {
            if ($entry['seenAt'] + self::WATCH_WINDOW_SECONDS < $now || !\is_dir($entry['root'])) {
                unset(self::$entries[$key]);
            }
        }

        $bestKey = null;
        $bestDue = null;
        foreach (self::$entries as $key => $entry) {
            $due = $entry['fetchedAt'] === null
                ? $entry['registeredAt'] + self::FIRST_FETCH_DELAY_SECONDS
                : $entry['fetchedAt'] + self::TTL_SECONDS;
            if ($due > $now) {
                continue;
            }
            if ($bestDue === null || $due < $bestDue) {
                $bestKey = $key;
                $bestDue = $due;
            }
        }

        if ($bestKey === null) {
            return;
        }

        $entry = self::$entries[$bestKey];
        $pr = self::fetch($entry['branch'], $entry['root']);
        self::$entries[$bestKey]['pr'] = $pr;
        // A failure still stamps the beat: retries ride the TTL, not a hot loop.
        self::$entries[$bestKey]['fetchedAt'] = self::now();
    }

    /**
     * Fold one `statusCheckRollup` array into the chip's single word.
     *
     * gh serves two item shapes here: check runs carry `conclusion`, commit
     * statuses carry `state`, and a queued check may carry only `status`.
     * Any hard failure wins immediately; anything still running or completed
     * without a verdict keeps the chip pending; only an all-green rollup
     * answers pass, and an empty rollup answers pending (no checks reported
     * is not the same fact as every check passing).
     */
    public static function verdict(mixed $rollup): string
    {
        if (!\is_array($rollup) || $rollup === []) {
            return 'pending';
        }

        $unsettled = false;
        foreach ($rollup as $item) {
            if (!\is_array($item)) {
                $unsettled = true;
                continue;
            }
            $conclusion = isset($item['conclusion']) && \is_string($item['conclusion']) ? \strtoupper($item['conclusion']) : '';
            if ($conclusion !== '') {
                if (\in_array($conclusion, self::PASS_WORDS, true)) {
                    continue;
                }
                return 'fail';
            }
            $state = isset($item['state']) && \is_string($item['state']) ? \strtoupper($item['state']) : '';
            if ($state !== '') {
                if ($state === 'SUCCESS') {
                    continue;
                }
                if ($state === 'PENDING' || $state === 'EXPECTED') {
                    $unsettled = true;
                    continue;
                }
                return 'fail';
            }
            // Nothing settled this item — queued, in progress, or a shape we
            // do not read. Pending is the honest word either way.
            $unsettled = true;
        }

        return $unsettled ? 'pending' : 'pass';
    }

    /**
     * Arm the single shared one-shot at the earliest live due time when none
     * is pending; a live timer is kept as-is and never re-compared, so an
     * entry registering just after a beat can wait up to one TTL for its
     * first fetch (the next beat re-arms at the earliest due time, and the
     * chip is decoration).
     * When nothing is live anymore the chain stops on its own.
     */
    private static function arm(LoopInterface $loop): void
    {
        $delay = self::nextDelay();
        if ($delay === null) {
            self::disarm();

            return;
        }

        if (self::$timer !== null) {
            if (self::$timerLoop === $loop) {
                return;
            }
            self::disarm();
        }

        self::$timerLoop = $loop;
        self::$timer = $loop->addTimer($delay, static function () use ($loop): void {
            self::$timer = null;
            self::$timerLoop = null;
            self::sweep();
            if (self::$entries !== []) {
                self::arm($loop);
            }
        });
    }

    /** @return float|null seconds to the next sweep worth waking for, null when nothing is left */
    private static function nextDelay(): ?float
    {
        $now = self::now();
        $earliestDue = null;
        $earliestPrune = null;
        foreach (self::$entries as $entry) {
            if ($entry['seenAt'] + self::WATCH_WINDOW_SECONDS >= $now && \is_dir($entry['root'])) {
                $due = $entry['fetchedAt'] === null
                    ? $entry['registeredAt'] + self::FIRST_FETCH_DELAY_SECONDS
                    : $entry['fetchedAt'] + self::TTL_SECONDS;
                if ($earliestDue === null || $due < $earliestDue) {
                    $earliestDue = $due;
                }
            } else {
                $pruneAt = $entry['seenAt'] + self::WATCH_WINDOW_SECONDS;
                if ($earliestPrune === null || $pruneAt < $earliestPrune) {
                    $earliestPrune = $pruneAt;
                }
            }
        }

        if ($earliestDue !== null) {
            return \max(0.001, $earliestDue - $now);
        }

        return $earliestPrune !== null ? \max(0.001, $earliestPrune - $now) : null;
    }

    private static function disarm(): void
    {
        if (self::$timer !== null && self::$timerLoop !== null) {
            self::$timerLoop->cancelTimer(self::$timer);
        }
        self::$timer = null;
        self::$timerLoop = null;
    }

    /** @return array{number: int, state: string, url: string, checks: string}|null */
    private static function fetch(string $branch, string $root): ?array
    {
        if (self::$fetcherOverride !== null) {
            try {
                return (self::$fetcherOverride)($branch, $root);
            } catch (Throwable) {
                return null;
            }
        }

        return self::fetchViaGh($branch, $root);
    }

    /** @return array{number: int, state: string, url: string, checks: string}|null */
    private static function fetchViaGh(string $branch, string $root): ?array
    {
        if (!\function_exists('proc_open') || !GhRunner::available()) {
            return null;
        }

        try {
            // E646 shape: the bounded per-call gh timeout the runner already
            // defaults to — never a blanket budget on anything wider.
            $captured = GhRunner::new($root)->capture(
                self::FETCH_MAX_BYTES,
                'pr',
                'view',
                $branch,
                '--json',
                self::STATUS_JSON_FIELDS,
            );
        } catch (Throwable) {
            return null;
        }

        if ($captured['timedOut'] || $captured['exitCode'] !== 0 || $captured['truncatedBytes'] > 0) {
            return null;
        }

        $decoded = \json_decode((string) $captured['stdout'], true);
        if (!\is_array($decoded)) {
            return null;
        }

        $number = $decoded['number'] ?? null;
        $state = $decoded['state'] ?? null;
        $url = $decoded['url'] ?? null;
        if (!\is_numeric($number) || (int) $number < 0 || !\is_string($state) || $state === '' || !\is_string($url) || $url === '') {
            return null;
        }

        return [
            'number' => (int) $number,
            'state' => $state,
            'url' => $url,
            'checks' => self::verdict($decoded['statusCheckRollup'] ?? null),
        ];
    }

    private static function now(): float
    {
        return self::$clockOverride === null ? \microtime(true) : (float) (self::$clockOverride)();
    }

    /** @param (callable(string, string): array<string, mixed>|null)|null $fetcher */
    public static function useFetcherForTesting(?callable $fetcher): void
    {
        self::$fetcherOverride = $fetcher;
    }

    /** @param (callable(): float)|null $clock */
    public static function useClockForTesting(?callable $clock): void
    {
        self::$clockOverride = $clock;
    }

    /** Full process-state reset, seams included; test setUp and tearDown. */
    public static function clearForTesting(): void
    {
        self::disarm();
        self::$entries = [];
        self::$fetcherOverride = null;
        self::$clockOverride = null;
    }
}

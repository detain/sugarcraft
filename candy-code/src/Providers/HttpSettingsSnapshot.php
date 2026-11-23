<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * The settings {@see Concerns\HttpClientDefaults} reads, snapshotted once per
 * turn (AUD §7 rank 51).
 *
 * The trait reads `connectTimeoutSeconds`, `streamIdleTimeoutSeconds` and
 * `streamHeaderTimeoutSeconds` off the merged settings, and the two stream
 * bounds are read per streaming REQUEST, so a turn of `maxSteps` provider
 * calls re-read the settings files twice a call. Both stream keys are
 * documented `next turn` (docs/SETTINGS.md: "the engine re-reads the merged
 * settings at every turn start"), so the turn's start is the honest moment to
 * read them: {@see \SugarCraft\Code\Backend\EngineBackend}'s turn installs the
 * config it already reads once per turn with {@see forTurn()}, every HTTP
 * read inside the turn answers from it, and the snapshot ends with the turn's
 * frame however the turn ends (a delegated run's turn nests inside its
 * parent's).
 *
 * A SNAPSHOT BELONGS TO THE FIBER ITS TURN RUNS IN. The chat turn runs in a
 * Fiber under {@see \SugarCraft\Code\Backend\LoopDrivenTurn}, and workflow
 * stages and background agents run their turns in Fibers stepped from the
 * same loop, so one turn can suspend while another starts and installs a
 * newer snapshot. {@see config()} answers with the newest snapshot installed
 * from the CALLER's fiber, so a suspended turn that resumes reads its own
 * turn-start settings, never a later turn's.
 *
 * OUTSIDE A TURN NOTHING CHANGES: with no snapshot installed,
 * {@see config()} reads the merged settings live, guarded the way the trait
 * always guarded it, so a client built at launch or on a provider switch and
 * a request made between turns see the settings as they are now.
 */
final class HttpSettingsSnapshot
{
    /**
     * The installed turns, by token, oldest first: each turn's config and
     * the fiber that installed it (null outside any fiber). Held weakly, so
     * the record never keeps a dead fiber alive; keyed rather than a bare
     * stack so turns that end out of order (two fibers' turns interleaved on
     * one loop) each remove their own and none outlives its turn.
     *
     * @var array<int, array{fiber: \WeakReference<\Fiber<mixed, mixed, mixed, mixed>>|null, config: array<string, mixed>}>
     */
    private static array $turns = [];

    private static int $nextToken = 0;

    /** Snapshots installed since process start: the test seam for "one per turn". */
    private static int $taken = 0;

    private function __construct()
    {
    }

    /**
     * Install $config as the snapshot every HTTP settings read answers from
     * until {@see endTurn()} is handed the token this returns.
     *
     * @param array<string, mixed> $config the merged settings, read once at the turn's start
     */
    public static function beginTurn(array $config): int
    {
        $token = ++self::$nextToken;
        $fiber = \Fiber::getCurrent();
        self::$turns[$token] = [
            'fiber' => $fiber === null ? null : \WeakReference::create($fiber),
            'config' => $config,
        ];
        self::$taken++;

        return $token;
    }

    /**
     * {@see beginTurn()}, held by the returned scope: the snapshot ends when
     * the scope is released or {@see HttpSettingsTurn::end()} is called.
     *
     * @param array<string, mixed> $config the merged settings, read once at the turn's start
     */
    public static function forTurn(array $config): HttpSettingsTurn
    {
        return new HttpSettingsTurn(self::beginTurn($config));
    }

    /** Remove the snapshot {@see beginTurn()} installed under $token. */
    public static function endTurn(int $token): void
    {
        unset(self::$turns[$token]);
    }

    /** Whether a turn's snapshot is installed for the caller's fiber. */
    public static function active(): bool
    {
        return self::callersTurn() !== null;
    }

    /**
     * The settings the HTTP defaults read: the newest snapshot installed
     * from the caller's fiber, else the merged settings read now. A missing
     * or malformed settings file costs the DEFAULT bounds, never a provider
     * client, so a failed read is `[]`.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $turn = self::callersTurn();
        if ($turn !== null) {
            return $turn;
        }

        try {
            return \SugarCraft\Code\Cli\Bootstrap::readUserConfig();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The newest config installed from the caller's fiber, or null when no
     * turn of this fiber is running.
     *
     * @return array<string, mixed>|null
     */
    private static function callersTurn(): ?array
    {
        $current = \Fiber::getCurrent();
        foreach (array_reverse(self::$turns) as $turn) {
            $owner = $turn['fiber'];
            if ($owner === null ? $current === null : ($current !== null && $owner->get() === $current)) {
                return $turn['config'];
            }
        }

        return null;
    }

    /** Snapshots installed since process start (or the last {@see resetForTesting()}). */
    public static function taken(): int
    {
        return self::$taken;
    }

    /** A fresh process: no snapshot, nothing counted. */
    public static function resetForTesting(): void
    {
        self::$turns = [];
        self::$taken = 0;
    }
}

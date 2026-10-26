<?php

declare(strict_types=1);

namespace SugarCraft\Code\Background;

use SugarCraft\Code\Permissions\PermissionMode;

/**
 * One repeating prompt: a name, a cadence, the task, and when it next fires.
 *
 * The row AUT-1 (plan_comparison.md §3) schedules on {@see BackgroundSupervisor}:
 * {@see ScheduleRegistry} persists these as `~/.candy-code/schedules.json`
 * rows, the pumps call {@see ScheduleRegistry::due()}, and a due row spawns a
 * daemon running its {@see self::$prompt} as the task. `/loop` registers one
 * in memory instead (ephemeral: never written, dies with the TUI, and fires
 * through Chat's prompt queue rather than the supervisor).
 *
 * IMMUTABLE LIKE EVERYTHING ELSE ON THIS PATH — every `with*()` returns a new
 * row, and the registry holds the only mutable thing (the roster itself).
 *
 * THE NAME IS ALSO A FILENAME. {@see ScheduleRegistry} takes a per-schedule
 * 0600 lock under `<configHome>/.candy-code/schedules/<name>.lock` so two
 * hosts (two TUIs, a TUI and `serve`) sharing one home never double-fire one
 * row. The pattern in {@see NAME_PATTERN} is what makes that path traversal
 * impossible rather than merely unlikely: no slash, no dot-lead, no colon,
 * at most 64 characters.
 *
 * THE PERMISSION FLOOR. {@see self::$permissionMode} is RECORDED when the row
 * is created and rides every later spawn; a scheduled run never reads the
 * live session's mode, so escalating `/permissions` after the fact cannot
 * widen a cron. A row that names no mode (or an unknown one) lands on
 * {@see PermissionMode::DontAsk} — the non-interactive floor the plan names:
 * read-only tools run, anything that would need a human answer is refused
 * loudly by the daemon's console approver instead of blocking on a question
 * nobody is there to read (the `HeadlessPermissionPrompt` polarity). A real
 * mode spelled by the operator's own hand-edited file rides unchanged —
 * ownership posture, the same one the user-scope `mcp.json` landed on. The
 * one override this floor does make (an unknown spelling) is announced by
 * name at load through {@see ScheduleRegistry::loadErrors()}; it is never a
 * silent rewrite of what the operator asked for.
 */
final class Schedule
{
    /** Names double as lock-file stems; see the class doc-block for why the grammar is this tight. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /** Nothing cron-worthy fires faster than this; a `/loop 1s` is a rate-limit incident waiting to happen. */
    public const MIN_EVERY_SECONDS = 10;

    /** Rows are cadenced in whole seconds — the file format, the tick granularity, and the bound all agree. */
    public const MAX_EVERY_SECONDS = 366 * 86_400;

    /** The floor a missing or unparseable mode falls to; see the class doc-block. */
    public const DEFAULT_PERMISSION_MODE = PermissionMode::DontAsk;

    public readonly string $name;

    public readonly int $everySeconds;

    public readonly string $prompt;

    /** Provider pinned by the row, or null to run on whatever the host session's roster resolves. */
    public readonly ?string $provider;

    /** A {@see PermissionMode} value, settled at construction — see the floor note above. */
    public readonly string $permissionMode;

    /** Unix seconds at which this row next becomes due. */
    public readonly int $nextDue;

    public readonly bool $enabled;

    /** Ephemeral rows (`/loop`) live in one process's memory and are never written to the file. */
    public readonly bool $persisted;

    public function __construct(
        string $name,
        int $everySeconds,
        string $prompt,
        ?string $provider = null,
        ?string $permissionMode = null,
        ?int $nextDue = null,
        bool $enabled = true,
        bool $persisted = true,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(
                "A schedule name must match " . self::NAME_PATTERN . " (it doubles as a lock-file name); got: {$name}",
            );
        }
        if ($everySeconds < self::MIN_EVERY_SECONDS || $everySeconds > self::MAX_EVERY_SECONDS) {
            throw new \InvalidArgumentException(
                "A schedule must repeat every " . self::MIN_EVERY_SECONDS . "s to " . self::MAX_EVERY_SECONDS . "s; got: {$everySeconds}",
            );
        }
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException("Schedule '{$name}' carries no prompt to run.");
        }
        // The mode is NORMALISED here, at the boundary, so nothing downstream
        // re-validates it or trusts a file: an unknown spelling silently
        // becomes the floor. The silence is LOCAL to this constructor: because
        // the coercion changes what the operator spelled, a file row whose
        // spelled mode differs from the settled one is announced by name at
        // load in ScheduleRegistry::load() (which owns the loadErrors
        // channel, read by `/schedules`). An ABSENT mode is not a coercion —
        // the format documents the floor as its default — and a real mode
        // spelled by the operator's own hand-edited file rides unchanged;
        // neither adds a note.
        $mode = $permissionMode === null
            ? self::DEFAULT_PERMISSION_MODE->value
            : (PermissionMode::tryFrom($permissionMode) !== null ? $permissionMode : self::DEFAULT_PERMISSION_MODE->value);

        $this->name = $name;
        $this->everySeconds = $everySeconds;
        $this->prompt = $prompt;
        $this->provider = $provider;
        $this->permissionMode = $mode;
        $this->nextDue = $nextDue ?? 0;
        $this->enabled = $enabled;
        $this->persisted = $persisted;
    }

    /**
     * Parse one file row at the boundary. A value that cannot be trusted is
     * refused HERE, naming the field, rather than entering the roster.
     *
     * `nextDue` may be absent (a hand-written row is armed at $now, not fired
     * the moment it appears). `persisted` is deliberately NOT readable from
     * the wire: an on-disk row is by definition persisted, and the ephemeral
     * flag cannot survive a restart anyway.
     *
     * @param array<array-key, mixed> $row
     * @throws \InvalidArgumentException naming the offending field
     */
    public static function fromArray(array $row, int $now): self
    {
        $name = $row['name'] ?? null;
        if (!is_string($name)) {
            throw new \InvalidArgumentException('schedule row has no string "name"');
        }
        $every = $row['every'] ?? null;
        if (!is_int($every) && !(is_string($every) && ctype_digit($every))) {
            throw new \InvalidArgumentException("schedule '{$name}' has no integer \"every\" (seconds)");
        }
        $prompt = $row['prompt'] ?? null;
        if (!is_string($prompt)) {
            throw new \InvalidArgumentException("schedule '{$name}' has no string \"prompt\"");
        }
        $provider = $row['provider'] ?? null;
        if ($provider !== null && !is_string($provider)) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-string \"provider\"");
        }
        $mode = $row['permissionMode'] ?? null;
        if ($mode !== null && !is_string($mode)) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-string \"permissionMode\"");
        }
        $enabled = $row['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-boolean \"enabled\"");
        }
        $nextDue = $row['nextDue'] ?? null;
        if ($nextDue !== null && !(is_int($nextDue) || (is_string($nextDue) && ctype_digit($nextDue)))) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-integer \"nextDue\"");
        }

        return new self(
            name: $name,
            everySeconds: (int) $every,
            prompt: $prompt,
            provider: $provider,
            permissionMode: $mode,
            nextDue: $nextDue === null ? $now + (int) $every : (int) $nextDue,
            enabled: $enabled,
        );
    }

    /**
     * The file-row form. `model`/`reuseSession`-style keys are NOT part of the
     * first cut — the plan's row is these seven fields and nothing else.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'every' => $this->everySeconds,
            'prompt' => $this->prompt,
            'provider' => $this->provider,
            'permissionMode' => $this->permissionMode,
            'nextDue' => $this->nextDue,
            'enabled' => $this->enabled,
        ];
    }

    /** Whether this row should run at $now. Cadence and enablement decide; nothing else. */
    public function isDue(int $now): bool
    {
        return $this->enabled && $this->nextDue <= $now;
    }

    /**
     * The next due stamp after $now, skipping every occurrence missed since the
     * current one. A host that was down for a week fires ONCE when it returns
     * and reschedules for the next live slot — the overrun skip is this
     * arithmetic, not a queue, so missed counts cannot burst (E646: the bound
     * is due-counts, there is no timeout anywhere on this path).
     */
    public function nextDueAfter(int $now): int
    {
        if ($this->nextDue > $now) {
            return $this->nextDue;
        }

        return $this->nextDue + $this->everySeconds * (intdiv($now - $this->nextDue, $this->everySeconds) + 1);
    }

    public function withNextDue(int $nextDue): self
    {
        return $this->mutate(['nextDue' => $nextDue]);
    }

    public function withEnabled(bool $enabled): self
    {
        return $this->mutate(['enabled' => $enabled]);
    }

    /**
     * Rebuild with named fields replaced, carrying every other forward — the
     * {@see \SugarCraft\Code\Agents\Agent::mutate()} discipline, so a field
     * added to the constructor cannot be dropped by a wither that forgot to
     * spell it.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}

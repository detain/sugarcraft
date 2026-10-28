<?php

declare(strict_types=1);

namespace SugarCraft\Code\Background;

use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Server\ServerConfig;

/**
 * One repeating prompt: a name, a cadence, the task, and when it next fires.
 *
 * The row AUT-1 (plan_comparison.md §3) schedules on {@see BackgroundSupervisor}:
 * {@see ScheduleRegistry} persists these as `~/.candy-code/schedules.json`
 * rows, the pumps call {@see ScheduleRegistry::due()}, and a due row spawns a
 * daemon running its {@see self::$prompt} as the task. `/loop` registers one
 * in memory instead (ephemeral: never written, dies with its host, and fires
 * through the session's prompt queue rather than the supervisor — on a served
 * session through {@see \SugarCraft\Code\Host\SessionHost::tick()}, which
 * fires only the rows stamped with ITS session id, {@see self::$sessionId}).
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
 *
 * THE CLASS THE FLOOR DOES NOT RAISE (C-AGT-7.a). The ceiling matches the
 * webhook's: a FILE row that SPELLS a bypass-class mode —
 * `bypass-permissions` or the floor's own name `dont-ask` — is REFUSED at
 * load, by name, and skipped, because an unattended fire has no one to
 * answer a permission ask either way (`--allow-bypass` governs human-driven
 * sessions only). The distinction is trust, not capability: the floor value
 * is granted by the CODE to rows the file says nothing about, while a
 * spelled mode is granted by the file itself, and this file refuses to let
 * an untrusted hand edit escalate. That is also why {@see toArray()}
 * serialises the settled floor as a null `permissionMode` — writing the
 * spelling back would poison the very file this build wrote.
 *
 * THE CREATION TAG (C-AGT-5.a). The tenth field, {@see self::$createdBy},
 * records WHO authored the row: a row the Schedule tool wrote carries the
 * literal `model`, and everything else — a hand-written line, a row from
 * before the field existed — reads null. It is a TRUST marker, not
 * decoration: the tool's delete guard refuses to remove an untagged row
 * unless the human explicitly opts in, so a prompt-injected model cannot
 * quietly erase an operator's cron while appearing to tidy its own. The tag
 * is therefore read strictly at the file boundary: {@see fromArray()}
 * refuses any spelling but the one the tool writes, because a forged tag is
 * precisely the attack this field exists to police. The constructor stays
 * permissive for the same reason the mode floor does — the wither
 * round-trips (`mutate()`) carry the settled value back in.
 *
 * THE RUN BOUND. Every fire spawns an ordinary background session, so the
 * run is bounded by that contract's timeout — 3600 seconds by default,
 * per-row raisable via {@see self::$timeoutSeconds}. This is the daemon's
 * wall-clock ceiling on the whole run, not a request timeout on any model
 * call (the E646 law untouched), and it exists for the same reason `/bg`
 * has it: a wedged daemon must not run forever unseen.
 *
 * THE ROOT BINDING (C-AGT-1). Every host on this machine reads the SAME
 * home-global file, so a row has to say which project it belongs to:
 * {@see self::$root} is the realpath'd directory its daemon runs in, and
 * {@see ScheduleRegistry} fires the row only on the host whose own root is
 * that same directory — nowhere else, and never by accident of which TUI
 * happened to wake up first. A row with no root belongs to nobody: it still
 * loads, it is listed as unbound, and no host fires it (the file predates this
 * field, so silence is the only thing a legacy row can say). A root spelled
 * for a directory that does not exist is refused HERE, naming the row, like
 * any other untrustworthy value — the operator learns which line is wrong
 * instead of watching a cron run in a checkout they never chose.
 */
final class Schedule
{
    /** Names double as lock-file stems; see the class doc-block for why the grammar is this tight. */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /** Nothing cron-worthy fires faster than this; a `/loop 1s` is a rate-limit incident waiting to happen. */
    public const MIN_EVERY_SECONDS = 10;

    /** Rows are cadenced in whole seconds — the file format, the tick granularity, and the bound all agree. */
    public const MAX_EVERY_SECONDS = 366 * 86_400;

    /**
     * Smallest run bound a row may name (C-AGT-7.a). Below a minute the
     * "bound" would be a race against scheduling latency and would kill the
     * very runs it means to cap; 60 is the same floor {@see MIN_EVERY_SECONDS}
     * draws for cadence, borrowed here for the same "whole-minute world" reason.
     */
    public const MIN_TIMEOUT_SECONDS = 60;

    /** Largest run bound a row may name — one whole year, so "raise it" always has room. */
    public const MAX_TIMEOUT_SECONDS = 366 * 86_400;

    /** The floor a missing or unparseable mode falls to; see the class doc-block. */
    public const DEFAULT_PERMISSION_MODE = PermissionMode::DontAsk;

    /**
     * The only spelling {@see self::$createdBy} may carry in a file row (see
     * the creation-tag note in the class doc-block): the tag the Schedule tool
     * writes. Absence means the operator; a row never claims any other author.
     */
    public const CREATED_BY_MODEL = 'model';

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

    /**
     * The realpath'd project directory this row is bound to, or null when the
     * row carries no binding — see the class doc-block's root-binding note.
     * Ephemeral rows never have one: a `/loop` lives where its TUI lives.
     */
    public readonly ?string $root;

    /**
     * The session whose `/loop` registered this EPHEMERAL row (C-AGT-1.b).
     * A served host fires only the rows carrying its own id, and lets go of
     * them when the session closes, so two browser tabs sharing one serve
     * process cannot fire each other's loops. NEVER serialized: an ephemeral
     * row has no life after this process, so there is nothing for the file
     * to remember, and {@see fromArray()} never reads such a key. The TUI
     * stamps the same field (its pump ignores it for now — it fires every
     * in-process loop row) so one grammar answers both drivers.
     */
    public readonly ?string $sessionId;

    /**
     * Wall-clock ceiling on ONE fired run (C-AGT-7.a P4), in seconds, or null
     * for the background session's own default (3600 — single-sourced in
     * {@see BackgroundSupervisor::spawnSession()}, never mirrored here).
     * The row's raisable knob for a task that legitimately runs longer than
     * an hour. This is the daemon's RUN bound, not a timeout on any model
     * request — the E646 law does not live on this field; the ceiling that IS
     * enforced against untrusted spellings is the permission-mode one in
     * {@see fromArray()}.
     */
    public readonly ?int $timeoutSeconds;

    /**
     * Who created this row (C-AGT-5.a): {@see self::CREATED_BY_MODEL} when the
     * Schedule tool wrote it, null for every other origin — a hand-edited line,
     * a legacy row, or an in-process grant that never spells a tag. The tool's
     * delete guard only removes rows carrying the tag without further asking;
     * untagged rows need the human's explicit opt-in, so the marker is what
     * keeps a prompt-injected model from quietly erasing an operator's cron.
     * {@see fromArray()} reads it strictly: only the model spelling or absence;
     * the constructor itself stays permissive (the wither round-trips pass the
     * settled value back in).
     */
    public readonly ?string $createdBy;

    public function __construct(
        string $name,
        int $everySeconds,
        string $prompt,
        ?string $provider = null,
        ?string $permissionMode = null,
        ?int $nextDue = null,
        bool $enabled = true,
        bool $persisted = true,
        ?string $root = null,
        ?string $sessionId = null,
        ?int $timeoutSeconds = null,
        ?string $createdBy = null,
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
        if ($timeoutSeconds !== null
            && ($timeoutSeconds < self::MIN_TIMEOUT_SECONDS || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS)
        ) {
            throw new \InvalidArgumentException(
                "Schedule '{$name}' names a \"timeoutSeconds\" of {$timeoutSeconds}; it must be between " . self::MIN_TIMEOUT_SECONDS . ' and ' . self::MAX_TIMEOUT_SECONDS . ' seconds, or absent for the session default.',
            );
        }
        // The mode is NORMALISED here, at the boundary, so nothing downstream
        // re-validates it or trusts a file: an unknown spelling silently
        // becomes the floor. The silence is LOCAL to this constructor: because
        // the coercion changes what the operator spelled, a file row whose
        // spelled mode differs from the settled one is announced by name at
        // load in ScheduleRegistry::load() (which owns the loadErrors
        // channel, read by `/schedules`). An ABSENT mode is not a coercion —
        // the format documents the floor as its default — and a real mode
        // spelled by the operator's own hand-edited file rides unchanged
        // THROUGH HERE; the ceiling that refuses bypass-class spellings lives
        // at the file boundary in {@see fromArray()}, deliberately not in this
        // constructor, because the wither round-trips (`mutate()`) pass the
        // settled floor value back in and a constructor-level refusal would
        // turn every `withEnabled()` of a floor row into an exception.
        $mode = $permissionMode === null
            ? self::DEFAULT_PERMISSION_MODE->value
            : (PermissionMode::tryFrom($permissionMode) !== null ? $permissionMode : self::DEFAULT_PERMISSION_MODE->value);

        // The binding is RESOLVED here, at the boundary, for the same reason
        // the mode is normalised here: two spellings of one directory (`repo/`
        // and `./repo`) must not become two different owners of one row, and a
        // path that cannot be found is a wrong line in the file rather than a
        // row that silently fires in some other checkout. An ABSENT root is
        // not an error — it is a row written before this field existed — and
        // {@see ScheduleRegistry} is what keeps such a row from firing.
        $resolved = null;
        if ($root !== null && $root !== '') {
            $found = realpath($root);
            if ($found === false) {
                throw new \InvalidArgumentException(
                    "Schedule '{$name}' names a \"root\" directory that does not exist: {$root}",
                );
            }
            $resolved = $found;
        }

        $this->name = $name;
        $this->everySeconds = $everySeconds;
        $this->prompt = $prompt;
        $this->provider = $provider;
        $this->permissionMode = $mode;
        $this->nextDue = $nextDue ?? 0;
        $this->enabled = $enabled;
        $this->persisted = $persisted;
        $this->root = $resolved;
        $this->sessionId = $sessionId;
        $this->timeoutSeconds = $timeoutSeconds;
        // The tag is TRIMMED to absence, never validated: the file-boundary
        // strictness is {@see fromArray()}'s job, exactly like the mode
        // normalisation above — an in-process grant that spells nothing (the
        // `/loop` row, a legacy wither) reads null, not ''.
        $this->createdBy = $createdBy === null || trim($createdBy) === '' ? null : $createdBy;
    }

    /**
     * Parse one file row at the boundary. A value that cannot be trusted is
     * refused HERE, naming the field, rather than entering the roster.
     *
     * `nextDue` may be absent (a hand-written row is armed at $now, not fired
     * the moment it appears). `root` may be absent as well, and means what its
     * absence has always meant here: a row written before the binding existed,
     * which no host fires — see {@see ScheduleRegistry::load()}.
     * `timeoutSeconds` may be absent, and means the background session's own
     * default (C-AGT-7.a P4). A present `permissionMode` is refused here when
     * it spells a bypass-class mode (C-AGT-7.a P3) — see the ceiling note in
     * the class doc-block; unknown spellings stay the constructor's business
     * (floor + load-time announcement), and null means the same as absence.
     * `persisted` is deliberately NOT readable from
     * the wire: an on-disk row is by definition persisted, and the ephemeral
     * flag cannot survive a restart anyway.
     * `createdBy` may be ABSENT — that is an operator row, the default and the
     * legacy shape — and the literal `model` is the only spelling a row may
     * carry (C-AGT-5.a); anything else is refused by name at this boundary,
     * because the tag gates the Schedule tool's delete privilege.
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
        // C-AGT-7.a P3, the ceiling beside the floor: a FILE row that spells a
        // bypass-class mode is refused here — at the trust boundary — rather
        // than coerced, with the same reasoning the webhook loader uses
        // ({@see ServerConfig::answersAsksWithoutAHuman()}, consulted so the
        // two surfaces cannot drift on which modes answer asks without a
        // human). Unknown spellings are NOT this arm's business: they fall to
        // the floor and are announced by ScheduleRegistry::load(), as they
        // always have been. The constructor stays permissive on purpose — its
        // bypass rides are in-process grants (a `/loop` row, a `mutate()`
        // round-trip of the settled floor), and refusing there would break
        // every wither of every floor row.
        $modeEnum = $mode === null ? null : PermissionMode::tryFrom($mode);
        if ($modeEnum !== null && ServerConfig::answersAsksWithoutAHuman($modeEnum)) {
            throw new \InvalidArgumentException(
                "schedule '{$name}': permission mode \"{$mode}\" is refused for schedules — a schedule fires unattended, with no one to answer a permission ask; leave \"permissionMode\" out of the row to run on the floor the code grants",
            );
        }
        $enabled = $row['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-boolean \"enabled\"");
        }
        $nextDue = $row['nextDue'] ?? null;
        if ($nextDue !== null && !(is_int($nextDue) || (is_string($nextDue) && ctype_digit($nextDue)))) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-integer \"nextDue\"");
        }
        $root = $row['root'] ?? null;
        if ($root !== null && !is_string($root)) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-string \"root\"");
        }
        // C-AGT-7.a P4: the optional run bound. Same grammar as `nextDue` —
        // int or digit-string, anything else named and refused; ABSENT is not
        // a value, it is the session default (3600), and stays null all the
        // way to the spawner so the single source in spawnSession keeps
        // answering.
        $timeout = $row['timeoutSeconds'] ?? null;
        if ($timeout !== null && !(is_int($timeout) || (is_string($timeout) && ctype_digit($timeout)))) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-integer \"timeoutSeconds\"");
        }
        // C-AGT-5.a, the creation tag read STRICTLY at the boundary. Only the
        // model spelling may be spelled; absence is the operator's own
        // statement. A forged or misspelled tag is refused by name rather than
        // coerced, because the tag gates the tool's delete privilege — a value
        // that quietly became something else would hand that privilege to (or
        // hide it from) the wrong rows.
        $creator = $row['createdBy'] ?? null;
        if ($creator !== null && !is_string($creator)) {
            throw new \InvalidArgumentException("schedule '{$name}' carries a non-string \"createdBy\"");
        }
        if ($creator !== null && $creator !== '' && $creator !== self::CREATED_BY_MODEL) {
            throw new \InvalidArgumentException(
                'schedule \'' . $name . '\' spells "createdBy" as "' . $creator . '"; only "' . self::CREATED_BY_MODEL . '" may be spelled — leave the key out for an operator row',
            );
        }

        return new self(
            name: $name,
            everySeconds: (int) $every,
            prompt: $prompt,
            provider: $provider,
            permissionMode: $mode,
            nextDue: $nextDue === null ? $now + (int) $every : (int) $nextDue,
            enabled: $enabled,
            root: $root,
            timeoutSeconds: $timeout === null ? null : (int) $timeout,
            createdBy: $creator === '' ? null : $creator,
        );
    }

    /**
     * The file-row form. `model`/`reuseSession`-style keys are NOT part of
     * this format — the plan's row is these ten fields and nothing else.
     *
     * `createdBy` serialises as the literal `model` only on rows the Schedule
     * tool created (C-AGT-5.a); every other origin writes a null, which the
     * next load reads as absence — the operator's statement, per the tag note
     * in the class doc-block.
     *
     * `permissionMode` serialises the settled FLOOR as null rather than as
     * the spelling `dont-ask`: {@see fromArray()} refuses bypass-class
     * spellings (C-AGT-7.a P3), so writing the floor's name back would make
     * this build's own output unreadable by the next load — a file that
     * poisons itself. Null means exactly what absence means on the read side,
     * and a non-floor mode rides spelled, as recorded.
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
            'permissionMode' => $this->permissionMode === self::DEFAULT_PERMISSION_MODE->value
                ? null
                : $this->permissionMode,
            'nextDue' => $this->nextDue,
            'enabled' => $this->enabled,
            'root' => $this->root,
            'timeoutSeconds' => $this->timeoutSeconds,
            'createdBy' => $this->createdBy,
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
     * arithmetic, not a queue, so missed counts cannot burst (the bound on
     * this path is due-COUNTS; no timer bounds the act of firing itself. The
     * run a fire spawns does carry the ordinary background-session wall-clock
     * bound — default 3600s, per-row raisable, {@see self::$timeoutSeconds} —
     * which is a daemon ceiling, not a timeout on any model request; E646
     * untouched).
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

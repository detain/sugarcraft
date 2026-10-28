<?php

declare(strict_types=1);

namespace SugarCraft\Code\Background;

use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Server\ServerConfig;

/**
 * The single creation path for persisted schedule rows (C-AGT-5.a).
 *
 * WHY A FACTORY AT ALL. Two surfaces create rows: the Schedule tool
 * (C-AGT-5.a) and the `/schedules add` verb (C-AGT-5.b). The bounds that
 * matter — the permission-mode ceiling, the model-row cap, the root binding,
 * the
 * one-interval arming — are exactly the things two hand-rolled callers would
 * drift apart on, so they live HERE, once, and both surfaces will call through.
 * {@see Schedule}'s constructor still owns the value-level validation (name
 * grammar, cadence bounds, timeout bounds, mode normalisation); this class owns
 * the POLICY that only a creator can know.
 *
 * THE CEILING (C-AGT-7.a, enforced at creation as well as at load). A created
 * row's mode is the stricter of the requested spelling and the session's live
 * mode, and a requested spelling that answers asks without a human —
 * `bypass-permissions` or the floor's own name `dont-ask` — is refused BY
 * NAME, never coerced, consulting the same
 * {@see ServerConfig::answersAsksWithoutAHuman()} set the file loader reads so
 * the two surfaces cannot drift. Unknown spellings are refused HERE rather
 * than fallen through to the floor: an operator hand-editing a file gets the
 * announced-floor treatment ({@see ScheduleRegistry::loadErrors()}) because a
 * typo in a line they wrote deserves a repair, not a rewrite, but a model
 * asked to name a mode and naming nonsense must be answered loudly, at the
 * call that asked. NO settled value this factory produces can ever spell a
 * bypass-class mode: the floor is a code grant serialised as null
 * ({@see Schedule::toArray()}), and a stricter-of result can only be the
 * session's mode or the requested one — the requested bypass-class ones above,
 * and a session whose own mode is bypass lands on the stricter request (or the
 * floor) rather than keeping rank 0.
 *
 * THE CAP. At most {@see MODEL_ROW_CAP} rows tagged
 * {@see Schedule::CREATED_BY_MODEL} may exist for one project root. Eight
 * recurring prompts per project is enough for any real automation this tool
 * can justify and small enough that a prompt-injected session cannot
 * carpet-bomb the supervisor's roster on its way past an Ask approval. The cap
 * is per-root and counts ONLY model-tagged rows: an operator's hand-written
 * rows are their own statement, and a row bound to another project is not this
 * project's quota. Eight was chosen over four or sixteen as the number a busy
 * real project might plausibly want (CI sweep, changelog, issue triage,
 * dependency audit, weekly summaries…) while staying an order of magnitude
 * below the count that would make the roster unreadable — a ceiling only works
 * if the honest use never tests it.
 *
 * THE ROOT BINDING (C-AGT-1.a). A row created without a project to fire in is
 * a dead row: {@see ScheduleRegistry::fireDue()} skips every unbound row
 * forever. In-process callers could at least list what they made; a MODEL
 * creating one would get a success sentence about a schedule that can never
 * run, so this factory REFUSES a create that arrives with no host root rather
 * than persist a lie. (A plain launch always has one — the tool is built from
 * {@see \SugarCraft\Code\Tools\Catalog\ToolBuildContext::$root} — so this arm
 * exists for embedded and future hosts that cannot say where they live.)
 *
 * THE DELETE POLICY (C-AGT-5.b, root-scoped per the owner ruling closing
 * C-AGT-5.a r1 MINOR-2). {@see deleteRefusal()} is the guard the Schedule
 * tool's `delete` consults, and it scopes the tag: a model-tagged row bound
 * to THIS project root goes freely, because the human approved its creation
 * and the same approval sees its removal; a model-tagged row bound to
 * ANOTHER root does not — the file is shared machine-wide (C-AGT-1), and a
 * session in one project tidying another project's automation is exactly the
 * cross-project reach a cap without a fence would have granted. Operator rows
 * keep the rule they always had. `include_operator_rows: true` opens all of
 * it at once — and because the tool is Ask-class, spelling it puts the intent
 * in front of the human verbatim. The human surface (`/schedules delete`) is
 * deliberately NOT behind this guard: a person typing a name owns every row in
 * their own home file, which is why the refusal sentences point there.
 */
final class ScheduleFactory
{
    /** Model-created rows per project root; see the cap note in the class doc-block. */
    public const MODEL_ROW_CAP = 8;

    private function __construct() {}

    /**
     * How many persisted rows this root has that carry the model tag.
     * The comparison is strict on BOTH sides: a null root matches only unbound
     * rows (which the factory no longer creates, but a legacy file may carry),
     * and operator-tagged or untagged rows never count.
     */
    public static function modelRowCount(ScheduleRegistry $registry, ?string $hostRoot): int
    {
        $count = 0;
        foreach ($registry->all() as $row) {
            if ($row->persisted && $row->createdBy === Schedule::CREATED_BY_MODEL && $row->root === $hostRoot) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The guard on a tool-side delete: the refusal sentence, or null when this
     * row may go. Arms in order of breadth — the human-spelled door opens
     * everything, then the creation tag, then the root. The operator-row
     * sentence is byte-frozen from C-AGT-5.a (it is pinned there); the
     * cross-root sentence is new in C-AGT-5.b and names the fact it refuses on
     * so the model can tell "wrong project" from "not mine to touch".
     */
    public static function deleteRefusal(Schedule $row, ?string $hostRoot, bool $includeOperatorRows): ?string
    {
        if ($includeOperatorRows) {
            return null;
        }
        if ($row->createdBy !== Schedule::CREATED_BY_MODEL) {
            return "schedule '{$row->name}' was not created by this tool — deleting an operator-written row needs include_operator_rows: true (the approval will show it), or /schedules delete {$row->name} from a human prompt";
        }
        if ($row->root !== $hostRoot) {
            $bound = $row->root ?? 'unbound';

            return "schedule '{$row->name}' was created by this tool but is bound to another project root ({$bound}); deleting a row outside this project needs include_operator_rows: true (the approval will show it), or /schedules delete {$row->name} from a human prompt";
        }

        return null;
    }

    /**
     * The mode a created row may carry: null means "say nothing, take the
     * floor", and a spelled request lands at most as loose as the session.
     *
     * @throws \InvalidArgumentException naming an unknown or bypass-class request
     */
    public static function settleMode(?string $requested, PermissionMode $sessionMode): ?string
    {
        if ($requested === null || trim($requested) === '') {
            return null;
        }
        $wanted = PermissionMode::tryFrom($requested);
        if ($wanted === null) {
            $allowed = implode(', ', array_map(
                static fn(PermissionMode $mode): string => $mode->value,
                PermissionMode::cases(),
            ));
            throw new \InvalidArgumentException(
                "unknown permission mode \"{$requested}\"; spell one of: {$allowed} — or omit permission_mode to run on the non-interactive floor",
            );
        }
        if (ServerConfig::answersAsksWithoutAHuman($wanted)) {
            throw new \InvalidArgumentException(
                "permission mode \"{$requested}\" is refused for schedules — a schedule fires unattended, with no one to answer a permission ask; omit permission_mode to run on the floor the code grants",
            );
        }

        return $sessionMode->stricterOf($wanted)->value;
    }

    /**
     * Validate, bound, register and PERSIST one model-created row.
     *
     * The roster reloads before the cap is counted: a peer host that added a
     * row since this launch read the file moves this root's quota, and a cap
     * that counted a stale roster could be walked past by two hosts that each
     * saw one row too few.
     *
     * @param array<array-key, mixed> $args the tool's `create` arguments
     * @throws \InvalidArgumentException naming the offending field (shape, ceiling, cap, binding)
     * @throws \RuntimeException on a failed write-back (lock contention, an
     *         unwritable home) — the row is withdrawn from the roster before
     *         this escapes, so no ghost outlives the caller's refusal
     */
    public static function create(array $args, ?string $hostRoot, PermissionMode $sessionMode, ScheduleRegistry $registry, int $now): Schedule
    {
        $registry->reloadIfChanged();

        if ($hostRoot === null || $hostRoot === '') {
            throw new \InvalidArgumentException(
                'this host has no project directory to bind the row to — a schedule created without a root can never fire, so it is refused rather than written',
            );
        }

        $name = $args['name'] ?? null;
        if (!\is_string($name) || trim($name) === '') {
            throw new \InvalidArgumentException('create needs a "name": a lowercase lock-safe identifier matching ' . Schedule::NAME_PATTERN);
        }
        $every = $args['every'] ?? null;
        if (!\is_int($every) && !(\is_string($every) && ctype_digit($every))) {
            throw new \InvalidArgumentException("schedule \"{$name}\": \"every\" must be an integer number of seconds");
        }
        $prompt = $args['prompt'] ?? null;
        if (!\is_string($prompt) || trim($prompt) === '') {
            throw new \InvalidArgumentException("schedule \"{$name}\": create needs a \"prompt\" — the task the daemon runs");
        }
        $provider = $args['provider'] ?? null;
        if ($provider !== null && !\is_string($provider)) {
            throw new \InvalidArgumentException("schedule \"{$name}\": \"provider\" must be a string or absent");
        }
        $requestedMode = $args['permission_mode'] ?? null;
        if ($requestedMode !== null && !\is_string($requestedMode)) {
            throw new \InvalidArgumentException("schedule \"{$name}\": \"permission_mode\" must be a string or absent");
        }
        $timeout = $args['timeout_seconds'] ?? null;
        if ($timeout !== null && !\is_int($timeout) && !(\is_string($timeout) && ctype_digit($timeout))) {
            throw new \InvalidArgumentException("schedule \"{$name}\": \"timeout_seconds\" must be an integer or absent");
        }
        // C-AGT-5.c: the kind door. A strict membership test — an unknown
        // spelling is refused by name, never guessed. Silently defaulting a
        // misspelled "hearbeat" to task would delete the alerts the caller
        // asked for; defaulting it to heartbeat would ring on every unrelated
        // answer. Only an ABSENT kind means task.
        $kind = $args['kind'] ?? null;
        if ($kind !== null && !\in_array($kind, Schedule::KINDS, true)) {
            $spelled = implode(' or ', Schedule::KINDS);
            throw new \InvalidArgumentException(
                "schedule \"{$name}\": \"kind\" must be {$spelled} — or omit kind to create a plain task",
            );
        }

        $mode = self::settleMode($requestedMode, $sessionMode);

        $held = self::modelRowCount($registry, $hostRoot);
        if ($held >= self::MODEL_ROW_CAP) {
            throw new \InvalidArgumentException(
                'this project already carries ' . self::MODEL_ROW_CAP . ' model-created schedules (the cap); delete one first with action "delete" (list shows them) — operator-written rows in schedules.json do not count against it',
            );
        }

        $row = new Schedule(
            name: $name,
            everySeconds: (int) $every,
            prompt: $prompt,
            provider: $provider,
            permissionMode: $mode,
            nextDue: $now + (int) $every,
            persisted: true,
            root: $hostRoot,
            timeoutSeconds: $timeout === null ? null : (int) $timeout,
            createdBy: Schedule::CREATED_BY_MODEL,
            kind: $kind ?? Schedule::KIND_TASK,
        );
        $registry->add($row);
        try {
            $registry->persist();
        } catch (\RuntimeException $e) {
            // add() already rostered the row. A write-back that fails must not
            // leave it behind: the digest latch sees no change on disk, so no
            // later reload clears the ghost and every `list` this process then
            // answers would lie about a row the file never received. Withdraw
            // it before the exception reaches the caller's refusal — the same
            // roster/answer honesty the delete path keeps by removing in
            // memory before persisting.
            $registry->remove($row->name);

            throw $e;
        }

        return $row;
    }
}

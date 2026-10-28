<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleFactory;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Host\Commands\LoopCommand;
use SugarCraft\Code\Host\Commands\SchedulesCommand;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Creates, lists and deletes schedule rows (C-AGT-5.a, GAP-TL-22).
 *
 * THE CHANNEL IS THE FILE. A turn runs forked, so there is no long-lived
 * process here to hold a clock: this tool writes PERSISTED rows into
 * `~/.candy-code/schedules.json` through {@see ScheduleRegistry}, under its
 * lock, and every host — this session's pump, another TUI, `serve` — reloads
 * by digest and fires them on their own cadence. The registry this tool builds
 * is the home-backed one anchored at the launch root
 * ({@see ScheduleRegistry::loadDefault()}), so a created row lands bound to
 * the project that made it (C-AGT-1.a) and tagged
 * {@see Schedule::CREATED_BY_MODEL} — the marker the delete guard below (and
 * the operator's own `/schedules` eye) reads.
 *
 * PERMISSION CLASS: Ask, and it has to be. A schedule is a PROMISE THAT
 * OUTLIVES THE TURN: the prompt stored here re-enters the model unattended,
 * every interval, possibly for months, and a prompt-injected session that
 * could mint one silently would have bought a recurring write primitive. The
 * human sees the exact call — name, cadence, prompt, bound root — before it
 * lands, at every action, including `list`: the tool is a control surface,
 * gated as one whole.
 *
 * THE CEILING AND THE CAP (the bounds the risk line demands, enforced in
 * {@see ScheduleFactory}, not here). A created row's permission mode is no
 * looser than the session's and never bypass-class — an unattended fire has no
 * one to answer an ask, so the default is the `dont-ask` floor and the
 * session's own mode is the loosest spelling that survives. At most
 * {@see ScheduleFactory::MODEL_ROW_CAP} model-tagged rows exist per root: an
 * injected model that does talk a human into one create cannot carpet-bomb the
 * roster. The cadence floor ({@see Schedule::MIN_EVERY_SECONDS}) is the row
 * class's own.
 *
 * THE DELETE GUARD (root-scoped as of C-AGT-5.b). `delete` removes a
 * model-tagged row bound to THIS project on its own; a row the operator wrote
 * (or hand-edited into the file) — and, since the guard learned to read the
 * root binding, a model row bound to ANOTHER project — is refused UNLESS the
 * call spells `include_operator_rows: true`, and because the tool is
 * Ask-class, spelling it means the human sees exactly that argument before it
 * runs. The flag is not a bypass, it is the asking, made visible in the
 * transcript. `/schedules delete` stays the unrestricted human surface.
 *
 * NO LOOPS FROM SCHEDULES. A fired run executes under its recorded mode (the
 * dont-ask floor unless the human spelled otherwise), where any tool call that
 * would need a human answer is REFUSED rather than asked — so a scheduled turn
 * cannot use this tool to mint another schedule or widen its own mode. The
 * clock cannot escalate itself. (A row an operator spells looser — say
 * `accept-edits` — can do more unattended, which is exactly why the spelled
 * choice is the human's, visible at the create approval.)
 *
 * The result prose stays English in the code like every other tool's: a tool
 * result is the model's channel, not a screen string (lang/en.php header).
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::Ask, position: 41, gloss: 'create, list or delete schedules that fire recurring background prompts')]
final readonly class ScheduleTool implements Tool, BuildsFromCatalog, DelegatesToEngine, TakesToolCallId
{
    public const NAME = 'Schedule';

    private function __construct(
        private ?string $root = null,
        private ?ScheduleRegistry $registry = null,
        private ?PermissionMode $sessionMode = null,
    ) {}

    public static function new(): self
    {
        return new self();
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root, ScheduleRegistry::loadDefault($context->root));
    }

    public function withEngine(\SugarCraft\Code\Backend\EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): self
    {
        return new self($this->root, $this->registry, $engine->permissionGate()?->mode());
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Create, list, or delete schedules: recurring prompts that fire as supervised background runs in this project, stored in ~/.candy-code/schedules.json and kept by every candy-code host on this machine. A row created here repeats every N seconds (first fire one interval out), runs unattended on the permission mode settled at creation — never looser than this session\'s, never bypass-class, by default the read-only floor that refuses anything that would need asking — and carries this project as its root, so only a host in that directory ever fires it. This project holds at most 8 model-created rows; delete frees a slot. A delete removes a row this tool created for this project; deleting an operator-written row — or a model row bound to another project root — requires include_operator_rows: true, which the approval shows verbatim. Every call here asks a human first: a schedule outlives the turn that made it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'list', 'delete'],
                    'description' => 'What to do: create a row, list the file (and its load notes), or delete one row.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'The row\'s identity (required for create and delete). Lowercase letter or digit first, then lowercase letters, digits, ".", "_", "-"; at most 64 characters — it doubles as a lock-file stem.',
                ],
                'every' => [
                    'type' => 'integer',
                    'description' => 'create: cadence in whole seconds, from 10 up to 31,622,400 (366 days). The first fire lands one interval out.',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'create: the task the daemon runs each fire. Never empty; multi-line stays multi-line.',
                ],
                'provider' => [
                    'type' => 'string',
                    'description' => 'create: pin the provider for this row\'s runs. Absent means whatever the host session\'s roster resolves.',
                ],
                'permission_mode' => [
                    'type' => 'string',
                    'description' => 'create: the mode the unattended runs use. Refused when it names a mode that answers asks without a human (bypass-permissions, dont-ask); never looser than this session\'s own mode. Omit to run on the read-only-with-loud-refusals floor — the right answer for almost every schedule.',
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'description' => 'create: optional wall-clock ceiling on one fired run, 60 up to 31,622,400 seconds. Absent means the background session default of 3600.',
                ],
                'include_operator_rows' => [
                    'type' => 'boolean',
                    'description' => 'delete only: also remove a row this tool did not create, or a model row bound to another project root. Without it, deleting either is refused; /schedules delete <name> is the human surface for those.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @param array<array-key, mixed> $args
     */
    public function execute(array $args): ToolResult
    {
        if ($this->registry === null) {
            return $this->refusal($args, 'no schedule registry is attached to this launch');
        }

        // The file is shared by every host on the machine; answer from the
        // roster the file currently says (digest latch — free when nothing
        // moved). create reloads again inside the factory before the cap
        // count; the latch makes the second look a no-op.
        $this->registry->reloadIfChanged();

        $action = $args['action'] ?? 'list';
        if (!\is_string($action)) {
            return $this->refusal($args, self::USAGE);
        }

        return match (strtolower($action)) {
            'list' => new ToolResult(
                toolCallId: (string) ($args['id'] ?? ''),
                content: SchedulesCommand::listing($this->registry),
            ),
            'create' => $this->createRow($args),
            'delete' => $this->deleteRow($args),
            default => $this->refusal($args, self::USAGE),
        };
    }

    /** Sub-command grammar for a wrong or missing `action`. */
    private const USAGE = 'action must be one of: create (needs name, every, prompt), list, delete (needs name)';

    /**
     * @param array<array-key, mixed> $args
     */
    private function createRow(array $args): ToolResult
    {
        try {
            $row = ScheduleFactory::create(
                $args,
                $this->root,
                $this->sessionMode ?? PermissionMode::Default,
                $this->registry,
                time(),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->refusal($args, $e->getMessage());
        }

        return new ToolResult(
            toolCallId: (string) ($args['id'] ?? ''),
            content: sprintf(
                "schedule '%s' created in schedules.json: every %s, first fire at %s, bound to %s. It fires as a supervised background run under %s until deleted.",
                $row->name,
                LoopCommand::everyLabel($row->everySeconds),
                date('Y-m-d H:i:s', $row->nextDue),
                $row->root ?? 'no root',
                $row->permissionMode,
            ),
        );
    }

    /**
     * @param array<array-key, mixed> $args
     */
    private function deleteRow(array $args): ToolResult
    {
        $name = $args['name'] ?? null;
        if (!\is_string($name) || trim($name) === '') {
            return $this->refusal($args, 'delete needs the schedule "name" to remove');
        }

        $row = $this->registry->get($name);
        if ($row === null) {
            return $this->refusal($args, "no schedule named '{$name}'; the list action shows what exists");
        }

        // The shared guard (root-scoped since C-AGT-5.b): model row of THIS
        // root goes free; operator rows and foreign-root model rows need the
        // human-spelled door, which the Ask approval shows verbatim.
        $refusal = ScheduleFactory::deleteRefusal($row, $this->root, ($args['include_operator_rows'] ?? null) === true);
        if ($refusal !== null) {
            return $this->refusal($args, $refusal);
        }

        $this->registry->remove($name);
        try {
            $this->registry->persist();
        } catch (\RuntimeException $e) {
            return $this->refusal($args, "schedule '{$name}' was removed in this process but could not be written back: {$e->getMessage()}");
        }

        return new ToolResult(
            toolCallId: (string) ($args['id'] ?? ''),
            content: "schedule '{$name}' deleted from schedules.json.",
        );
    }

    /**
     * @param array<array-key, mixed> $args
     */
    private function refusal(array $args, string $reason): ToolResult
    {
        return new ToolResult(
            toolCallId: (string) ($args['id'] ?? ''),
            content: $reason,
            isError: true,
        );
    }
}

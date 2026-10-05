<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\PermissionModeToggledMsg;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\RelaysPermissionAsks;
use SugarCraft\Crush\Tools\TakesToolCallId;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Plan mode's way out (roadmap 5.7-2): the model hands the user the plan it
 * wrote to `.sugar-crush/plans/` (roadmap 5.7-1) and asks to leave plan mode
 * with it.
 *
 * THE USER DECIDES, IN THE 1.C-2 MODAL. Like {@see AskUserTool}, the tool puts
 * its question to the turn's approver ({@see RelaysPermissionAsks}), so it
 * reaches the TUI's permission modal or a server client's
 * `permission.requested` event. The ask carries the plan: its path and first
 * lines in the question text (what the modal shows) and the whole file, up
 * to {@see MAX_PLAN_BYTES}, in its arguments (what a client can render).
 *
 * - `y` approves. The result opens with {@see APPROVED} and names the mode
 *   the session goes back to — the one plan mode was entered from, or
 *   `default`. {@see approval()} turns that result into the
 *   {@see PermissionModeToggledMsg} a host applies once the turn has ended:
 *   a running turn keeps the gate it forked with, so the edits wait for the
 *   next turn.
 * - `r` with a note refuses with feedback; the model reads it and keeps
 *   planning. `n` refuses without one.
 * - Nobody answering is an error result, and plan mode stays on.
 *
 * Plan mode is never left without that approval. The tool refuses outside
 * `plan` (when the turn's mode is known — {@see DelegatesToEngine} is how it
 * learns it, and why the tool is withheld from sub-agents), with no approver,
 * and in a run marked {@see withoutInteractiveUser()} (`-p`,
 * {@see \SugarCraft\Crush\Cli\NonInteractive}).
 *
 * PERMISSION CLASS: no-ask, as for AskUser: the call is itself the question,
 * and it has to run in `plan`, the one mode it exists for.
 *
 * NOT YET REACHABLE BY A PERSON: the engine does not bind its approver to
 * this tool (only {@see withPermissionApprover()} does), so a live call takes
 * the no-approver branch until it does, and `Alt+M` stays the way out.
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::NoAsk, position: 22, gloss: 'leave plan mode: put the written plan to the user for approval')]
final readonly class PlanExitTool implements Tool, BuildsFromCatalog, DelegatesToEngine, RelaysPermissionAsks, TakesToolCallId
{
    public const NAME = 'PlanExit';

    /** How an approval's result opens — what {@see approval()} recognises. */
    public const APPROVED = 'Plan approved:';

    /** The largest plan the ask's arguments carry whole; a longer one is cut there. */
    public const MAX_PLAN_BYTES = 65536;

    /** The plan lines the modal's question text quotes. */
    private const PREVIEW_LINES = 4;

    /**
     * @param ?\Closure(ToolCall, HookResult): mixed $approver the turn's approver; null when nothing can answer
     * @param ?PermissionMode                        $mode     the turn's permission mode, null when unknown
     * @param ?PermissionMode                        $returnTo the mode an approval switches to, when the mode is known
     * @param string                                 $noUser   why nobody can approve in this run; '' when someone might
     */
    private function __construct(
        private string $root,
        private ?\Closure $approver = null,
        private ?PermissionMode $mode = null,
        private ?PermissionMode $returnTo = null,
        private string $noUser = '',
    ) {
    }

    /** `$root` is the project the plans directory sits in; '' reads the working directory when called. */
    public static function new(string $root = ''): self
    {
        return new self($root);
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return self::new($context->root);
    }

    /**
     * Bound per turn: the copy knows the turn's permission mode and the mode
     * leaving plan returns to — {@see PermissionGate::toggledFrom()}, the
     * mode plan was entered from, or `default` when the session started in
     * plan (the rule {@see \SugarCraft\Crush\Chat}'s `Alt+M` toggle follows).
     */
    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): self
    {
        $gate = $engine->permissionGate();
        $from = $gate?->toggledFrom();
        $returnTo = $gate === null ? null : ($from !== null && $from !== PermissionMode::Plan ? $from : PermissionMode::Default);

        return new self($this->root, $this->approver, $gate?->mode(), $returnTo, $this->noUser);
    }

    /**
     * @param \Closure(ToolCall, HookResult): mixed $approver
     */
    public function withPermissionApprover(\Closure $approver): self
    {
        return new self($this->root, $approver, $this->mode, $this->returnTo, $this->noUser);
    }

    /** A copy that never asks, whatever approver it is later bound to; `$why` is what the model reads. */
    public function withoutInteractiveUser(string $why): self
    {
        return new self($this->root, $this->approver, $this->mode, $this->returnTo, trim($why) === '' ? 'nobody is at the keyboard' : trim($why));
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Leave plan mode: ask the user to approve the plan you wrote. Call it only in plan mode, once the '
            . 'plan is written to a Markdown file directly in `' . PermissionGate::PLANS_DIR . '/` and covers the goal, the '
            . 'files it touches, the steps in order, the risks and how the result will be verified. The user sees the '
            . 'plan and approves it or sends feedback. On approval, plan mode ends when this turn ends: finish the '
            . 'turn with a short summary and carry the plan out in the next one. On feedback, revise the plan and '
            . 'call this again. Asking for approval is the only way out of plan mode; never treat the original '
            . 'request as approval.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_path' => [
                    'type' => 'string',
                    'description' => 'The plan file, e.g. `' . PermissionGate::PLANS_DIR . '/add-retry-backoff.md` '
                        . '(relative to the project root, or absolute)',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Optional: the plan in one or two sentences, shown above it',
                ],
            ],
            'required' => ['plan_path'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $refusal = $this->whyNobodyCanApprove();
        if ($refusal !== null) {
            return new ToolResult('', $refusal, true);
        }

        try {
            [$relative, $plan, $cut] = $this->readPlan($args['plan_path'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return new ToolResult('', 'Error: ' . $e->getMessage() . ' Plan mode stays on.', true);
        }

        $summary = is_string($args['summary'] ?? null) ? self::oneLine($args['summary']) : '';
        $callId = is_string($args['id'] ?? null) ? $args['id'] : '';
        $call = new ToolCall($callId, self::NAME, array_filter([
            'description' => 'Approve the plan in ' . $relative . ' and leave plan mode',
            'plan_path' => $relative,
            'summary' => $summary,
            'plan' => $plan,
        ], static fn (string $value): bool => $value !== ''));

        try {
            /** @var \Closure(ToolCall, HookResult): mixed $approver */
            $approver = $this->approver;
            $verdict = ApprovalVerdict::of($approver($call, HookResult::ask($this->prompt($relative, $summary, $plan, $cut))));
        } catch (\Throwable $e) {
            $verdict = ApprovalVerdict::unanswered('the plan could not be put to the user: ' . $e->getMessage());
        }

        return $this->outcome($verdict, $relative);
    }

    /**
     * What the host does when a finished call's result is an approval: the
     * switch out of plan mode to apply once the turn has ended, or null for
     * any other result (a refusal, an error, another tool). The mode named in
     * the approval when the tool knew it; otherwise the toggle out of plan.
     * Apply it only while the session is still in plan mode.
     */
    public static function approval(string $toolName, ToolResult $result): ?PermissionModeToggledMsg
    {
        if ($toolName !== self::NAME || $result->isError() || !str_starts_with($result->content(), self::APPROVED)) {
            return null;
        }

        $firstLine = strtok($result->content(), "\n");
        $mode = is_string($firstLine) && preg_match('/switches to `([a-z-]+)`/', $firstLine, $m) === 1
            ? PermissionMode::tryFrom($m[1])
            : null;

        return new PermissionModeToggledMsg($mode === PermissionMode::Plan ? null : $mode);
    }

    private function outcome(ApprovalVerdict $verdict, string $relative): ToolResult
    {
        if ($verdict->isUnanswered()) {
            $why = $verdict->feedback === '' ? 'the turn ended before it was answered' : $verdict->feedback;

            return new ToolResult('', "Nobody answered ({$why}). Plan mode stays on; present the plan in your reply and end the turn.", true);
        }

        if ($verdict->permits()) {
            $switch = $this->returnTo === null
                ? 'plan mode ends when this turn ends.'
                : "plan mode ends when this turn ends, and the session switches to `{$this->returnTo->value}`.";

            return new ToolResult('', self::APPROVED . ' ' . $switch . "\n\n"
                . "The user approved the plan in {$relative}. Edits are still refused until this turn ends, so end it "
                . 'now with a short summary of what you will do, and carry the plan out in the next turn.');
        }

        $label = substr(ApprovalVerdict::rejectedByUser('x')->feedback, 0, -1);
        if ($verdict->feedback !== '' && !str_starts_with($verdict->feedback, $label)) {
            return new ToolResult('', "The plan could not be put to the user: {$verdict->feedback}. Plan mode stays on.", true);
        }

        $note = trim(substr($verdict->feedback, \strlen($label)));

        return new ToolResult('', $note === ''
            ? "The user did not approve the plan. Plan mode stays on: ask what should change (AskUser), or revise {$relative} and call PlanExit again."
            : "The user did not approve the plan and said: {$note}\n\nPlan mode stays on: revise {$relative} to address that, then call PlanExit again.");
    }

    /** Why the plan cannot be put to anyone, or null when it can. */
    private function whyNobodyCanApprove(): ?string
    {
        if ($this->noUser !== '') {
            return "No one can approve a plan here: {$this->noUser}. Plan mode stays on; present the plan in your reply instead.";
        }
        if ($this->mode !== null && $this->mode !== PermissionMode::Plan) {
            return "Plan mode is not active: the session runs in `{$this->mode->value}`, so there is nothing to leave. Carry on with the work.";
        }
        if ($this->approver === null) {
            return 'No one can approve a plan here: no interactive user is attached to this run. Plan mode stays on; present the plan in your reply instead.';
        }

        return null;
    }

    /**
     * The modal's question text, keys first (the modal shows only the first
     * rows of a long prompt).
     */
    private function prompt(string $relative, string $summary, string $plan, bool $cut): string
    {
        $switch = $this->returnTo === null ? 'leave plan mode' : "switch to `{$this->returnTo->value}`";
        $lines = [
            "y = approve and {$switch} when this turn ends · r = send feedback, keep planning · n = refuse",
            '',
            'Plan: ' . $relative . ($cut ? ' (long; the start is shown)' : ''),
        ];
        if ($summary !== '') {
            $lines[] = $summary;
        }
        $lines[] = '';
        $body = array_slice(array_values(array_filter(
            explode("\n", $plan),
            static fn (string $line): bool => trim($line) !== '',
        )), 0, self::PREVIEW_LINES);

        return implode("\n", [...$lines, ...$body]);
    }

    /**
     * The plan named by `$path`: its project-relative spelling, its text (cut
     * at {@see MAX_PLAN_BYTES}) and whether it was cut.
     *
     * The file must be a `.md` directly in {@see PermissionGate::PLANS_DIR}
     * under the root, judged on the resolved path the way the gate's plans-dir
     * exception judges a write, so a link that leads out is not a plan.
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    private function readPlan(mixed $path): array
    {
        $hint = 'Write the plan to `' . PermissionGate::PLANS_DIR . '/<name>.md` first, then call PlanExit with that path.';
        if (!is_string($path) || trim($path) === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('`plan_path` must name the plan file. ' . $hint);
        }

        $path = trim($path);
        $base = $this->root !== '' ? $this->root : (getcwd() ?: '.');
        // A path resolved earlier in this process is cached, and a cached
        // `.sugar-crush/plans` would hide a directory swapped for a link out.
        clearstatcache(true);
        $rootReal = realpath($base);
        $file = PathJail::resolve($base, $path);
        if ($rootReal === false || $file === null || !is_file($file)) {
            throw new \InvalidArgumentException("there is no plan file at {$path} in this project. {$hint}");
        }

        // The resolved file, named from the root, must be `<PLANS_DIR>/<name>.md`
        // — the spelling PermissionGate's plan-mode write exception allows, so
        // a plan PlanExit accepts is one plan mode could have written.
        $relative = substr($file, \strlen(rtrim($rootReal, '/') . '/'));
        $name = basename($relative);
        if (dirname($relative) !== PermissionGate::PLANS_DIR || $name === '.md' || !str_ends_with(strtolower($name), '.md')) {
            throw new \InvalidArgumentException("{$path} is not a Markdown file directly in " . PermissionGate::PLANS_DIR . ". {$hint}");
        }

        $text = @file_get_contents($file, false, null, 0, self::MAX_PLAN_BYTES + 1);
        if ($text === false) {
            throw new \InvalidArgumentException("the plan file {$path} could not be read.");
        }
        $cut = \strlen($text) > self::MAX_PLAN_BYTES;
        $text = trim(mb_scrub($cut ? mb_strcut($text, 0, self::MAX_PLAN_BYTES, 'UTF-8') : $text, 'UTF-8'));
        if ($text === '') {
            throw new \InvalidArgumentException("the plan file {$path} is empty. {$hint}");
        }

        return [$relative, $text, $cut];
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', mb_scrub($text, 'UTF-8')));
    }
}

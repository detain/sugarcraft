<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Attachments\ContextMentions;
use SugarCraft\Crush\Attachments\FileMentions;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\QueueMode;
use SugarCraft\Crush\Backend\SocketSteerInbox;
use SugarCraft\Crush\CommandParser;
use SugarCraft\Crush\Commands\BangShell;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\ToolCall;

/**
 * Submission, queueing and turn dispatch as logic a host runs without a screen
 * (roadmap O-2g, Appendix O §4.2 "Submit pipeline", "Dispatch", "Prompt
 * queueing mid-turn").
 *
 * WHY IT LEFT {@see Chat}. What a submitted line becomes is the same whether a
 * keyboard or a socket sent it: whether a running turn takes it as a steer or
 * queues it (roadmap 1.C-3) and what it says when it does; which mid-turn
 * drafts are refused; a `!cmd` (roadmap 5.14g) and what its refusal reads;
 * a command file's expansion — the `/name:arg` spelling, the project-tier
 * shell refusal, the gate check, and the fork that takes a shell form off the
 * thread (audit 15b-20); the two turn-lifecycle hooks — their context, their
 * gate-first order, the notes they add and the refusal an `ASK` becomes, and
 * the fork that takes a script hook off the thread (audit 15b-04); the
 * `@file` / `@diff` / `@session:` / `@url` mentions on the user's row (audit
 * 15b-15, roadmap 5.8); the inline 85% / 95% tier's synchronous heuristic;
 * and the dispatch's bookkeeping — the `/rewind` checkpoint taken BEFORE the
 * prompt (audit SES-1), the picker's turn count, and the durable
 * `message.created` the prompt's row is announced with. All of it lives here
 * once. `Chat` keeps the Msg plumbing — which `mutate()` a route commits,
 * which Cmd it returns, when a parked submission re-enters `submit()` — and
 * its methods keep their names as thin delegates where tests and docs cite
 * them. {@see SessionHost} is the headless caller of the same methods, so a
 * TUI and a server cannot admit a submission differently.
 *
 * STATELESS BY DESIGN, like {@see CompactionService}: one service per
 * workspace ({@see WorkspaceContext::service()}) serves every session in it,
 * so a session's history, queue, hooks, gate and store are handed in, and
 * every answer is a value, a sentence or a promise for the caller to commit.
 * The two exceptions are the side effects a dispatch owes the store
 * ({@see saveCheckpoint()}, {@see recordTurn()}, {@see recordMessagesCreated()})
 * — each a write the caller asked for, none of them session state.
 */
final class TurnController
{
    /**
     * The durable event a dispatched prompt's row is announced with (Appendix O
     * §6.5 `message.created`), written ahead of the turn's own `turn.started`
     * so that event's `messageId` names a row a replay has already seen.
     */
    public const MESSAGE_CREATED = SessionEvent::MESSAGE_CREATED;

    /**
     * Longest excerpt of the user's own text a notice quotes back.
     *
     * Bounded because these notices are transcript rows and the transcript is
     * a fixed-width pane: {@see \SugarCraft\Crush\Renderer::fitToPane()} wraps
     * rather than cuts, so an unbounded quote costs ROWS rather than
     * correctness, and a pasted 4KB draft would push the turn it is about to
     * follow off the frame. Short enough to identify the message, which is the
     * whole job — the full text is what eventually goes out.
     */
    public const IN_FLIGHT_QUOTE_MAX_CHARS = 60;

    /**
     * The key {@see checkpointState()} marks a pre-prompt checkpoint with
     * (audit SES-1): a checkpoint WITHOUT it predates the fix and still ends
     * on the prompt its draft re-seeds, which `/rewind` drops instead.
     */
    public const CHECKPOINT_PRE_TURN_KEY = 'messagesPrecedePrompt';

    /**
     * Poll interval for a child {@see forkPayload()} started — the same 50 ms
     * the tool fan-out polls its children at.
     */
    public const FORK_POLL_SECONDS = 0.05;

    /** How long a cancelled child's reap may take, seconds (the tool fan-out's budget). */
    public const REAP_BUDGET_SECONDS = 0.1;

    /** How often that reap looks, seconds. */
    public const REAP_POLL_SECONDS = 0.005;

    /**
     * {@see midTurnRoute()}'s answers: what a line submitted while a turn runs
     * does.
     */
    public const ROUTE_QUIT = 'quit';
    public const ROUTE_WORKFLOW_CONTROL = 'workflow-control';
    public const ROUTE_REFUSE_COMMAND = 'refuse-command';
    public const ROUTE_QUEUE = 'queue';
    public const ROUTE_STEER = 'steer';
    public const ROUTE_INTERRUPT = 'interrupt';
    public const ROUTE_SIDE_QUESTION = 'side-question';

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    // ── the sentences ──────────────────────────────────────────────────

    /**
     * One bounded, control-byte-free excerpt of untrusted draft text, for a
     * notice that is about to be painted.
     *
     * {@see CompactionService::sanitizeSummaryLine()} does the flattening and
     * the ESC-stripping — the same treatment model-authored text gets, and for
     * the same reason: this is keystroke data, so a bracketed-paste dump can
     * carry ESC/C0/DEL, and it is bound for a frame.
     */
    public static function quoteDraft(string $text): string
    {
        $clean = CompactionService::sanitizeSummaryLine($text);
        if (mb_strlen($clean, 'UTF-8') > self::IN_FLIGHT_QUOTE_MAX_CHARS) {
            $clean = mb_substr($clean, 0, self::IN_FLIGHT_QUOTE_MAX_CHARS - 1, 'UTF-8') . '…';
        }

        return $clean;
    }

    /** The notice a queued follow-up gets: $waiting is the queue's length with it. */
    public function queuedNotice(int $waiting, string $text): string
    {
        return Lang::t('host.turn.queued', ['waiting' => $waiting, 'draft' => self::quoteDraft($text)]);
    }

    /** The notice a prompt steered into the running turn gets (roadmap 1.C-3). */
    public function steeringNotice(string $text): string
    {
        return Lang::t('host.turn.steering', ['draft' => self::quoteDraft($text)]);
    }

    /**
     * Why a command file whose template expanded to nothing was not sent.
     *
     * Refused visibly rather than swallowed: pressing Enter and watching
     * nothing happen reads as a wedged app, and the author of the file is the
     * only one who can fix it.
     */
    public function emptyCustomCommandNotice(string $text): string
    {
        return Lang::t('host.turn.empty_custom_command', ['draft' => self::quoteDraft($text)]);
    }

    /**
     * Why a slash command TYPED while a turn runs was not run — the draft is
     * kept, so the notice says Enter will run it.
     */
    public function inFlightCommandNotice(string $text): string
    {
        return Lang::t('host.turn.in_flight_command', ['draft' => self::quoteDraft($text)]);
    }

    /**
     * Why a command run on the user's behalf (a menu row, Ctrl+N) while a turn
     * runs was not run — the draft was never touched, so the notice says so.
     */
    public function runCommandInFlightNotice(string $text): string
    {
        return Lang::t('host.turn.run_command_in_flight', ['draft' => self::quoteDraft($text)]);
    }

    /** Why an overlay action chosen while a turn runs was not run; $what is its label. */
    public function inFlightActionNotice(string $what): string
    {
        return Lang::t('host.turn.in_flight_action', ['action' => self::quoteDraft($what)]);
    }

    /** Why a read-only session did not send $text; $session is its name or id. */
    public function readOnlyNotice(string $text, string $session): string
    {
        return Lang::t('host.turn.read_only', ['draft' => self::quoteDraft($text), 'session' => $session]);
    }

    /** Why `!$command` did not run: $reason from {@see BangShell::refusal()}. */
    public function bangRefusedNotice(string $command, string $reason): string
    {
        return Lang::t('host.turn.bang_refused', ['command' => self::quoteDraft($command), 'reason' => $reason]);
    }

    /** The row a `!$command` that is about to run adds. */
    public function bangRunningNotice(string $command): string
    {
        return Lang::t('host.turn.bang_running', ['command' => self::quoteDraft($command)]);
    }

    /**
     * The row a prompt a turn hook blocked gets: $blocked is
     * {@see turnHookRefusalReason()}'s sentence. $boxOccupied says the user
     * typed a NEW draft while a forked chain ran, in which case the box keeps
     * theirs (losing typed text is the worse error) and the notice quotes the
     * prompt instead of claiming it is still in the box.
     */
    public function turnHookBlockedNotice(string $blocked, string $text, bool $boxOccupied): string
    {
        return $blocked . ($boxOccupied
            ? Lang::t('host.turn.hook_blocked.box_occupied', ['draft' => self::quoteDraft($text)])
            : Lang::t('host.turn.hook_blocked.in_box'));
    }

    /** The row a forked command-file expansion that reported nothing gets. */
    public function expansionFailedNotice(string $text, bool $boxOccupied): string
    {
        return Lang::t('host.turn.expansion_failed', [
            'draft' => self::quoteDraft($text),
            'box' => Lang::t($boxOccupied ? 'host.turn.expansion_failed.box_occupied' : 'host.turn.expansion_failed.in_box'),
        ]);
    }

    /**
     * Why a headless host did not run the command $text: a turn holds the
     * session, and a command would rewrite the history that turn is about to
     * append to. A command file is a PROMPT and is not refused by this.
     */
    public function hostCommandNotice(string $text): string
    {
        return Lang::t('host.turn.host_command_in_flight', ['draft' => self::quoteDraft($text)]);
    }

    /** Why a host with no backend sent nothing. */
    public function noBackendNotice(): string
    {
        return Lang::t('host.turn.no_backend');
    }

    /** Why an empty submission sent nothing. */
    public function emptyPromptNotice(): string
    {
        return Lang::t('host.turn.empty_prompt');
    }

    // ── admission ──────────────────────────────────────────────────────

    /**
     * Whether $text is the leading-slash-less `mcp auth …` command spelling.
     *
     * `mcp` and `auth` must each be WHOLE words: a raw prefix test used to
     * claim prose like "mcp authentication keeps failing on my server, why?"
     * (audit 15b-11) — idle it ran the handler instead of asking the model,
     * and mid-turn it refused the draft instead of queueing it.
     */
    public static function isBareMcpAuthCommand(string $text): bool
    {
        return preg_match('/^mcp\s+auth(?:\s|$)/', $text) === 1;
    }

    /**
     * What a line submitted while a turn is running does — one of the
     * `ROUTE_*` constants.
     *
     * - Bare `/exit` and `/quit` quit: they end the process, so there is no
     *   state left for them to corrupt.
     * - `/workflow pause|status` during a workflow run controls it
     *   ($workflowControl, the caller's judgement, audit WF-4).
     * - `/btw <question>` (roadmap 5.14b) runs: a side question writes only
     *   UI-only rows and leaves the turn running, so there is nothing for it
     *   to corrupt — and asking about the work while it happens is the point.
     * - Every other `/`-prefixed draft, and the bare `mcp auth …` spelling, is
     *   refused rather than queued: a queued command would run minutes later
     *   against a transcript the user is no longer looking at, and the commands
     *   most likely typed in the dead time are the destructive ones. The `/`
     *   test is the whole classifier, not a per-command list — every built-in
     *   handler touches state the running turn is about to write.
     * - A `!cmd` (roadmap 5.14g) is the user's own shell command, not a
     *   message for the running turn: steering it in would hand the agent the
     *   text `!git status` to interpret. It waits its turn.
     * - Anything else is delivered as $options asks: steered into the running
     *   turn (roadmap 1.C-3, decision D6 — Enter's default), queued for after
     *   it, or interrupting it.
     */
    public function midTurnRoute(string $text, bool $workflowControl, SubmitOptions $options): string
    {
        if ($text === '/exit' || $text === '/quit') {
            return self::ROUTE_QUIT;
        }

        if ($workflowControl) {
            return self::ROUTE_WORKFLOW_CONTROL;
        }

        if (Commands\CommandText::tokens($text)[0] === '/btw') {
            return self::ROUTE_SIDE_QUESTION;
        }

        if (str_starts_with($text, '/') || self::isBareMcpAuthCommand($text)) {
            return self::ROUTE_REFUSE_COMMAND;
        }

        if (BangShell::commandOf($text) !== null) {
            return self::ROUTE_QUEUE;
        }

        return match ($options->effectiveDelivery()) {
            QueueMode::Steer => self::ROUTE_STEER,
            QueueMode::Interrupt => self::ROUTE_INTERRUPT,
            QueueMode::Followup => self::ROUTE_QUEUE,
        };
    }

    /**
     * $queue without the entries the turn that just settled delivered as
     * steers (roadmap 1.C-3).
     *
     * A delivered steer is a hidden user row of exactly
     * {@see SocketSteerInbox::content()}'s bytes, folded into the history from
     * the turn's transcript. Only the rows AFTER the turn's own prompt — the
     * last user row the transcript shows — are counted, so a steer an earlier
     * turn delivered cannot swallow the same words queued now; each delivered
     * row cancels one queued entry.
     *
     * @param list<string>  $queue
     * @param list<Message> $history
     * @return list<string>
     */
    public static function withoutDeliveredSteers(array $queue, array $history): array
    {
        $delivered = [];
        for ($i = \count($history) - 1; $i >= 0; $i--) {
            $row = $history[$i];
            if ($row->role !== Role::User) {
                continue;
            }
            if ($row->userVisible && !$row->uiOnly) {
                break;
            }
            if (!$row->userVisible) {
                $delivered[$row->content] = ($delivered[$row->content] ?? 0) + 1;
            }
        }

        if ($delivered === []) {
            return $queue;
        }

        $kept = [];
        foreach ($queue as $text) {
            $row = SocketSteerInbox::content($text);
            if (($delivered[$row] ?? 0) > 0) {
                $delivered[$row]--;

                continue;
            }
            $kept[] = $text;
        }

        return $kept;
    }

    // ── command files ──────────────────────────────────────────────────

    /**
     * The file-based command a typed `/name …` names, with its argument string,
     * or null when it names none.
     *
     * THE NAME IS "EVERYTHING UP TO THE FIRST WHITESPACE", not
     * {@see CommandParser::parse()}'s normalised name: that strips every
     * character outside `[A-Za-z0-9_-]` and lower-cases the rest, so it reports
     * `/deploy/staging` as `deploystaging` — and `deploy/staging.md` is exactly
     * the namespaced file the loader most deliberately supports. It is also
     * what the "/" popup completes, so the string picked is the string looked
     * up.
     *
     * THE COLON INVOCATION FORM, `/name:arg`, is tried only after the whole
     * name misses (`:` is not legal in a command file's name), and its tail is
     * PREPENDED to the arguments, matching `parse()`'s reading of
     * `/compact:x y` — without it every built-in stayed reachable,
     * un-overridden, through its colon spelling.
     *
     * @param array<string, CommandSpec> $customCommands
     * @return ?array{0: CommandSpec, 1: string}
     */
    public function resolveCustomCommand(string $text, array $customCommands): ?array
    {
        if ($customCommands === []) {
            return null;
        }

        // `/s` so a bare "/name" with a trailing newline still parses, and `\S+`
        // so the name stops at the first whitespace of any kind.
        if (preg_match('/^\/(\S+)(?:\s+(.*))?$/s', $text, $matches) !== 1) {
            return null;
        }

        $name = $matches[1];
        $arguments = trim($matches[2] ?? '');

        $spec = $customCommands[$name] ?? null;

        if ($spec === null) {
            $colon = strpos($name, ':');
            if ($colon !== false) {
                $candidate = $customCommands[substr($name, 0, $colon)] ?? null;
                if ($candidate !== null) {
                    $spec = $candidate;
                    $arguments = trim(substr($name, $colon + 1) . ' ' . $arguments);
                }
            }
        }

        if ($spec === null) {
            return null;
        }

        return [$spec, $arguments];
    }

    /**
     * $spec's template expanded for $arguments, with $directive answering its
     * `` !`cmd` `` and `@path` forms ({@see commandDirective()}).
     *
     * THE POSITIONAL TOKENS ARE THE PARSER'S, fed the argument string
     * {@see resolveCustomCommand()} isolated rather than the raw draft — so `$1`
     * and `$ARGUMENTS` are two views of ONE string instead of two parses that
     * could disagree about where the arguments began.
     */
    public function expandCustomCommand(CommandSpec $spec, string $arguments, \Closure $directive): ?string
    {
        return $spec->expandTemplate(
            $arguments,
            (new CommandParser())->parse('/c ' . $arguments)?->args ?? [],
            $directive,
        );
    }

    /**
     * The resolver {@see CommandSpec::expandTemplate()} calls for the two
     * template forms that leave the string: `` !`cmd` `` and `@path`.
     *
     * THIS IS THE POLICY AND {@see CommandSpec} IS THE MECHANISM: the spec is a
     * value read off disk, and a spec that could gate itself would be a
     * repository-supplied file deciding its own permissions.
     *
     * THE ROOT IS RESOLVED ONCE by the caller, so every substitution in one
     * expansion is judged against the same directory — a command whose own
     * `` !`cd /tmp && …` `` moved the process must not move the boundary its
     * later `@path` forms are checked against.
     *
     * @param (\Closure(string): void)|null $onGateEvaluated see {@see refuseCommandShell()}
     */
    public function commandDirective(
        CommandSpec $spec,
        string $root,
        bool $projectCommandsTrusted,
        ?PermissionGate $gate,
        ?\Closure $onGateEvaluated = null,
    ): \Closure {
        $controller = $this;

        return static function (string $kind, string $payload, float $secondsRemaining) use ($controller, $spec, $root, $projectCommandsTrusted, $gate, $onGateEvaluated): string {
            if ($kind === 'include') {
                return $spec->includeFile($payload, $root);
            }

            $refusal = $controller->refuseCommandShell($spec, $payload, $projectCommandsTrusted, $gate, $onGateEvaluated);
            if ($refusal !== null) {
                return $refusal;
            }

            return $spec->runShellSubstitution($payload, $root, $secondsRemaining);
        };
    }

    /**
     * Why this `` !`cmd` `` may not run, or null if it may.
     *
     * TWO CHECKS, IN THIS ORDER, and the order is the substantive decision:
     *
     * 1. THE TIER. A `project`-tier file came out of a `git clone`, and running
     *    a shell out of it needs $projectCommandsTrusted — the operator having
     *    named this root under `trustedProjectCommands`. `user` is the
     *    operator's own file; `null` was built in PHP by an in-process caller.
     * 2. THE GATE, and only then, because {@see PermissionGate::evaluate()}
     *    MUTATES Auto mode's circuit-breaker counters, and a command refused by
     *    the tier rule never really ran.
     *
     * ONLY `Deny` REFUSES; an `Ask` proceeds: expansion has no prompt to show,
     * and {@see PermissionGate::refuses()} forbids turning "would have asked"
     * into "no". No gate is not a refusal either — a session with no permission
     * configuration must not be stricter than the shipped default mode.
     *
     * RECORDED when asked to be (audit 15b-20): an expansion forked off the
     * thread evaluates against the CHILD's copy of the gate, whose counters die
     * with it, so the child reports every command it put to the gate through
     * $onGateEvaluated and the parent replays them against its own.
     */
    public function refuseCommandShell(
        CommandSpec $spec,
        string $command,
        bool $projectCommandsTrusted,
        ?PermissionGate $gate,
        ?\Closure $onGateEvaluated = null,
    ): ?string {
        if ($spec->tier === 'project' && !$projectCommandsTrusted) {
            return sprintf(
                '[!`%s` was not run: /%s came from this project\'s .sugar-crush/commands, which arrives '
                . 'with the repository, and a command file from a checkout may only run a shell if you have '
                . 'listed this project under "trustedProjectCommands" in ~/.sugar-crush/config.json — the '
                . 'rest of the command file was sent.]',
                CommandSpec::abbreviateForm($command),
                $spec->name,
            );
        }

        if ($gate === null) {
            return null;
        }

        if ($onGateEvaluated !== null) {
            $onGateEvaluated($command);
        }

        // `\SugarCraft\Crush\ToolCall`, the TUI-side pair PermissionGate takes.
        // No project root: a Bash verdict never reads one (audit F-J3-rem(b),
        // pinned by ChatBashGateRootTest).
        if ($gate->evaluate(new ToolCall('Bash', ['command' => $command])) === PermissionDecision::Deny) {
            return sprintf(
                '[!`%s` was not run: this session\'s permission mode (%s) denies it]',
                CommandSpec::abbreviateForm($command),
                $gate->mode()->value,
            );
        }

        return null;
    }

    /**
     * Whether expanding the command file $text names would run a shell, and so
     * has to leave the host's thread (audit 15b-20).
     *
     * True only when every one of these holds, so every other expansion stays
     * synchronous and byte-identical: $text names a file whose body has a
     * `` !`…` `` form; that form could actually reach a shell (an untrusted
     * project-tier body has every one refused by {@see refuseCommandShell()}'s
     * first check, so forking it would buy nothing); no expansion for this
     * exact line is already in hand ($resolvedText, the re-entry); and this
     * build can fork.
     *
     * @param array<string, CommandSpec> $customCommands
     */
    public function customCommandMustFork(
        string $text,
        array $customCommands,
        ?string $resolvedText,
        bool $projectCommandsTrusted,
    ): bool {
        if ($resolvedText === $text || !self::canFork()) {
            return false;
        }

        $command = $this->resolveCustomCommand($text, $customCommands);
        if ($command === null) {
            return false;
        }
        [$spec] = $command;

        return $spec->hasShellSubstitution()
            && !($spec->tier === 'project' && !$projectCommandsTrusted);
    }

    /**
     * What a forked expansion child writes. THE EXPANSION CROSSES THE BOUNDARY
     * BASE64-ENCODED: it carries raw command output, which need not be UTF-8,
     * and JSON's substitution would hand the model different bytes from the
     * ones the synchronous path sent.
     *
     * @param list<string> $gated
     */
    public static function customCommandPayload(?string $expanded, array $gated): string
    {
        $json = json_encode([
            'expanded' => $expanded === null ? null : base64_encode($expanded),
            'gated' => $gated,
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '' : $json;
    }

    /**
     * Read back what an expansion child wrote ($data, already taken from its
     * payload file). Anything unreadable is a null expansion, which a caller
     * refuses rather than sends.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    public static function customCommandExpansionFromPayload(string|false $data): array
    {
        $decoded = ($data !== false && $data !== '') ? json_decode($data, true) : null;
        if (!\is_array($decoded) || !\is_string($decoded['expanded'] ?? null)) {
            return [null, []];
        }

        $expanded = base64_decode($decoded['expanded'], true);
        $gated = array_values(array_filter(
            \is_array($decoded['gated'] ?? null) ? $decoded['gated'] : [],
            'is_string',
        ));

        return [$expanded === false ? null : $expanded, $gated];
    }

    // ── turn-lifecycle hooks ───────────────────────────────────────────

    /**
     * The tool-shaped context a turn-lifecycle hook is dispatched with (P7.S2).
     *
     * Smuggles a session/prompt event through {@see HookContext} instead of
     * extending it — the full WHY is on {@see HookManager::sessionStart()}.
     */
    public function turnHookContext(string $event, string $prompt, bool $atStartup, ?string $sessionId, string $root): HookContext
    {
        return new HookContext(
            // Null on a fresh session until autosave assigns one, so a
            // SessionStart hook legitimately sees '' — it is the first thing in
            // the session.
            sessionId: $sessionId ?? '',
            // Not a mislabel: this slot is the only thing a hook's matcher is
            // tested against (HookRegistry::findMatches()), and for a
            // turn-lifecycle event the thing being matched IS the event name.
            toolName: $event,
            toolArgs: [],
            // JSON_INVALID_UTF8_SUBSTITUTE: the draft is untrusted bytes, and
            // ONE invalid sequence would make json_encode() fail and hand the
            // hook a context with no prompt in it. Slashes and non-ASCII
            // unescaped (the F-H3 encoding HOOKS.md documents), so a hook
            // grepping the prompt matches what was typed.
            toolInput: json_encode(
                $atStartup
                    ? ['prompt' => $prompt, 'source' => 'startup']
                    : ['prompt' => $prompt],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            ) ?: '{}',
            toolOutput: '',
            // No model/provider identity reaches the submit path: Backend's
            // whole contract is complete(history). Empty rather than guessed.
            model: '',
            provider: '',
            projectRoot: $root,
        );
    }

    /**
     * Why a turn-lifecycle verdict does not permit the turn, or null when it
     * does.
     *
     * An unanswered ASK FAILS CLOSED: the submit path has no UI that can put
     * the question and no queue that could hold the turn until it was
     * answered, so honouring an ASK as permission would run a prompt a hook
     * explicitly asked to pause. A silent deny still owes the user a sentence.
     */
    public function turnHookRefusalReason(HookResult $result): ?string
    {
        if ($result->permitsExecution()) {
            return null;
        }

        if ($result->isAsk()) {
            return DenialKind::Unanswered->reason('a turn hook asked for a decision this path cannot present');
        }

        return DenialKind::Hook->reason($result->message !== ''
            ? $result->message
            : 'the hook blocked this prompt without giving a reason');
    }

    /**
     * Whether this submission's turn-hook chain has to leave the host's thread
     * (audit 15b-04): a hook on one of $events runs out of process AND this
     * build can fork. Without pcntl the chain runs synchronously as it always
     * did.
     *
     * @param list<HookEvent> $events
     */
    public function turnHooksMustFork(?HookManager $hooks, array $events): bool
    {
        if ($hooks === null || !self::canFork()) {
            return false;
        }

        foreach ($events as $event) {
            // The matcher subject for a turn-lifecycle event is the event name
            // — see turnHookContext()'s `toolName` slot.
            if ($hooks->runsOutOfProcess($event, $event->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run one submission's chain, GATE-FIRST: `UserPromptSubmit`, then — only
     * when it permitted the prompt and $sessionContext says the event fires —
     * `SessionStart`, so a SessionStart script never spawns just to learn its
     * prompt was blocked. The one body behind the in-process path and the
     * forked child alike.
     *
     * @return array{0: HookResult, 1: ?HookResult}
     */
    public static function runTurnHooks(HookManager $hooks, HookContext $promptContext, ?HookContext $sessionContext): array
    {
        $prompt = $hooks->userPromptSubmit($promptContext);
        $session = ($sessionContext !== null && $prompt->permitsExecution())
            ? $hooks->sessionStart($sessionContext)
            : null;

        return [$prompt, $session];
    }

    /**
     * The `role: system` notes a permitted submission carries, in the order
     * Anthropic's insertion-point table implies: SessionStart's note ahead of
     * the UserPromptSubmit note, both ahead of the user's line. A blocked
     * SessionStart stops nothing — its note is discarded and only the reason
     * is said. An empty `additionalContext` adds no row, so a wired chain that
     * produced no stdout leaves the request byte-identical.
     *
     * @return list<Message>
     */
    public function turnHookNotes(HookResult $prompt, ?HookResult $session): array
    {
        $notes = [];

        if ($session !== null) {
            $sessionBlocked = $this->turnHookRefusalReason($session);

            if ($sessionBlocked !== null) {
                $notes[] = Message::notice($sessionBlocked . Lang::t('host.turn.session_hook_note_discarded'));
            } elseif ($session->additionalContext !== '') {
                $notes[] = Message::system($session->additionalContext);
            }
        }

        if ($prompt->additionalContext !== '') {
            $notes[] = Message::system($prompt->additionalContext);
        }

        return $notes;
    }

    /**
     * @return array{action: string, message: string, modifiedInput: ?string, additionalContext: string}
     */
    public static function turnHookResultToArray(HookResult $result): array
    {
        return [
            'action' => $result->action,
            'message' => $result->message,
            'modifiedInput' => $result->modifiedInput,
            'additionalContext' => $result->additionalContext,
        ];
    }

    public static function turnHookResultFromArray(mixed $row): ?HookResult
    {
        if (!\is_array($row) || !\is_string($row['action'] ?? null)) {
            return null;
        }

        return new HookResult(
            $row['action'],
            \is_string($row['message'] ?? null) ? $row['message'] : '',
            \is_string($row['modifiedInput'] ?? null) ? $row['modifiedInput'] : null,
            \is_string($row['additionalContext'] ?? null) ? $row['additionalContext'] : '',
        );
    }

    /** What a forked turn-hook child writes: both verdicts. */
    public static function turnHookPayload(HookResult $prompt, ?HookResult $session): string
    {
        $json = json_encode([
            'prompt' => self::turnHookResultToArray($prompt),
            'session' => $session === null ? null : self::turnHookResultToArray($session),
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '' : $json;
    }

    /**
     * Read back what a turn-hook child wrote ($data, already taken from its
     * payload file). A CHILD THAT REPORTS NOTHING FAILS CLOSED: a crashed or
     * truncated payload is a DENY, because "the prompt gate could not answer"
     * letting the prompt through is the widening
     * {@see HookResult::permitsExecution()}'s allow-list exists to prevent.
     *
     * @return array{0: HookResult, 1: ?HookResult}
     */
    public static function turnHookResultsFromPayload(string|false $data): array
    {
        $decoded = ($data !== false && $data !== '') ? json_decode($data, true) : null;
        $prompt = \is_array($decoded) ? self::turnHookResultFromArray($decoded['prompt'] ?? null) : null;
        if ($prompt === null) {
            return [HookResult::deny('the prompt hooks ended without reporting a verdict'), null];
        }

        return [$prompt, self::turnHookResultFromArray($decoded['session'] ?? null)];
    }

    // ── leaving the thread ─────────────────────────────────────────────

    /**
     * What a child {@see forkPayload()} started wrote to $file — the name
     * {@see ToolIpcFiles::reserve()} chose in this process before the fork —
     * read once and discarded either way, including on a failed read, so a
     * half-written payload is never left behind.
     */
    public static function takePayload(string $file): string|false
    {
        $data = is_file($file) ? file_get_contents($file) : false;
        ToolIpcFiles::discard($file);

        return $data;
    }

    /** Whether this build can fork a child and poll it without blocking. */
    public static function canFork(): bool
    {
        return \function_exists('pcntl_fork') && \function_exists('pcntl_waitpid');
    }

    /**
     * Run $childWork in a forked child and resolve with what its payload
     * decodes to — the one fork + WNOHANG-poll shape behind every piece of a
     * submission that leaves the thread: a turn's script hooks (audit 15b-04)
     * and a command file's shell forms (audit 15b-20).
     *
     * - The child closes the server descriptors it inherited first
     *   ({@see ForkedChild::closeInheritedServerFds()}, a no-op in a TUI),
     *   runs $childWork, writes the string it returns through
     *   {@see ToolIpcFiles} (0600, atomic rename) and exits through
     *   {@see ForkedChild::exitNow()}, never back into the loop.
     * - The parent polls from a loop timer every {@see FORK_POLL_SECONDS}, so
     *   the frame keeps painting and the keyboard stays live, and hands the
     *   payload file to $collect once the child has been reaped.
     * - CANCEL KILLS THE TREE: once $cancellation fires, the next poll takes the
     *   child and everything under it through
     *   {@see ProcessContainment::killTreeAsync()}, reaps it on a bounded
     *   window (both on the loop, audit R3), discards the payload and resolves
     *   null, so nothing is dispatched.
     * - A failed fork (-1) runs $inline here, blocking but never wrong.
     *
     * @template T
     *
     * @param \Closure(): string $childWork runs in the child; returns the payload
     * @param \Closure(string): T $collect decodes the payload file (and discards it)
     * @param \Closure(): T $inline the synchronous fallback
     *
     * @return PromiseInterface<T|null>
     */
    public static function forkPayload(
        \Closure $childWork,
        \Closure $collect,
        \Closure $inline,
        CancellationToken $cancellation,
        float $reapBudgetSeconds = self::REAP_BUDGET_SECONDS,
        float $reapPollSeconds = self::REAP_POLL_SECONDS,
    ): PromiseInterface {
        $deferred = new Deferred();

        if ($cancellation->isCancelled()) {
            $deferred->resolve(null);

            return $deferred->promise();
        }

        $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
        $pid = pcntl_fork();

        if ($pid === -1) {
            ToolIpcFiles::discard($file);
            $deferred->resolve($inline());

            return $deferred->promise();
        }

        if ($pid === 0) {
            ForkedChild::closeInheritedServerFds();
            ToolIpcFiles::write($file, $childWork());
            ForkedChild::exitNow(0);
        }

        $loop = Loop::get();
        $settled = false;
        $timer = null;
        $timer = $loop->addPeriodicTimer(
            self::FORK_POLL_SECONDS,
            static function () use ($pid, $file, $collect, $cancellation, $loop, &$settled, &$timer, $deferred, $reapBudgetSeconds, $reapPollSeconds): void {
                if ($settled) {
                    return;
                }

                if ($cancellation->isCancelled()) {
                    $settled = true;
                    $loop->cancelTimer($timer);
                    // Audit R3: the tree kill and the bounded reap run on the
                    // loop (each pass its own tick), so Escape does not freeze
                    // the frame for the walk. The promise still resolves only
                    // after both.
                    ProcessContainment::killTreeAsync($pid, $loop)
                        ->then(static fn (): PromiseInterface => ProcessContainment::reapAsync(
                            [$pid],
                            $reapBudgetSeconds,
                            $reapPollSeconds,
                            $loop,
                        ))
                        ->then(static function () use ($file, $deferred): void {
                            ToolIpcFiles::discard($file);
                            $deferred->resolve(null);
                        });

                    return;
                }

                $status = 0;
                if (pcntl_waitpid($pid, $status, WNOHANG) !== $pid) {
                    return;
                }

                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->resolve($collect($file));
            },
        );

        return $deferred->promise();
    }

    // ── the user's row ─────────────────────────────────────────────────

    /**
     * The user row a submitted prompt becomes, with its mentions attached, plus
     * one notice per mention that could not be (audit 15b-15).
     *
     * The ONE constructor of a dispatched user turn, used by every route that
     * commits one, so a prompt that happens to cross the 85% tier does not lose
     * its attachments. The files are read HERE, once, and the snapshot rides on
     * the message; {@see FileMentions} bounds that read. The notices are
     * UI-only: they report on the user's own input, and the model already sees
     * the mention text itself.
     *
     * The keyword mentions (`@diff`, `@session:<id>`, `@https://…`, roadmap
     * 5.8) are resolved beside the files by {@see ContextMentions}: $store
     * answers `@session:`, and a URL a permission rule denies `WebFetch` is
     * refused rather than fetched.
     *
     * The `$name` skill mentions (roadmap 5.14l) are resolved first, against
     * $skills: each user-invocable skill named attaches its SKILL.md body for
     * this turn only ({@see \SugarCraft\Crush\Skills\SkillMentions}). No
     * registry, no skill mentions — a `$` is then just text.
     *
     * $resolveMentions is false for text the user did not type — a command
     * file's expansion (repository-authored, with its own `@` include form
     * that a trust tier may just have REFUSED) and a background session's
     * announcement (the model's answer, quoted). Neither kind of mention is
     * resolved in it.
     *
     * @return array{0: Message, 1: list<Message>}
     */
    public function userTurnMessage(
        string $text,
        bool $resolveMentions,
        string $root,
        SessionStore|EnhancedSessionStore|null $store,
        ?PermissionGate $gate,
        ?\SugarCraft\Crush\Skills\SkillRegistry $skills = null,
    ): array {
        $message = Message::user($text);
        if (!$resolveMentions) {
            return [$message, []];
        }

        $skilled = $skills !== null && str_contains($text, '$')
            ? \SugarCraft\Crush\Skills\SkillMentions::new($skills)->resolve($text)
            : ['attachments' => [], 'notices' => []];
        $resolved = ['attachments' => [], 'notices' => []];
        $context = ['attachments' => [], 'notices' => []];
        if (str_contains($text, '@')) {
            $resolved = FileMentions::resolve($text, $root);
            $context = ContextMentions::new($root)
                ->withSessionStore(static fn () => $store)
                ->withUrlPolicy(static fn (string $url): ?string => $gate?->ruleDecision(
                    new ToolCall('WebFetch', ['url' => $url]),
                ) === PermissionDecision::Deny
                    ? 'a permission rule denies WebFetch for it.'
                    : null)
                ->resolve($text);
        }
        foreach ([...$skilled['attachments'], ...$resolved['attachments'], ...$context['attachments']] as $attachment) {
            $message = $attachment->type === AttachmentType::Image
                ? $message->attachImage($attachment->path, $attachment->data, $attachment->mimeType)
                : $message->attachFile($attachment->path, $attachment->data);
        }

        return [$message, array_map(
            static fn (string $notice): Message => Message::notice($notice),
            [...$skilled['notices'], ...$resolved['notices'], ...$context['notices']],
        )];
    }

    // ── the inline 85% / 95% tier ──────────────────────────────────────

    /**
     * The automatic tier's synchronous heuristic route for one submission,
     * once the caller has found the tier fired and neither the thrash breaker
     * nor a model-written summary ({@see CompactionService}'s parked route)
     * took it.
     *
     * - The rewrite is ADOPTED only when it bought something: a history of at
     *   most the preserved exchanges is returned untouched however large, and
     *   announcing "saved 0%" every turn would be noise. The decision is made
     *   before the blocking tier is consulted, so both outcomes report it.
     * - The state block (roadmap 2.5) is built from the WHOLE history, as the
     *   `/compact` and parked routes build it — the compactor's own fallback
     *   sees only the tool-stripped exchanges and wrote "Files read/modified:
     *   none".
     * - The blocking tier is tested against the COMPACTED wire even when the
     *   rewrite was not adopted: "blocked until space is freed" means
     *   something only once the automatic way of freeing it was tried.
     * - Before refusing, the INTRA-exchange rescue (backlog §12.2 E18)
     *   truncates one exchange that is itself larger than the window, against
     *   the Message list index-aligned with the compacted wire.
     *
     * `outcome` is `sent` (the rewrite, if any, goes out with the turn — under
     * the tier that breaks the thrash run), `rescued` (the truncation exemption
     * — the run is neither extended nor broken, ruling P8.S5-R6) or `blocked`
     * (refuse: nothing could free enough). `refilled` is the thrash breaker's
     * measurement, taken off the compacted wire whether or not it was adopted.
     *
     * @param list<Message> $history
     * @param list<array<string, mixed>> $wireHistory {@see CompactionService::compactionWire()} of $history
     * $summaries are model-written records to condense with (roadmap 2.10: an
     * ahead-of-need summary the caller has checked still describes $history,
     * passed through {@see CompactionService::splicedSummaries()}); empty is
     * the heuristic route. Any exchange they do not cover gets the heuristic
     * line, and with no state block in them the heuristic block is used.
     *
     * @param \Closure(list<Message>): int $estimate the session's calibrated estimate
     * @param array<string, string> $summaries
     * @return array{outcome: string, history: list<Message>, tokenCount: int, compactionNotice: ?Message, truncationNotice: ?Message, refilled: bool}
     */
    public function inlineTier(
        CompactionService $compaction,
        ContextCompactor $compactor,
        array $history,
        array $wireHistory,
        int $tokenLimit,
        int $tokenCount,
        \Closure $estimate,
        array $summaries = [],
    ): array {
        $baseHistory = $history;
        $compactionNotice = null;
        $truncationNotice = null;

        // One instance for both calls: savingsPercentage() reads the state
        // compact() just left on it.
        $attemptCompactor = $compaction->attemptCompactor($compactor, $history)->withExchangeSummaries($summaries + [
            StateSummaryTemplate::SUMMARY_KEY => CompactionService::heuristicState($history)->render(),
        ]);
        $compactedWire = $attemptCompactor->compact($wireHistory);
        $savedPercentage = $attemptCompactor->savingsPercentage();

        if ($savedPercentage > 0) {
            $compactedHistory = CompactionService::messagesFromWire($compactedWire, $history);
            $baseHistory = $compactedHistory;
            $tokenCount = $estimate($compactedHistory);
            // Agent-visible counts: the rewrite hides rows, it does not remove
            // them (roadmap 1.B-3).
            $compactionNotice = CompactionService::contextCompactedMessage(
                \count(Message::agentVisible($history)),
                \count(Message::agentVisible($compactedHistory)),
                $savedPercentage,
                $tokenCount,
                $tokenLimit,
            );
        }

        $refilled = $compactor->shouldCompact($compactedWire, $tokenLimit);

        if (!$compactor->shouldCompactForeground($compactedWire, $tokenLimit)) {
            return [
                'outcome' => 'sent',
                'history' => $baseHistory,
                'tokenCount' => $tokenCount,
                'compactionNotice' => $compactionNotice,
                'truncationNotice' => null,
                'refilled' => $refilled,
            ];
        }

        // When compaction freed NOTHING, $baseHistory is still $history, which
        // produced $wireHistory and NOT $compactedWire — compact()'s no-op path
        // can drop wire entries and shift every index after them. Re-derive
        // the aligned list the way the adoption above derives its own (review
        // cycle 4, finding 2b).
        $rescueBase = $savedPercentage > 0
            ? $baseHistory
            : CompactionService::messagesFromWire($compactedWire, $history);

        $rescued = $compaction->intraExchangeTruncation($compactor, $compactedWire, $rescueBase, $tokenLimit, $estimate);
        if ($rescued === null) {
            return [
                'outcome' => 'blocked',
                'history' => $baseHistory,
                'tokenCount' => $tokenCount,
                'compactionNotice' => $compactionNotice,
                'truncationNotice' => null,
                'refilled' => $refilled,
            ];
        }

        $truncationNotice = $rescued['notice'];

        return [
            'outcome' => 'rescued',
            'history' => $rescued['history'],
            'tokenCount' => $estimate($rescued['history']),
            'compactionNotice' => $compactionNotice,
            'truncationNotice' => $truncationNotice,
            'refilled' => $refilled,
        ];
    }

    // ── the dispatch's bookkeeping ─────────────────────────────────────

    /**
     * The prompt $newTurnMessages carries: the content of its last user row,
     * or null when it has none.
     *
     * @param list<Message> $newTurnMessages
     */
    public function turnPrompt(array $newTurnMessages): ?string
    {
        $prompt = null;
        foreach ($newTurnMessages as $message) {
            if ($message instanceof Message && $message->role === Role::User) {
                $prompt = $message->content;
            }
        }

        return $prompt;
    }

    /**
     * The checkpoint state a dispatch saves: THE STATE BEFORE THE PROMPT, NOT
     * AFTER IT (audit SES-1). Every row the submission itself added — the
     * user's line, the hook notes, the 70% reminder — is out, because a resend
     * through the restored draft regenerates all three; the compaction the
     * submission triggered stays in with its reports, because a rewrite is a
     * fact about the transcript, not about the prompt. The draft and caret are
     * the caller's: on the ordinary route the pre-clear buffer, on the parked
     * route whatever the box held when it was parked.
     *
     * @param list<Message> $preTurnHistory
     * @return array<string, mixed>
     */
    public function checkpointState(array $preTurnHistory, string $draft, ?int $cursor, ?string $sessionId): array
    {
        return [
            'messages' => CompactionService::withoutContextReminders($preTurnHistory),
            self::CHECKPOINT_PRE_TURN_KEY => true,
            // THE DRAFT IS THE CALLER'S, not the dispatched state's, whose box
            // is already blank (E681).
            'inputBuf' => $draft,
            // The cursor travels WITH the draft (E4); null is the restore's
            // end-of-text fallback.
            'inputCursor' => $cursor,
            'inFlight' => false,
            'agentContext' => [
                'currentSessionId' => $sessionId,
            ],
        ];
    }

    /**
     * Save $state as $sessionId's next checkpoint and answer the workspace
     * capture that belongs beside it, or null when there is none to take.
     *
     * THE FILES, TOO (item 3.A-1): the same checkpoint gets a git snapshot of
     * the workspace — but NOT here: `git stash create` on a large repository
     * would stall the caller's thread. The capture is handed back to run at
     * the head of the turn's own work, after the frame is painted and before
     * the turn can fork and write a file. Only for a session given an explicit
     * project root ($root), never a `getcwd()` fallback, so an embedder's
     * process directory is not snapshotted behind its back. Neither the save
     * nor the snapshot ever costs the prompt.
     *
     * @param array<string, mixed> $state
     * @return (\Closure(): void)|null
     */
    public function saveCheckpoint(
        SessionStore|EnhancedSessionStore|null $store,
        ?string $sessionId,
        ?string $root,
        array $state,
    ): ?\Closure {
        if ($store === null || $sessionId === null || !method_exists($store, 'saveCheckpoint')) {
            return null;
        }

        try {
            $checkpointIndex = $store->saveCheckpoint($sessionId, $state);
        } catch (\Throwable) {
            // Ignore checkpoint save errors - don't block the prompt.
            return null;
        }

        if (!$store instanceof EnhancedSessionStore || $root === null || $root === '') {
            return null;
        }

        return static function () use ($store, $sessionId, $checkpointIndex, $root): void {
            try {
                $store->captureWorkspace($sessionId, $checkpointIndex, $root);
            } catch (\Throwable) {
                // A snapshot never costs the turn; the row simply has none.
            }
        };
    }

    /**
     * The row's turn count and last-prompt preview, which the session picker
     * shows instead of the system prompt every session shares (Appendix P
     * §3.1, audit B3). Picker bookkeeping only; never blocks the prompt.
     *
     * @param list<Message> $newTurnMessages
     */
    public function recordTurn(SessionStore|EnhancedSessionStore|null $store, ?string $sessionId, array $newTurnMessages): void
    {
        if ($store === null || $sessionId === null) {
            return;
        }

        $prompt = $this->turnPrompt($newTurnMessages);
        if ($prompt === null) {
            return;
        }

        try {
            $store->recordTurn($sessionId, $prompt);
        } catch (\Throwable) {
            // Picker bookkeeping only; never blocks the prompt.
        }
    }

    /**
     * Announce the prompt row(s) a dispatch commits as durable
     * `message.created` events in the session's {@see EventLog} (Appendix O
     * §6.5), each named by the identity its save will keep
     * ({@see TranscriptStore::identify()}) — the same id the turn's own
     * `turn.started` then cites as its `messageId`.
     *
     * Only the rows the USER sent: a visible user row that is not a UI-only
     * notice. Hook notes, reminders and compaction reports are the session's
     * own words, and their events belong to the producers that write them.
     *
     * NEVER FATAL TO THE TURN, the {@see EventLog} contract: a store that
     * persists nothing, a session with no id, or a write that fails records
     * nothing and the prompt goes out regardless.
     *
     * Written through $runner's {@see TurnRunner::announce()} — log first,
     * then broadcast — so a host's listeners hear the row the replay reads;
     * a caller with no runner of its own gets a listenerless one (log only).
     *
     * @param list<Message> $newTurnMessages
     * @return list<array{messageId: string, seq: int}> what was written
     */
    public function recordMessagesCreated(?TranscriptStore $transcripts, ?string $sessionId, array $newTurnMessages, ?TurnRunner $runner = null): array
    {
        $log = $transcripts?->events();
        if ($log === null || $sessionId === null || $transcripts?->persists() !== true) {
            return [];
        }

        $written = [];
        foreach ($newTurnMessages as $row) {
            if (!$row instanceof Message || $row->role !== Role::User || $row->uiOnly || !$row->userVisible) {
                continue;
            }

            try {
                $identity = $transcripts->identify($sessionId, $row);
                if ($identity === null) {
                    continue;
                }
                $heard = ($runner ??= TurnRunner::new())->announce(SessionEvent::new(self::MESSAGE_CREATED, [
                    'messageId' => $identity[0],
                    'ref' => $identity[1],
                    'role' => $row->role->value,
                    'kind' => 'user',
                    'content' => $row->content,
                    'createdAt' => (int) floor(microtime(true) * 1000),
                ], $sessionId), $transcripts, $sessionId);
                if ($heard?->seq !== null) {
                    $written[] = ['messageId' => $identity[0], 'seq' => $heard->seq];
                }
            } catch (\Throwable) {
                // Not written; the turn does not wait on its audience.
            }
        }

        return $written;
    }
}

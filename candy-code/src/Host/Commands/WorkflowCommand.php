<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\Commands;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tools\BuiltIn\WorkflowTool;
use SugarCraft\Code\Workflows\RunContextValue;
use SugarCraft\Code\Workflows\StageResult;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * `/workflow run|pause|resume|status|list|lint` (roadmap O-2h moved it out of
 * `Chat::handleWorkflowCommand()`; `Chat::workflowRun()` and
 * `Chat::driveWorkflowFiber()` delegate here).
 *
 * A run is a TURN, not a reply: {@see start()} answers with the echo and a
 * {@see CommandEffect::occupyTurn()} whose thunk steps the run's fiber from a
 * loop timer ({@see drive()}), so the TUI repaints between polls and a
 * headless host's loop never blocks. Everything else answers at once, and
 * holds whatever turn is running — `/workflow pause|status` may be typed
 * inside the run they control (audit WF-4).
 *
 * The read-only-window refusal of `run`/`resume` stays in `Chat`: it is about
 * which TUI owns the session, and a headless host holds the session's lock.
 */
final class WorkflowCommand implements HostCommand
{
    /**
     * How often the run's fiber is resumed. The loop is free between two
     * resumes, which is when the TUI paints the sub-agents the suspended run
     * has live.
     */
    public const STEP_INTERVAL_SECONDS = 0.05;

    public function run(CommandContext $context, string $inputText): CommandResult
    {
        $engine = $context->workflowEngine;
        if ($engine === null) {
            return self::respond($inputText, Lang::t('host.workflow.not_configured'), true);
        }

        [$command, $args] = self::subcommand($inputText);
        if ($command === '') {
            return self::help($inputText);
        }

        return match ($command) {
            'run' => self::start($engine, $inputText, $args, $context),
            'pause' => self::pause($engine, $inputText, $args),
            'resume' => self::resume($engine, $inputText, $args),
            'status' => self::status($engine, $inputText, $args, $context),
            'list' => self::list($engine, $inputText),
            'lint' => self::lintCommand($inputText, $args, $context),
            default => self::help($inputText, Lang::t('host.workflow.unknown_command', ['command' => $command])),
        };
    }

    /**
     * The sub-command word and the rest of `/workflow <word> <rest>`; the word
     * is '' for a bare `/workflow`.
     *
     * @return array{0: string, 1: string}
     */
    public static function subcommand(string $inputText): array
    {
        $after = CommandText::argument($inputText);
        if ($after === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $after, 2) ?: [$after];

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * A workflow command's answer: the echo and the reply, UI-only.
     *
     * The turn is HELD, not released: every caller runs idle except
     * `/workflow pause|status` mid-run (audit WF-4), and releasing it there
     * would free the run's turn while its fiber keeps going.
     */
    private static function respond(string $inputText, string $response, bool $error = false): CommandResult
    {
        $result = $error
            ? CommandResult::errorReply($inputText, $response)
            : CommandResult::reply($inputText, $response);

        // The turn is HELD, not released: every caller runs idle except
        // `/workflow pause|status` mid-run (audit WF-4), and releasing it
        // there would free the run's turn while its fiber keeps going.
        return $result->holdingTurn();
    }

    /**
     * The error sentence `/workflow` answers with, as a flagged UI-only row
     * (ISS-097): the occupy channel's report is a string, so the verdict has
     * to ride in the row itself — both drivers land a `Message` report
     * verbatim.
     */
    public static function errorRow(string $error): Message
    {
        return Message::assistant(Lang::t('host.workflow.error', ['error' => $error]))->withUiOnly()->withError();
    }

    /**
     * Show help text for /workflow command.
     */
    private static function help(string $inputText, ?string $error = null): CommandResult
    {
        $lines = [];
        if ($error !== null) {
            $lines[] = Lang::t('host.workflow.error', ['error' => $error]);
            $lines[] = '';
        }
        $lines[] = Lang::t('host.workflow.help.heading');
        $lines[] = '';
        $lines[] = Lang::t('host.workflow.help.run');
        $lines[] = Lang::t('host.workflow.help.pause');
        $lines[] = Lang::t('host.workflow.help.resume');
        $lines[] = Lang::t('host.workflow.help.status');
        $lines[] = Lang::t('host.workflow.help.list');
        $lines[] = Lang::t('host.workflow.help.lint');
        $lines[] = Lang::t('host.workflow.help.self');
        $lines[] = '';
        $lines[] = Lang::t('host.workflow.help.note');

        return self::respond($inputText, implode("\n", $lines), $error !== null);
    }

    /**
     * Handle /workflow run command.
     *
     * ## This used to freeze the whole TUI, and why the obvious fix was wrong
     *
     * `WorkflowEngine::run()` was called synchronously from inside `update()`,
     * so no frame painted, no keystroke was read and no spinner turned until
     * the last stage was over — for as long as the run took, up to
     * `ProcessExecutor`'s 300s-per-worker ceiling.
     *
     * The fix recorded here for a long time was "the fork-plus-socket pattern
     * {@see \SugarCraft\Code\Backend\EngineBackend::completeAsync()} already uses". Measured,
     * that pattern would have made the command asynchronous and made the
     * feature it was blocking permanently unreachable: the split-pane
     * compositor renders from `AgentManager::liveOutputs()`, which reads the
     * manager's sub-agent map — an object graph in THIS process. Fork the
     * workflow and every sub-agent it creates lives, and dies, in a child the
     * renderer cannot see. The parent would repaint a blank pane promptly.
     *
     * ## What it does instead
     *
     * The run goes into a `\Fiber`, and the driver resumes it from a periodic
     * timer on the same ReactPHP loop that repaints
     * ({@see drive()}). A fiber suspends its whole call stack, so
     * one suspension point deep inside the pool
     * ({@see \SugarCraft\Code\Agents\AgentWorkerPool::idle()}) yields the
     * entire `Chat → WorkflowEngine → AgentManager → AgentWorkerPool` chain
     * back to the loop, and everything stays in this process where the
     * renderer can see it.
     *
     * Nothing runs before this method returns: the fiber is not started here,
     * only handed to the timer. `update()` returns on the same tick the user
     * pressed Enter, with the command echoed and `inFlight` set.
     *
     * WHAT IS NOT COVERED: the yield granularity is one poll of a parallel
     * stage's worker pool. A stage type that blocks the PARENT rather than
     * dispatching to workers still holds the fiber for its duration; it just
     * no longer holds it for the whole workflow.
     *
     * The run itself is {@see CommandEffect::occupyTurn()}: `Chat` drives the
     * fiber from its Cmd channel and a headless host from its loop, both
     * through {@see drive()}.
     */
    public static function start(WorkflowEngineInterface $engine, string $inputText, string $args, ?CommandContext $commandContext = null): CommandResult
    {
        [$workflowName, $context, $problems] = self::parseRunArguments($args);

        if ($problems !== []) {
            // Loud refusal, not silent drop: the old whitespace split quietly
            // ran the plan without any pair it could not parse, and gave values
            // no way to carry a space.
            return self::respond(
                $inputText,
                Lang::t('host.workflow.error', ['error' => 'Malformed run arguments: '
                    . implode('; ', $problems)
                    . '. Quote values that contain spaces (key="two words").']),
                true,
            );
        }

        if ($workflowName === '') {
            return self::help($inputText, Lang::t('host.workflow.usage.run'));
        }

        // P0-4: lint before the first dispatch. Errors refuse the run with
        // nothing spent; warnings ride the report under its heading.
        $lintWarnings = '';
        $lint = $commandContext === null ? null : self::preRunLint($workflowName, $context, $commandContext);
        if ($lint !== null) {
            if ($lint->hasErrors()) {
                return self::respond(
                    $inputText,
                    Lang::t('host.workflow.error', ['error' => sprintf(
                        "workflow `%s` was not run: lint found %d error(s), %d warning(s) (nothing was dispatched).\n%s",
                        $workflowName,
                        $lint->errorCount(),
                        $lint->warningCount(),
                        $lint->renderFindings(),
                    )]),
                    true,
                );
            }
            $lintWarnings = WorkflowTool::lintWarningHeader($lint);
        }

        // The run's Esc Esc: held as this turn's inFlightCancellation, so the
        // double-Escape arm's existing cancel() reaches the engine, which
        // kills the stage's agents and stops the run (see drive()).
        $cancellation = new CancellationToken();

        // Built here, started by the driver's FIRST timer tick. Everything
        // inside runs off the main stack.
        $fiber = new \Fiber(static function () use ($engine, $workflowName, $context, $cancellation, $lintWarnings) {
            try {
                return self::withLintWarnings(self::describeWorkflowResult($workflowName, $engine->run($workflowName, $context, $cancellation)), $lintWarnings);
            } catch (\Throwable $e) {
                // One catch, where there used to be three arms
                // ({@see \SugarCraft\Code\Workflows\WorkflowNotFoundException}, {@see \SugarCraft\Code\Workflows\WorkflowLoadException},
                // everything else) that produced the same string: inside a
                // fiber the distinction matters LESS, not more, because an
                // uncaught throw here surfaces on the driver's timer tick with
                // no user-facing context at all.
                return self::errorRow($e->getMessage());
            }
        });

        // The workflow is a turn: it occupies the session, the spinner should
        // run, and a second prompt must queue behind it rather than interleave
        // with it. Released when drive() settles, on both the success and the
        // error path -- both resolve, neither rejects.
        return CommandResult::echo($inputText)->withEffect(
            CommandEffect::occupyTurn(static fn (): PromiseInterface => self::drive($fiber), $cancellation),
        );
    }

    /**
     * P0-4: the pre-run lint of a named workflow, through the same registry
     * tiers, tool universe and `uses:` resolver `/workflow lint` uses, seeded
     * with the run's own context so a `{{key}}` the user passed is not
     * reported. Null when the name does not load here: the engine's own
     * load refusal owns that verdict, and the lint must not invent one.
     *
     * @param array<string, string> $runContext
     */
    private static function preRunLint(string $workflowName, array $runContext, CommandContext $context): ?WorkflowLinter
    {
        try {
            $workflow = self::registryFor($context)->load($workflowName);
        } catch (\Throwable) {
            return null;
        }

        return WorkflowLinter::lint($workflow, $runContext, self::toolUniverse($context), self::includeResolver($context), self::presetNames($workflow, $context));
    }

    /**
     * C5: the preset names a `preset:` may name in this session, for the
     * lint's unknown-preset check — asked only when $workflow names one (the
     * roster reads preset files). The session's `/workflow` engine answers
     * first (its roster, or its manager's); a bare AgentManager next; null
     * leaves the check to the engine's own pre-flight refusal.
     *
     * @return ?list<string>
     */
    private static function presetNames(Workflow $workflow, CommandContext $context): ?array
    {
        if (WorkflowEngine::presetsNamedBy($workflow) === []) {
            return null;
        }

        if ($context->workflowEngine instanceof WorkflowEngine) {
            $names = $context->workflowEngine->presetNames();
            if ($names !== null) {
                return $names;
            }
        }

        if ($context->agentManager === null) {
            return null;
        }

        $names = array_values(array_unique(array_map(static fn ($agent): string => $agent->name, array_values($context->agentManager->all()))));
        sort($names);

        return $names;
    }

    /** $report with the lint-warning block placed under its heading line. */
    private static function withLintWarnings(string $report, string $warnings): string
    {
        if ($warnings === '') {
            return $report;
        }

        $split = strpos($report, "\n\n");

        return $split === false
            ? $report . "\n\n" . rtrim($warnings)
            : substr($report, 0, $split + 2) . $warnings . substr($report, $split + 2);
    }

    /**
     * Split a `/workflow run` argument line into [name, context, problems].
     *
     * Quote-aware: a value may carry spaces by wrapping it in single or double
     * quotes (`msg="two words"`), which the old whitespace split made
     * impossible. Every token that is not a well-formed key=value pair is
     * REPORTED by name instead of dropped — the previous loop ran the plan
     * without any pair it could not parse, silently. '@'-prefixed keys are
     * refused here as well, mirroring WorkflowEngine's own run-entry rule: the
     * engine rejects them, so accepting them at the keyboard is a lie.
     *
     * C6: `key:=<json>` (httpie's spelling) decodes its value as JSON, so a
     * list-reading stage (`from: {context: files}`) can run from here;
     * malformed JSON is reported naming the key. `key=value` stays a string.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: list<string>}
     */
    public static function parseRunArguments(string $args): array
    {
        $tokens = [];
        $current = '';
        $has = false;
        $quote = null;
        $length = strlen($args);

        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                } else {
                    $current .= $char;
                    $has = true;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $has = true;
                continue;
            }
            if ($char === ' ' || $char === "\t") {
                if ($has) {
                    $tokens[] = $current;
                    $current = '';
                    $has = false;
                }
                continue;
            }
            $current .= $char;
            $has = true;
        }

        if ($quote !== null) {
            return ['', [], ['unterminated quote in `' . $current . '`']];
        }
        if ($has) {
            $tokens[] = $current;
        }

        $name = $tokens[0] ?? '';
        $context = [];
        $problems = [];

        foreach (array_slice($tokens, 1) as $pair) {
            $split = RunContextValue::split($pair);
            if ($split === null) {
                $problems[] = 'expected key=value, got `' . $pair . '`';
                continue;
            }
            [$key, $raw, $isJson] = $split;
            if ($key === '') {
                $problems[] = 'empty key in `' . $pair . '`';
                continue;
            }
            if ($key[0] === '@') {
                $problems[] = '`' . $key . '` is a reserved run-context key — the engine refuses \'@\'' . "-prefixed names at run entry";
                continue;
            }
            if (!$isJson) {
                $context[$key] = $raw;
                continue;
            }
            // C6: `key:=<json>` carries a typed value — a list for
            // `from: {context: key}`, a number, an object. Quote it so the
            // JSON's own quotes survive: files:='["a.php","b.php"]'.
            try {
                $context[$key] = RunContextValue::decode($key, $raw);
            } catch (\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }

        return [$name, $context, $problems];
    }

    /**
     * How many stages of a finished run actually RAN.
     *
     * `count($result->stageResults)` is not that number: the whole-workflow
     * pre-flight in WorkflowEngine reports a refusal it caught before
     * dispatch as one synthetic Failed StageResult whose own engine comment
     * says "Nothing ran", so a run that dispatched nothing used to print
     * "Stages completed: 1" (tracker #85 / E8). The synthetic entry and a
     * per-stage declaration refusal that fired before the stage's first agent
     * share the same nothing-ran shape - Failed, no agents, no tokens - so
     * the rule is derived from that shape rather than from the pre-flight's
     * file of origin: a stage counts when it completed or when it ran agents.
     * A dispatch that threw before recording any agent is the same nothing
     * the pre-flight is; the honest line says 0.
     */
    public static function countDispatchedStages(WorkflowResult $result): int
    {
        return count(array_filter(
            $result->stageResults,
            static fn(StageResult $stage): bool => $stage->isSuccess() || $stage->agents !== [],
        ));
    }

    /**
     * Render a finished run as the assistant's reply.
     *
     * Static so the fiber body closes over nothing but its arguments: a fiber
     * outlives the `Chat` that created it (that instance is replaced on the
     * very next `update()`), and capturing a driver would pin a stale model
     * for the length of the run.
     */
    public static function describeWorkflowResult(string $workflowName, WorkflowResult $result, bool $resumed = false): string
    {
        // The heading is chosen from the status, never assumed. AUDIT WF-2:
        // `/workflow resume` printed "resumed and completed" for every result,
        // so a resumed run that FAILED again, or was paused again, read as a
        // success; and a run paused while live reported "completed".
        $outcome = match (true) {
            $result->status === WorkflowStatus::Paused => Lang::t('host.workflow.outcome.paused'),
            // Ahead of isFailure(), which counts Cancelled as a failure: a run
            // the user stopped did not fail, and must not read as though it had.
            $result->status === WorkflowStatus::Cancelled => Lang::t('host.workflow.outcome.cancelled'),
            $result->isFailure() => Lang::t('host.workflow.outcome.failed'),
            $result->isSuccess() => Lang::t('host.workflow.outcome.completed'),
            default => $result->status->value,
        };
        $response = Lang::t($resumed ? 'host.workflow.report.resumed' : 'host.workflow.report.heading', [
            'name' => $workflowName,
            'outcome' => $outcome,
        ]) . "\n\n";
        $response .= Lang::t('host.workflow.report.id', ['id' => $result->workflowId]) . "\n";
        $response .= Lang::t('host.workflow.report.status', ['status' => $result->status->value]) . "\n";
        $response .= Lang::t('host.workflow.report.stages', ['count' => self::countDispatchedStages($result)]) . "\n";
        $response .= Lang::t('host.workflow.report.tokens', ['tokens' => $result->totalTokens]) . "\n";
        $response .= Lang::t('host.workflow.report.cost', ['cost' => $result->totalCost]);
        // Since WF-1(b) a stage's agent may be re-run after a failed or
        // timed-out attempt; the pool folds every attempt into ONE result, so
        // without this line a stage that took three tries to pass (and paid
        // for all three) read exactly like one that passed first time.
        foreach ($result->stageResults as $stage) {
            $attempts = max([1, ...array_map(static fn(AgentResult $agent): int => $agent->attempts, $stage->agents)]);
            if ($attempts > 1) {
                $response .= "\n" . Lang::t('host.workflow.report.attempts', ['stage' => $stage->stageName, 'attempts' => $attempts]);
            }
        }
        // The failing stage's message, or the reason never reaches the
        // user at all: a failed run used to print the word "completed" in
        // bold with `Status: failed` under it and nothing else, so a stage
        // refused for declaring a tool this session's mode denies looked
        // like a workflow that had simply not worked. The engine puts the
        // reason on the stage; this is the only place that can show it.
        $failure = $result->firstFailure();
        if ($failure !== null && ($failure->error ?? '') !== '') {
            $response .= "\n\n" . Lang::t('host.workflow.report.failure', ['stage' => $failure->stageName, 'error' => $failure->error]);
        }

        // P2-2: the run's announcements — `announce:` lines and the
        // engine's mandatory notices (truncations, minSuccess casualties,
        // budget stops, the agent ceiling) — as transcript lines. Absent when
        // the run made none, so an announcement-free report is unchanged.
        if ($result->announcements !== []) {
            $response .= "\n\nAnnouncements:";
            foreach ($result->announcements as $line) {
                $response .= "\n- " . $line;
            }
        }

        if ($result->status === WorkflowStatus::Paused) {
            $response .= "\n\n" . Lang::t('host.workflow.report.continue', ['id' => $result->workflowId]);
        }

        if ($result->status === WorkflowStatus::Cancelled) {
            $response .= "\n\n" . Lang::t('host.workflow.report.cancelled');
        }

        return $response;
    }

    /**
     * Step a workflow fiber from the event loop until it terminates, and
     * resolve with its report — the text of the reply that settles the run
     * (`Chat` lands it as an `AssistantMsg`, or as a
     * {@see \SugarCraft\Code\App\CancelledWorkflowReportMsg} once Esc Esc released
     * the turn; a headless host appends it the same way).
     *
     * ## The invariant this exists to hold
     *
     * BETWEEN two resumes the loop is free. That is the entire point: sugar-core's
     * `Program` repaints from its own periodic timer on this same loop, so a
     * frame lands in every gap, and `Renderer::renderView()` reads
     * `AgentManager::liveOutputs()` at that moment — the sub-agents the
     * suspended fiber has running, in this process, with the partial text
     * `AgentWorkerPool::pumpProgress()` mirrored onto them on its last poll.
     *
     * `start()` therefore happens on the first TICK, never inline: doing it
     * here would run the workflow up to its first suspension point inside
     * `update()`, which is the freeze this change is about, only shorter.
     *
     * ## Failure and cancellation
     *
     * The promise RESOLVES on a throwing fiber rather than rejecting. A
     * rejection dispatches sugar-core's `ExceptionMsg`, which this model does
     * not handle, so a workflow that died would have cleared nothing and left
     * `inFlight` latched on forever with no message to explain it. An error
     * notice through the ordinary AssistantMsg arm both tells the user and
     * releases the turn.
     *
     * The timer is cancelled on every exit, including the throwing one; a live
     * periodic timer holding a terminated fiber would resume it and raise
     * `FiberError` on the next tick.
     *
     * ## Double-Escape stops the run
     *
     * $cancellation is the run's token, held as the turn's
     * `inFlightCancellation`, so the double-Escape arm's `cancel()` reaches
     * the engine. `WorkflowEngine` registered on it
     * ({@see CancellationToken::onCancel()}), so the cancel calls
     * `AgentWorkerPool::cancelAll()` on the stage's live pool THERE AND THEN —
     * the fiber is suspended in that pool's idle poll and could not look at a
     * flag itself — killing the stage's forked agents (asynchronously, with a
     * grace, on the loop), and the stage loop then stops with a Cancelled
     * result instead of starting the next stage. An engine that ignores the
     * token just finishes.
     *
     * THE PARTIAL REPORT STILL LANDS, marked cancelled
     * ({@see describeWorkflowResult()}): the stages that ran really ran and
     * cost money, and dropping the report would leave no record of them. It
     * arrives as a {@see \SugarCraft\Code\App\CancelledWorkflowReportMsg}, NOT an AssistantMsg,
     * because the cancel already released the turn — by the time the run
     * winds down the user may have started another, and an AssistantMsg would
     * settle that one on this run's behalf. This used to be a known
     * limitation: Esc Esc released the turn and the workflow ran on to the
     * end, forked workers and all.
     *
     * WHAT THAT LIMITATION USED TO IMPLY, and no longer does: because the
     * released turn accepts input again, a user could type a SECOND
     * `/workflow run` while the first was still stepping, and get it. Measured
     * — two runs live at once, exiting in an order unrelated to the order they
     * started, each popping the other's SIGINT/SIGTERM frame off
     * `WorkflowEngine`'s LIFO handler stack, and both collapsing onto one
     * `$resultsByName` slot when they shared a name (so `/workflow pause` on
     * run A's own printed id persisted run B). `WorkflowEngine` now REFUSES a
     * run that would interleave with a live one and says so; see
     * `WorkflowEngine::$liveRunOwners`. Nesting — a stage re-entering `run()`
     * on the same call stack — is unaffected and still works. The refusal
     * still matters after a cancel: the run winds down over the kill's grace,
     * and a `/workflow run` typed in that window is refused rather than
     * interleaved.
     */
    public static function drive(\Fiber $fiber, float $interval = self::STEP_INTERVAL_SECONDS): PromiseInterface
    {
        $deferred = new Deferred();
        $loop = Loop::get();
        $timer = null;

        $timer = $loop->addPeriodicTimer(
            $interval,
            static function () use ($fiber, $loop, &$timer, $deferred): void {
                try {
                    $fiber->isStarted() ? $fiber->resume() : $fiber->start();
                } catch (\Throwable $e) {
                    $loop->cancelTimer($timer);
                    $deferred->resolve(self::errorRow($e->getMessage()));

                    return;
                }

                if (!$fiber->isTerminated()) {
                    return;
                }

                $loop->cancelTimer($timer);
                $report = $fiber->getReturn();
                $deferred->resolve($report instanceof Message ? $report : (string) $report);
            },
        );

        return $deferred->promise();
    }

    /**
     * Handle /workflow pause command.
     *
     * A LIVE run is reachable here: its fiber is suspended between agent polls
     * whenever this runs. The turn the run occupies refuses slash commands
     * (`Chat::refuseInFlightCommand()`) except this one and `/workflow status`
     * (`Chat::isWorkflowControlDuringWorkflowTurn()`, audit WF-4), so the user
     * pauses it mid-run by typing it — the turn stays in flight. The engine
     * then stops the run before its next stage, and the run's own report, when
     * {@see drive()} delivers it, says `paused` (AUDIT WF-2).
     *
     * Pause (cooperative here, or via WorkflowEngine's real SIGINT/SIGTERM
     * handling on a genuine interrupt) captures whatever whole stages have
     * actually completed so far. Resume granularity stays per-whole-stage
     * only: if a 'parallel' stage is mid-flight when the pause happens, its
     * individual in-progress agent results are not captured and that stage
     * is re-run from scratch on resume — there is no partial-credit resume
     * for a parallel sub-stage. See WorkflowEngine's class docblock.
     *
     */
    public static function pause(WorkflowEngineInterface $engine, string $inputText, string $args): CommandResult
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return self::help($inputText, Lang::t('host.workflow.usage.pause'));
        }

        try {
            // Asked BEFORE pausing, because the two cases mean different things
            // to the user: a finished run is paused at once, while a LIVE one
            // (its fiber suspended between agent polls) finishes the stage in
            // flight and stops before the next — its report lands later, as
            // `paused`, through the run's own fiber.
            try {
                $live = $engine->status($workflowId) === WorkflowStatus::Running;
            } catch (\Throwable) {
                $live = false;
            }

            $engine->pause($workflowId);
            return self::respond($inputText, $live
                ? Lang::t('host.workflow.pause_requested', ['id' => $workflowId])
                : Lang::t('host.workflow.paused', ['id' => $workflowId]));
        } catch (\Throwable $e) {
            return self::respond($inputText, Lang::t('host.workflow.error', ['error' => $e->getMessage()]), true);
        }
    }

    /**
     * Handle /workflow resume command.
     *
     * Driven exactly like {@see start()}: the resume goes into a `\Fiber`
     * stepped by {@see drive()}, `inFlight` is set, and the report
     * arrives as the reply when it settles. AUDIT WF-2: it used to run
     * synchronously inside `update()`, so a resumed run froze the TUI for its
     * whole length and — since nothing else could run while it did — could
     * never be paused. It also printed "resumed and completed" whatever the
     * result was; the heading now comes from {@see describeWorkflowResult()}.
     *
     */
    public static function resume(WorkflowEngineInterface $engine, string $inputText, string $args): CommandResult
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return self::help($inputText, Lang::t('host.workflow.usage.resume'));
        }

        // Esc Esc stops a resumed run exactly as a fresh one; see start().
        $cancellation = new CancellationToken();

        // Static for the reason start()'s fiber is: it outlives the Chat that typed it.
        $fiber = new \Fiber(static function () use ($engine, $workflowId, $cancellation) {
            try {
                return self::describeWorkflowResult($workflowId, $engine->resume($workflowId, $cancellation), resumed: true);
            } catch (\Throwable $e) {
                // WorkflowNotRunningException (nothing paused under that id),
                // WorkflowNotFoundException (the definition is gone) and the
                // engine's interleaving refusal all read the same to the user.
                return self::errorRow($e->getMessage());
            }
        });

// A resumed run is a turn exactly as a fresh one is; see start().
        return CommandResult::echo($inputText)->withEffect(
            CommandEffect::occupyTurn(static fn (): PromiseInterface => self::drive($fiber), $cancellation),
        );
    }

    /**
     * Handle /workflow status command.
     *
     */
    public static function status(WorkflowEngineInterface $engine, string $inputText, string $args, ?CommandContext $context = null): CommandResult
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return self::help($inputText, Lang::t('host.workflow.usage.status'));
        }

        try {
            $status = $engine->status($workflowId);

            $text = Lang::t('host.workflow.status', ['id' => $workflowId, 'status' => $status->value]);

            // C14: a PAUSED run's own ledger file carries the per-stage plan
            // the enum line hides — durations, spend, tokens, attempts. The
            // engine's interface only answers the enum, and live runs keep
            // their stage detail in a private map, so the pause plane is what
            // is reachable from here; when there is no ledger the plain row
            // stands exactly as before. The whole leg is fail-soft: a status
            // line must never break because its garnish could not be read.
            if ($context !== null) {
                try {
                    $ledger = WorkflowLinter::readPauseLedger(self::registryFor($context)->workflowsPath(), $workflowId);
                } catch (\Throwable) {
                    $ledger = null;
                }
                if ($ledger !== null) {
                    $detail = WorkflowLinter::renderStageLedger($ledger);
                    if ($detail !== '') {
                        $text .= "\n\n" . $detail;
                    }
                }
            }

            return self::respond($inputText, $text);
        } catch (\Throwable $e) {
            return self::respond($inputText, Lang::t('host.workflow.error', ['error' => $e->getMessage()]), true);
        }
    }

    /**
     * Handle /workflow list command.
     *
     */
    public static function list(WorkflowEngineInterface $engine, string $inputText): CommandResult
    {
        $workflows = $engine->listWorkflows();

        if ($workflows === []) {
            // BOTH tiers named, because Bootstrap::workflowEngine() searches
            // both: naming only the home one sends a user who checked a
            // workflow into their repo off to fix the wrong directory. The
            // project tier's `.yaml`-only rule is named for the same reason —
            // otherwise a user who committed `deploy.php` is pointed at the
            // right directory and the wrong extension (see
            // WorkflowRegistry::__construct() for why that tier refuses PHP).
            $response = Lang::t('host.workflow.none');
        } else {
            $lines = [Lang::t('host.workflow.list_heading')];
            foreach ($workflows as $i => $name) {
                $lines[] = ($i + 1) . ". `{$name}`";
            }
            $response = implode("\n", $lines);
        }

        return self::respond($inputText, $response);
    }

    /**
     * Handle /workflow lint command (C5).
     *
     * Static check of a workflow plan WITHOUT dispatching agents: a plan
     * table plus findings with severity. The cannot-load verdict rides an
     * error-flagged row — the slash surface has no exit codes, and the
     * 0/1/2 mapping is reported as a protocol seam (WorkflowMethods carries
     * no lint row and protocol regen is a serialized surface).
     */
    private static function lintCommand(string $inputText, string $args, CommandContext $context): CommandResult
    {
        $target = trim($args);

        if ($target === '') {
            return self::help($inputText, Lang::t('host.workflow.usage.lint'));
        }

        try {
            $workflow = self::lintTarget($target, $context);
        } catch (\Throwable $e) {
            return self::respond($inputText, Lang::t('host.workflow.error', ['error' => 'cannot-load — ' . $e->getMessage()]), true);
        }

        // C11: the include-compose edges resolve against the same two tiers
        // the engine will load through at run time, so `lint` by name or by
        // .yaml path sees the cycle/depth the run would hit. A name the
        // registry cannot load stays silent here — load()'s run-start
        // refusal owns that verdict, and the linter must not invent it.
        $includeResolver = self::includeResolver($context);

        $report = WorkflowLinter::lint($workflow, [], self::toolUniverse($context), $includeResolver, self::presetNames($workflow, $context));

        // The framing is localised (S-H08); the plan table, findings and
        // verdictLine() content stay machine-side English by design — they
        // are the linter's own stable report text.
        $lines = [Lang::t('host.workflows.lint_header', ['name' => $workflow->name]), '', $report->renderPlanTable()];
        if ($report->hasFindings()) {
            $lines[] = '';
            $lines[] = $report->renderFindings();
        }
        $lines[] = '';
        $lines[] = Lang::t('host.workflows.lint_verdict', ['verdict' => $report->verdictLine()]);

        return self::respond($inputText, implode("\n", $lines), $report->hasErrors());
    }

    /**
     * Resolve the lint target: a `<file>.yaml`/`.yml` path loads through the
     * same fail-closed YAML parser the registry uses (a path lint cannot
     * consult tiers it was not asked about); anything else is a NAME and
     * loads through the registry tiers, project before user. A `.php` path
     * is refused loudly — requiring it would EXECUTE the file.
     */
    private static function lintTarget(string $target, CommandContext $context): Workflow
    {
        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            throw new \RuntimeException('a PHP workflow file executes at load; lint it by NAME (the registry tiers load it) instead');
        }

        if ($extension === 'yaml' || $extension === 'yml') {
            $contents = is_file($target) ? @file_get_contents($target) : false;
            if ($contents === false) {
                throw new \RuntimeException("no workflow file at {$target}");
            }

            return (new WorkflowRegistry())->fromYamlString($contents, $target);
        }

        return self::registryFor($context)->load($target);
    }

    /**
     * The same two tiers WorkflowEngine sees: a read-only mirror of
     * Bootstrap::workflowEngine()'s registry wiring (project tier from the
     * resolved config root, user tier from the trusted config dir). Built
     * per call on purpose — the command must not cache a registry across
     * config edits.
     */
    private static function registryFor(CommandContext $context): WorkflowRegistry
    {
        $configRoot = LaunchContext::configRoot($context->root);
        $userConfigDir = Bootstrap::trustedConfigDirPath();

        return new WorkflowRegistry(
            $userConfigDir . '/workflows',
            $configRoot === null ? null : rtrim($configRoot, '/') . '/.candy-code/workflows',
            projectRoot: $configRoot,
            userHome: dirname($userConfigDir),
        );
    }

    /**
     * C11: the `uses:` edge walker's registry view for
     * {@see WorkflowLinter::lint()} — a name loads or it yields null, and
     * null means "no verdict from here", never "no edge".
     */
    private static function includeResolver(CommandContext $context): callable
    {
        $registry = self::registryFor($context);

        return static function (string $name) use ($registry): ?Workflow {
            try {
                return $registry->load($name);
            } catch (\Throwable) {
                return null;
            }
        };
    }

    /**
     * The declared-vs-known tool leg needs the session's tool universe;
     * EngineBackend::tools() is not on the Backend contract, so a context
     * without one (or with a non-engine double) degrades the leg to skipped
     * rather than guessing a universe.
     *
     * @return ?list<string> null = universe unreachable => skip that leg
     */
    private static function toolUniverse(CommandContext $context): ?array
    {
        $backend = $context->backend;
        if (!$backend instanceof EngineBackend) {
            return null;
        }

        return WorkflowTool::toolNamesOf($backend);
    }
}

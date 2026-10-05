<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Workflows\StageResult;
use SugarCraft\Crush\Workflows\WorkflowEngineInterface;
use SugarCraft\Crush\Workflows\WorkflowNotRunningException;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * `/workflow run|pause|resume|status|list` (roadmap O-2h moved it out of
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
            return self::respond($inputText, Lang::t('host.workflow.not_configured'));
        }

        [$command, $args] = self::subcommand($inputText);
        if ($command === '') {
            return self::help($inputText);
        }

        return match ($command) {
            'run' => self::start($engine, $inputText, $args),
            'pause' => self::pause($engine, $inputText, $args),
            'resume' => self::resume($engine, $inputText, $args),
            'status' => self::status($engine, $inputText, $args),
            'list' => self::list($engine, $inputText),
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
    private static function respond(string $inputText, string $response): CommandResult
    {
        return CommandResult::reply($inputText, $response)->holdingTurn();
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
        $lines[] = Lang::t('host.workflow.help.self');
        $lines[] = '';
        $lines[] = Lang::t('host.workflow.help.note');

        return self::respond($inputText, implode("\n", $lines));
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
     * {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()} already uses". Measured,
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
     * ({@see \SugarCraft\Crush\Agents\AgentWorkerPool::idle()}) yields the
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
    public static function start(WorkflowEngineInterface $engine, string $inputText, string $args): CommandResult
    {
        $argParts = preg_split('/\s+/', $args);
        $workflowName = $argParts[0] ?? '';

        if ($workflowName === '') {
            return self::help($inputText, Lang::t('host.workflow.usage.run'));
        }

        // Parse key=val context pairs
        $context = [];
        foreach (array_slice($argParts, 1) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $context[trim($k)] = trim($v);
            }
        }

        // The run's Esc Esc: held as this turn's inFlightCancellation, so the
        // double-Escape arm's existing cancel() reaches the engine, which
        // kills the stage's agents and stops the run (see drive()).
        $cancellation = new CancellationToken();

        // Built here, started by the driver's FIRST timer tick. Everything
        // inside runs off the main stack.
        $fiber = new \Fiber(static function () use ($engine, $workflowName, $context, $cancellation): string {
            try {
                return self::describeWorkflowResult($workflowName, $engine->run($workflowName, $context, $cancellation));
            } catch (\Throwable $e) {
                // One catch, where there used to be three arms
                // ({@see \SugarCraft\Crush\Workflows\WorkflowNotFoundException}, {@see \SugarCraft\Crush\Workflows\WorkflowLoadException},
                // everything else) that produced the same string: inside a
                // fiber the distinction matters LESS, not more, because an
                // uncaught throw here surfaces on the driver's timer tick with
                // no user-facing context at all.
                return Lang::t('host.workflow.error', ['error' => $e->getMessage()]);
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
     * {@see \SugarCraft\Crush\CancelledWorkflowReportMsg} once Esc Esc released
     * the turn; a headless host appends it the same way).
     *
     * ## The invariant this exists to hold
     *
     * BETWEEN two resumes the loop is free. That is the entire point: candy-core's
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
     * rejection dispatches candy-core's `ExceptionMsg`, which this model does
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
     * arrives as a {@see \SugarCraft\Crush\CancelledWorkflowReportMsg}, NOT an AssistantMsg,
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
                    $deferred->resolve(Lang::t('host.workflow.error', ['error' => $e->getMessage()]));

                    return;
                }

                if (!$fiber->isTerminated()) {
                    return;
                }

                $loop->cancelTimer($timer);
                $deferred->resolve((string) $fiber->getReturn());
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
                $live = $engine->getStatus($workflowId) === WorkflowStatus::Running;
            } catch (\Throwable) {
                $live = false;
            }

            $engine->pause($workflowId);
            $response = $live
                ? Lang::t('host.workflow.pause_requested', ['id' => $workflowId])
                : Lang::t('host.workflow.paused', ['id' => $workflowId]);
        } catch (WorkflowNotRunningException $e) {
            $response = Lang::t('host.workflow.error', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $response = Lang::t('host.workflow.error', ['error' => $e->getMessage()]);
        }

        return self::respond($inputText, $response);
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
        $fiber = new \Fiber(static function () use ($engine, $workflowId, $cancellation): string {
            try {
                return self::describeWorkflowResult($workflowId, $engine->resume($workflowId, $cancellation), resumed: true);
            } catch (\Throwable $e) {
                // WorkflowNotRunningException (nothing paused under that id),
                // WorkflowNotFoundException (the definition is gone) and the
                // engine's interleaving refusal all read the same to the user.
                return Lang::t('host.workflow.error', ['error' => $e->getMessage()]);
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
    public static function status(WorkflowEngineInterface $engine, string $inputText, string $args): CommandResult
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return self::help($inputText, Lang::t('host.workflow.usage.status'));
        }

        try {
            $status = $engine->getStatus($workflowId);
            $response = Lang::t('host.workflow.status', ['id' => $workflowId, 'status' => $status->value]);
        } catch (WorkflowNotRunningException $e) {
            $response = Lang::t('host.workflow.error', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $response = Lang::t('host.workflow.error', ['error' => $e->getMessage()]);
        }

        return self::respond($inputText, $response);
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
}

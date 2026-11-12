<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * Fluent builder for assembling a Workflow value object.
 *
 * Mirrors the DSL usage seen in workflow definitions:
 *   $b->name('refactor-service')
 *      ->description('Refactor a microservice with tests and docs')
 *      ->stage('analyze', Tasks::agent('architect')->prompt('...'))
 *      ->parallel('implement', [Tasks::agent('coder'), Tasks::agent('tester')])
 *      ->maxConcurrent(5)
 *      ->timeout(3600)
 *      ->build();
 *
 * Each command method returns $this for chaining, and build() produces
 * the immutable Workflow value object. {@see withVerification()} is the
 * one true wither: house law reserves the `with*` prefix for copy-on-write
 * returns (AGENTS.md "Immutable + fluent"), and it is the only method here
 * so named — callers must use its return value, never the discarded-call
 * side effect the command methods offer.
 */
final class WorkflowBuilder
{
    private string $name = '';
    private string $description = '';
    /** @var array<int, array{name: string, type: string, tasks?: array<int, mixed>}> */
    private array $stages = [];
    private ?int $maxConcurrent = null;
    private int $maxAgents = Workflow::DEFAULT_MAX_AGENTS;
    private int $timeout = 3600;
    private bool $stopOnFirstFailure = false;

    /**
     * Set the human-readable name of the workflow.
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set the brief description of what the workflow does.
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Add a sequential stage: one task, or a list of tasks run one after
     * another (roadmap 4.10-1).
     *
     * In a multi-task stage each task after the first sees the previous one's
     * output as `{{prevResult}}`, the tasks share the stage's timeout, and the
     * first task that fails stops the stage — see
     * {@see WorkflowEngine} `executeStage()`. For tasks that should run at the
     * same time use {@see parallel()} instead.
     *
     * @param TaskBuilder|list<TaskBuilder> $tasks
     * @param bool $capture S3: write this stage's FULL output to the run's
     *        capture file and hand downstream stages the path plus a bounded
     *        preview instead of the dump — see
     *        {@see WorkflowEngine::captureStageOutput()}.
     * @param bool $cache C13: OPT-IN resume reuse. When true, a resume that
     *        carries this stage's recorded result AND a matching input hash
     *        settles the stage from the record instead of re-dispatching
     *        agents (see {@see WorkflowEngine}). Default off: a stage re-runs
     *        unless its author says its output is stable enough to trust.
     *
     * @throws \InvalidArgumentException When $tasks is an empty list or holds
     *         something other than TaskBuilders.
     */
    public function stage(string $name, TaskBuilder|array $tasks, bool $capture = false, bool $cache = false): self
    {
        $tasks = $tasks instanceof TaskBuilder ? [$tasks] : array_values($tasks);
        if ($tasks === []) {
            throw new \InvalidArgumentException("Stage '{$name}' needs at least one task");
        }

        $built = [];
        foreach ($tasks as $i => $task) {
            if (!$task instanceof TaskBuilder) {
                throw new \InvalidArgumentException(sprintf(
                    "Stage '%s' task #%d must be a TaskBuilder, got %s",
                    $name,
                    $i + 1,
                    get_debug_type($task),
                ));
            }
            $built[] = $task->build();
        }

        $this->stages[] = [
            'name' => $name,
            'type' => 'stage',
            'tasks' => $built,
        ]
            + ($capture === true ? ['capture' => true] : [])
            + ($cache === true ? ['cache' => true] : []);

        return $this;
    }

    /**
     * Add a parallel stage containing multiple tasks that run concurrently.
     *
     * Part of the parallel() primitive implementation (P4.S11) which spans:
     *   - WorkflowEngine.php: executeParallelStage() orchestrates concurrent execution
     *   - WorkflowBuilder.php: parallel() registers the stage definition
     *   - Workflow.php: $stopOnFirstFailure property controls fail-fast
     *   - WorkflowRegistry.php: parseStages() recognizes parallel type stages
     *   - AgentWorkerPool.php: executeAll() runs agents concurrently
     *
     * @param TaskBuilder[] $tasks
     * @param array{glob: string, chunk?: int|null, max?: int|null}|array{} $from C8 dynamic-wave
     *        spec, empty for the static list spelling. The engine expands the
     *        glob at run time and replaces $tasks with one built task per
     *        match; the template in $tasks[0] is what gets fanned out.
     *        P0-3 `max` keeps the first N sorted matches and records the
     *        rest as dropped in the stage output (1 to
     *        {@see Workflow::FAN_OUT_CEILING}).
     *
     * @throws \InvalidArgumentException When $from is not that shape.
     */
    public function parallel(string $name, array $tasks, bool $capture = false, bool $cache = false, array $from = []): self
    {
        if ($from !== []) {
            $from = self::normalizedFrom($name, $from);
        }

        $builtTasks = array_map(
            static fn(TaskBuilder $t) => $t->build(),
            $tasks,
        );

        $this->stages[] = [
            'name' => $name,
            'type' => 'parallel',
            'tasks' => $builtTasks,
        ]
            + ($capture === true ? ['capture' => true] : [])
            + ($cache === true ? ['cache' => true] : [])
            + ($from !== [] ? ['from' => $from] : []);

        return $this;
    }

    /**
     * The PHP spelling of a `from:` spec held to the YAML loader's shape law
     * (builder parity): `glob` a non-empty string, `chunk` null or >= 1,
     * `max` absent/null or 1..{@see Workflow::FAN_OUT_CEILING}, nothing else.
     * `max` is stored only when set, so a max-less spec keeps the two-key
     * shape the engine and the pause record have always carried.
     *
     * @param array<array-key, mixed> $from
     * @return array{glob: string, chunk: int|null, max?: int}
     */
    private static function normalizedFrom(string $stage, array $from): array
    {
        foreach (array_keys($from) as $key) {
            if (!in_array($key, WorkflowRegistry::YAML_FROM_KEYS, true)) {
                throw new \InvalidArgumentException("Stage '{$stage}' from has unknown key \"{$key}\"; supported keys: " . implode(', ', WorkflowRegistry::YAML_FROM_KEYS));
            }
        }

        $glob = $from['glob'] ?? null;
        if (!is_string($glob) || trim($glob) === '') {
            throw new \InvalidArgumentException("Stage '{$stage}' from needs a non-empty \"glob\"");
        }

        $chunk = $from['chunk'] ?? null;
        if ($chunk !== null && (!is_int($chunk) || $chunk < 1)) {
            throw new \InvalidArgumentException("Stage '{$stage}' from \"chunk\" must be a whole number of at least 1");
        }

        $spec = ['glob' => trim($glob), 'chunk' => $chunk];
        $max = $from['max'] ?? null;
        if ($max !== null) {
            if (!is_int($max) || $max < 1 || $max > Workflow::FAN_OUT_CEILING) {
                throw new \InvalidArgumentException(
                    "Stage '{$stage}' from \"max\" must be a whole number from 1 to " . Workflow::FAN_OUT_CEILING
                );
            }
            $spec['max'] = $max;
        }

        return $spec;
    }

    /**
     * Add a pipeline stage containing nested stages that chain output to input.
     *
     * Each nested stage receives `{{prevResult}}` interpolated with the previous
     * stage's output string, enabling sequential transformation pipelines.
     *
     * @param TaskBuilder[]|array[] $stages Each element is a TaskBuilder instance or a plain array
     *                                     with 'name', 'type', 'tasks' keys.
     */
    public function pipeline(string $name, array $stages, bool $capture = false, bool $cache = false): self
    {
        $nestedStageArrays = [];
        foreach ($stages as $index => $stage) {
            if ($stage instanceof TaskBuilder) {
                $workflowTask = $stage->build();
                // Use explicit task name, agentType, or generated index as the sub-stage name
                $subName = $workflowTask->name ?? $workflowTask->agentType ?? "step-{$index}";
                $nestedStageArrays[] = [
                    'name' => $subName,
                    'type' => 'stage',
                    'tasks' => [$workflowTask],
                ];
            } else {
                // Plain array (pre-built stage definition) — pass through as-is
                $nestedStageArrays[] = $stage;
            }
        }

        $this->stages[] = [
            'name' => $name,
            'type' => 'pipeline',
            'stages' => $nestedStageArrays,
        ]
            + ($capture === true ? ['capture' => true] : [])
            + ($cache === true ? ['cache' => true] : []);

        return $this;
    }

    /**
     * Set how many agents of a parallel stage run at once (P0-3). Null, the
     * default, leaves it unset: the engine runs
     * {@see Workflow::defaultConcurrency()} of the host's CPUs,
     * `min(16, max(1, cpus - 2))`. 0 is the explicit "no cap" — every task
     * of a parallel stage is dispatched at once (the linter warns on it).
     *
     * @throws \InvalidArgumentException When $n is negative.
     */
    public function maxConcurrent(?int $n): self
    {
        if ($n !== null && $n < 0) {
            throw new \InvalidArgumentException("maxConcurrent() needs 0 (no cap) or a width of at least 1, got {$n}");
        }

        $this->maxConcurrent = $n;
        return $this;
    }

    /**
     * Set the run-wide agent-dispatch ceiling (P0-3, `config.maxAgents`,
     * default {@see Workflow::DEFAULT_MAX_AGENTS}). Every dispatch counts —
     * retries, stall restarts, loop rounds and an included workflow's
     * agents — and a dispatch past it fails its stage with "agent ceiling
     * reached (N)".
     *
     * @throws \InvalidArgumentException When $n is below 1.
     */
    public function maxAgents(int $n): self
    {
        if ($n < 1) {
            throw new \InvalidArgumentException("maxAgents() needs a whole number of at least 1, got {$n}");
        }

        $this->maxAgents = $n;
        return $this;
    }

    /**
     * Return a builder with a verification stage appended: a task runs, then
     * a verifier validates its result.
     *
     * The task executes first; if it succeeds the verifier runs to validate
     * the result. If the verifier returns failure, the entire stage fails.
     *
     * Copy-on-write per the house `with*` law (rv-php-8): the clone carries
     * the stages accumulated so far, the receiver is left untouched, so
     * composing two withers can never double-add. The clone is safe as a
     * shallow copy — every field is a scalar or a copy-on-write array.
     *
     * @param string      $name     Human-readable name for this stage.
     * @param TaskBuilder $task     The task to run and then verify.
     * @param TaskBuilder $verifier The verifier that checks the task output.
     * @param bool        $capture  Same S3 law as {@see stage()}'s capture.
     * @param bool        $cache    Same C13 opt-in resume-reuse law as
     *        {@see stage()}'s cache: the recorded hash covers BOTH slots'
     *        task and verifier prompts, so a carried stage replays exactly
     *        what a prior run verified.
     */
    public function withVerification(string $name, TaskBuilder $task, TaskBuilder $verifier, bool $capture = false, bool $cache = false): self
    {
        $clone = clone $this;
        $clone->stages[] = [
            'name' => $name,
            'type' => 'verification',
            'task' => $task->build(),
            'verifier' => $verifier->build(),
        ]
            + ($capture === true ? ['capture' => true] : [])
            + ($cache === true ? ['cache' => true] : []);

        return $clone;
    }

    /**
     * Add an include-compose stage (C11): the stage's body is the whole of
     * another registered workflow, run to completion as ONE outer stage row.
     *
     * `uses:` is an ARM, not a fifth stage type: the stored shape keeps
     * `type: 'stage'` (the shared list in {@see Workflow} stays the engine's
     * dispatch truth) and carries the included workflow's name under `uses`,
     * optionally with `with` values interpolated in the OUTER context and
     * injected as the inner start context. Exactly one body per stage — the
     * loader refuses `uses:` alongside agent:/tasks:/task:/agents:/stages:/
     * parallel:/a non-stage type:, and the engine refuses chains deeper than
     * three nodes or that cycle, both raw English carrying the chain like
     * `a -> b -> c -> d`.
     *
     * @param array<string, string> $with
     */
    public function uses(string $name, string $included, array $with = [], bool $capture = false, bool $cache = false): self
    {
        $this->stages[] = [
            'name' => $name,
            'type' => 'stage',
            'uses' => $included,
        ]
            + ($with !== [] ? ['with' => $with] : [])
            + ($capture === true ? ['capture' => true] : [])
            + ($cache === true ? ['cache' => true] : []);

        return $this;
    }

    /**
     * Set the per-stage timeout in seconds.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * C2: attach a `when:` condition to the stage most recently added by
     * {@see stage()}, {@see parallel()}, {@see pipeline()} or
     * {@see withVerification()}. The clause is normalized and refused at
     * call time by {@see StageCondition::parse()} — malformed grammar never
     * rides into the built workflow (the same law the YAML loader enforces,
     * and the same raw-English InvalidArgumentException shape
     * {@see TaskBuilder::reject()} uses).
     *
     * @param array<string, mixed> $clause the author's `when:` map
     *
     * @throws \InvalidArgumentException when no stage exists to attach to,
     *         or the clause is not the closed grammar.
     */
    public function when(array $clause): self
    {
        if ($this->stages === []) {
            throw new \InvalidArgumentException('when() needs a stage to attach to; call it after stage(), parallel(), pipeline() or withVerification()');
        }

        $normalized = StageCondition::parse($clause);
        $this->stages[array_key_last($this->stages)]['when'] = $normalized;

        return $this;
    }

    /**
     * C4: attach a `loop:` round policy to the stage most recently added by
     * {@see stage()}. The map is a closed pair — `max` (whole number ≥ 1) and
     * `until` (a C2 clause) — normalized and refused at call time, the same
     * raw-English InvalidArgumentException shape {@see when()} rides. v1 is
     * stage-only: {@see parallel()}, {@see pipeline()} and
     * {@see withVerification()} rows refuse it, so a built workflow can never
     * carry a round policy its runner would not honour.
     *
     * @param array<string, mixed> $loop the author's `loop:` map
     *
     * @throws \InvalidArgumentException when no stage exists to attach to, the
     *         last stage is not a plain one, or the map is not the closed pair.
     */
    public function loop(array $loop): self
    {
        if ($this->stages === []) {
            throw new \InvalidArgumentException('loop() needs a stage to attach to; call it after stage() (loop: is stage-only in v1)');
        }

        $lastKey = array_key_last($this->stages);
        $last = $this->stages[$lastKey];
        if ($last['type'] !== 'stage') {
            throw new \InvalidArgumentException(
                "loop() is stage-only in v1; the last stage '" . $last['name'] . "' is a '" . $last['type'] . "' stage"
            );
        }

        if (!array_key_exists('max', $loop) || !array_key_exists('until', $loop)) {
            throw new \InvalidArgumentException('loop() needs both max and until');
        }

        $max = null;
        if (is_int($loop['max'])) {
            $max = $loop['max'];
        } elseif (is_string($loop['max']) && preg_match('/^-?\d+$/', trim($loop['max'])) === 1) {
            $max = (int) trim($loop['max']);
        }

        if ($max === null || $max < 1) {
            throw new \InvalidArgumentException('loop() needs "max" to be a whole number of at least 1');
        }

        $normalized = ['max' => $max, 'until' => StageCondition::parse($loop['until'])];
        $this->stages[$lastKey]['loop'] = $normalized;

        return $this;
    }

    /**
     * Configure whether a parallel stage stops immediately on the first agent failure.
     *
     * When true (fail-fast), all queued agents are cancelled as soon as any agent
     * fails. When false (wait-for-all), all agents complete before the stage is
     * marked failed.
     *
     * Implementation chain: WorkflowBuilder stores this in $stopOnFirstFailure,
     * which WorkflowEngine reads to call AgentWorkerPool::withStopOnFirstFailure(),
     * which sets an internal flag checked by executeAll() to cancel remaining agents.
     */
    public function stopOnFirstFailure(bool $stop): self
    {
        $this->stopOnFirstFailure = $stop;
        return $this;
    }

    /**
     * Assemble and return the immutable Workflow value object.
     */
    public function build(): Workflow
    {
        return new Workflow(
            name: $this->name,
            description: $this->description,
            stages: $this->stages,
            maxConcurrent: $this->maxConcurrent,
            timeout: $this->timeout,
            stopOnFirstFailure: $this->stopOnFirstFailure,
            maxAgents: $this->maxAgents,
        );
    }
}

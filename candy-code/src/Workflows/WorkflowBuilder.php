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
    /** The stage shapes a `loop:` may repeat (P1-5; P1-2 adds `map`). */
    public const LOOPABLE_TYPES = ['stage', 'parallel', 'verification', 'map'];

    /** P1-2: the stage shapes `minSuccess:` judges (agents of a parallel stage, items of a map). */
    public const TOLERANT_TYPES = ['parallel', 'map'];

    /** The closed `loop:` roster. */
    public const LOOP_KEYS = ['max', 'until', 'accumulate'];

    /** The closed `loop.accumulate` roster. */
    public const ACCUMULATE_KEYS = ['pointer', 'key'];

    private string $name = '';
    private string $description = '';
    /** @var array<int, array{name: string, type: string, tasks?: array<int, mixed>}> */
    private array $stages = [];
    private ?int $maxConcurrent = null;
    private int $maxAgents = Workflow::DEFAULT_MAX_AGENTS;
    private ?int $maxTokens = null;
    private ?float $maxCost = null;
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
     * @param array<string, mixed> $from C8/P1-1 fan-out spec, empty for the
     *        static list spelling. Exactly ONE source:
     *        - `glob` (C8): the engine expands it at run time against the
     *          environment root, one task per sorted match (`{{path}}`);
     *        - `stage` + `pointer` (P1-1): an RFC 6901 pointer into a PRIOR
     *          stage's typed row that must resolve to a JSON array at run
     *          time, one task per element;
     *        - `context` (P1-1): a run-context key whose value must be a
     *          list, one task per element.
     *        Plus optional `chunk` (in-flight ceiling, >= 1), `max` (keep the
     *        first N and record the rest as dropped, 1 to
     *        {@see Workflow::FAN_OUT_CEILING}) and — data sources only —
     *        `key` (a pointer into each item: dedups items by that leaf and
     *        names each item's task `<stage>_<key>`). The template in
     *        $tasks[0] is what gets fanned out; its prompt sees `{{item}}`,
     *        `{{item.<pointer>}}` and `{{index}}`.
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
     * (builder parity): exactly one source (`glob` a non-empty string;
     * `stage` + `pointer`; or `context`), `chunk` null or >= 1, `max`
     * absent/null or 1..{@see Workflow::FAN_OUT_CEILING}, `key` only beside a
     * data source, nothing else. `max` and `key` are stored only when set, so
     * a glob spec without them keeps the two-key shape the engine and the
     * pause record have always carried.
     *
     * @param array<array-key, mixed> $from
     * @return array<string, mixed>
     */
    private static function normalizedFrom(string $stage, array $from): array
    {
        foreach (array_keys($from) as $key) {
            if (!in_array($key, WorkflowRegistry::YAML_FROM_KEYS, true)) {
                throw new \InvalidArgumentException("Stage '{$stage}' from has unknown key \"{$key}\"; supported keys: " . implode(', ', WorkflowRegistry::YAML_FROM_KEYS));
            }
        }

        $chunk = $from['chunk'] ?? null;
        if ($chunk !== null && (!is_int($chunk) || $chunk < 1)) {
            throw new \InvalidArgumentException("Stage '{$stage}' from \"chunk\" must be a whole number of at least 1");
        }

        $max = $from['max'] ?? null;
        if ($max !== null && (!is_int($max) || $max < 1 || $max > Workflow::FAN_OUT_CEILING)) {
            throw new \InvalidArgumentException(
                "Stage '{$stage}' from \"max\" must be a whole number from 1 to " . Workflow::FAN_OUT_CEILING
            );
        }

        if (array_key_exists('glob', $from) && !array_key_exists('stage', $from) && !array_key_exists('context', $from)) {
            $glob = $from['glob'];
            if (!is_string($glob) || trim($glob) === '') {
                throw new \InvalidArgumentException("Stage '{$stage}' from needs a non-empty \"glob\"");
            }
        }

        try {
            $source = self::fromSource($from);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("Stage '{$stage}' {$e->getMessage()}", 0, $e);
        }

        return $source + ['chunk' => $chunk] + ($max !== null ? ['max' => $max] : []);
    }

    /**
     * P1-1: the SOURCE half of a `from:` spec — the one law the PHP builder
     * and the YAML loader share (the loader coerces `chunk`/`max` itself and
     * re-raises this method's refusals naming the file and stage). Exactly
     * one of `glob`, `stage` or `context`:
     *
     * - `glob`: a non-empty string (trimmed) → `['glob' => g]`; `pointer`
     *   and `key` are refused beside it (a path list is already unique).
     * - `stage` + `pointer`: a non-empty stage name and a well-formed RFC
     *   6901 pointer (`""` is the whole row) → `['stage' => s, 'pointer' => p]`.
     *   Whether `stage` names a PRIOR stage is judged by the loader, which
     *   alone sees the whole file.
     * - `context`: a non-empty key that does not start with `@` (those are
     *   the engine's own) → `['context' => c]`; `pointer` is refused beside it.
     *
     * An optional `key` (data sources only, well-formed pointer) rides as
     * `['key' => k]` after the source keys.
     *
     * @param array<array-key, mixed> $from
     * @return array<string, string> the source keys (plus `key` when written), in that order
     *
     * @throws \InvalidArgumentException `from …` refusals, without a stage prefix.
     */
    public static function fromSource(array $from): array
    {
        $sources = array_values(array_intersect(['glob', 'stage', 'context'], array_keys($from)));
        if (count($sources) !== 1) {
            throw new \InvalidArgumentException(
                $sources === []
                    ? 'from needs exactly one source: "glob" (files under the environment root), "stage" + "pointer" (an array in a prior stage\'s typed row) or "context" (a run-context list)'
                    : 'from carries ' . implode(' and ', array_map(static fn (string $k): string => "\"{$k}\"", $sources)) . '; a fan-out reads exactly one source'
            );
        }
        $kind = $sources[0];

        $string = static function (string $name) use ($from): string {
            $value = $from[$name];
            if (!is_string($value)) {
                throw new \InvalidArgumentException("from \"{$name}\" must be a string, got " . get_debug_type($value));
            }

            return $value;
        };
        $pointer = static function (string $name) use ($string): string {
            $value = $string($name);
            if (!JsonPointer::isWellFormed($value)) {
                throw new \InvalidArgumentException(
                    "from \"{$name}\" is not a valid RFC 6901 pointer: \"{$value}\" (\"~\" must be written ~0, \"/\" inside a key ~1)"
                );
            }

            return $value;
        };

        if ($kind === 'glob') {
            foreach (['pointer', 'key'] as $dataOnly) {
                if (array_key_exists($dataOnly, $from)) {
                    throw new \InvalidArgumentException("from \"{$dataOnly}\" belongs to a data source (\"stage\" or \"context\"), not to \"glob\"");
                }
            }
            $glob = trim($string('glob'));
            if ($glob === '') {
                throw new \InvalidArgumentException('from "glob" must not be empty');
            }

            return ['glob' => $glob];
        }

        if ($kind === 'stage') {
            $name = trim($string('stage'));
            if ($name === '') {
                throw new \InvalidArgumentException('from "stage" must name a prior stage');
            }
            if (!array_key_exists('pointer', $from)) {
                throw new \InvalidArgumentException('from "stage" needs a "pointer" (RFC 6901, e.g. /findings) to the array in that stage\'s typed row');
            }
            $spec = ['stage' => $name, 'pointer' => $pointer('pointer')];
        } else {
            if (array_key_exists('pointer', $from)) {
                throw new \InvalidArgumentException('from "pointer" reads a stage\'s typed row; beside "context" the value itself must be the list');
            }
            $name = trim($string('context'));
            if ($name === '' || str_starts_with($name, '@')) {
                throw new \InvalidArgumentException('from "context" must name a run-context key (not empty, not starting with "@"), got "' . $name . '"');
            }
            $spec = ['context' => $name];
        }

        if (array_key_exists('key', $from)) {
            $spec['key'] = $pointer('key');
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
     * P1-2: add a `type: map` stage — every item of the `from:` source flows
     * through `$steps` INDEPENDENTLY, with no barrier between steps (item A
     * can be in step 3 while item B is in step 1); up to the stage width of
     * item chains run at once. See {@see WorkflowEngine} `executeMapStage()`
     * for the full run law and the stage's JSON output.
     *
     * `$from` is the same spec {@see parallel()} takes — exactly one of
     * `glob`, `stage` + `pointer`, or `context`, plus optional `key`,
     * `chunk` and `max` — held to the same shape law.
     *
     * `$steps` is a non-empty list, each entry one of:
     * - a {@see TaskBuilder} — a task step, named by its task `name()`
     *   (required: it is the step's key in the item's `steps` row and its
     *   `{{<name>.results}}` handle inside the item);
     * - {@see mapStep()}`($task, $when)` — the same, with a per-item guard;
     * - {@see mapVerification()}`($name, $task, $verifier, $when)` — a
     *   verification sub-stage (its verifier may convene a vote panel).
     * Step names are unique within the map. A step's `when:` is a C2 clause
     * whose leaves may spell `step: <name>` (a synonym of `stage:`); one that
     * names this step or a LATER step is refused, since it has not run for
     * the item yet.
     *
     * @param array<string, mixed> $from
     * @param list<TaskBuilder|array<string, mixed>> $steps
     *
     * @throws \InvalidArgumentException naming the malformed spec or step.
     */
    public function map(string $name, array $from, array $steps, bool $capture = false, bool $cache = false): self
    {
        if ($from === []) {
            throw new \InvalidArgumentException("Map stage '{$name}' needs a from: source (glob, stage + pointer, or context)");
        }
        $from = self::normalizedFrom($name, $from);

        if ($steps === [] || !array_is_list($steps)) {
            throw new \InvalidArgumentException("Map stage '{$name}' needs a non-empty list of steps");
        }

        $built = [];
        $names = [];
        foreach ($steps as $i => $step) {
            $at = "Map stage '{$name}' step #" . ($i + 1);
            if ($step instanceof TaskBuilder) {
                $step = self::mapStep($step);
            }
            if (!is_array($step) || !isset($step['type'])) {
                throw new \InvalidArgumentException("{$at} must be a TaskBuilder, mapStep() or mapVerification(), got " . get_debug_type($step));
            }
            if ($step['type'] === 'stage') {
                $task = $step['task'] ?? null;
                if (!$task instanceof TaskBuilder) {
                    throw new \InvalidArgumentException("{$at} carries no task");
                }
                $workflowTask = $task->build();
                $stepName = $workflowTask->name ?? '';
                $node = ['name' => $stepName, 'type' => 'stage', 'tasks' => [$workflowTask]];
            } elseif ($step['type'] === 'verification') {
                $stepName = (string) ($step['name'] ?? '');
                $node = ['name' => $stepName, 'type' => 'verification', 'task' => $step['task']->build(), 'verifier' => $step['verifier']->build()];
            } else {
                throw new \InvalidArgumentException(
                    "{$at} is a '{$step['type']}' step; a map step is a task or a verification sub-stage (parallel, map, pipeline and uses steps are refused in v1)"
                );
            }
            if (trim($stepName) === '') {
                throw new \InvalidArgumentException("{$at} needs a name: it keys the step in each item's row and is read as {{<name>.results}} inside the item");
            }
            if (isset($names[$stepName])) {
                throw new \InvalidArgumentException("{$at} reuses the step name '{$stepName}'; step names must be unique within a map");
            }
            if (array_key_exists('when', $step) && $step['when'] !== null) {
                if (!is_array($step['when'])) {
                    throw new \InvalidArgumentException("{$at} \"when\" must be a condition map, got " . get_debug_type($step['when']));
                }
                $when = StageCondition::parse(self::stepClause($step['when'], $at));
                foreach (self::clauseStages($when) as $read) {
                    if (self::laterStepName($steps, $i, $read)) {
                        throw new \InvalidArgumentException(
                            "{$at} \"when\" reads step '{$read}', which has not run yet for the item; a step guard reads earlier steps only"
                        );
                    }
                }
                $node['when'] = $when;
            }
            $names[$stepName] = true;
            $built[] = $node;
        }

        $this->stages[] = [
            'name' => $name,
            'type' => 'map',
            'from' => $from,
            'steps' => $built,
        ]
            + ($capture === true ? ['capture' => true] : [])
            + ($cache === true ? ['cache' => true] : []);

        return $this;
    }

    /**
     * P1-2: a task step for {@see map()}, optionally guarded per item by a
     * `when:` clause over the item's earlier steps.
     *
     * @param array<string, mixed>|null $when
     * @return array<string, mixed>
     */
    public static function mapStep(TaskBuilder $task, ?array $when = null): array
    {
        return ['type' => 'stage', 'task' => $task] + ($when !== null ? ['when' => $when] : []);
    }

    /**
     * P1-2: a verification sub-stage step for {@see map()} — `$task` does
     * the item's work, `$verifier` judges it (votes/lenses/quorum allowed).
     *
     * @param array<string, mixed>|null $when
     * @return array<string, mixed>
     */
    public static function mapVerification(string $name, TaskBuilder $task, TaskBuilder $verifier, ?array $when = null): array
    {
        return ['type' => 'verification', 'name' => $name, 'task' => $task, 'verifier' => $verifier]
            + ($when !== null ? ['when' => $when] : []);
    }

    /**
     * P1-2: a map step's `when:` with every leaf's `step:` spelled `stage:`
     * (the condition grammar's own key); a leaf carrying both is refused.
     *
     * @param array<array-key, mixed> $clause
     * @return array<array-key, mixed>
     */
    public static function stepClause(array $clause, string $at = 'map step'): array
    {
        foreach (['all', 'any'] as $combinator) {
            if (isset($clause[$combinator]) && is_array($clause[$combinator])) {
                $clause[$combinator] = array_map(
                    static fn (mixed $child): mixed => is_array($child) ? self::stepClause($child, $at) : $child,
                    $clause[$combinator],
                );
            }
        }
        if (array_key_exists('step', $clause)) {
            if (array_key_exists('stage', $clause)) {
                throw new \InvalidArgumentException("{$at} \"when\" leaf carries both \"step\" and \"stage\"; spell one");
            }
            $rewritten = [];
            foreach ($clause as $key => $value) {
                $rewritten[$key === 'step' ? 'stage' : $key] = $value;
            }
            $clause = $rewritten;
        }

        return $clause;
    }

    /**
     * Every stage name a NORMALIZED clause reads.
     *
     * @param array<array-key, mixed> $node
     * @return list<string>
     */
    public static function clauseStages(array $node): array
    {
        if (is_array($node['conditions'] ?? null)) {
            $names = [];
            foreach ($node['conditions'] as $child) {
                if (is_array($child)) {
                    $names = [...$names, ...self::clauseStages($child)];
                }
            }

            return $names;
        }

        return is_string($node['stage'] ?? null) ? [$node['stage']] : [];
    }

    /**
     * Whether $read names a step declared at or after position $i of the
     * author's $steps list (TaskBuilder steps are named by their task).
     *
     * @param list<mixed> $steps
     */
    private static function laterStepName(array $steps, int $i, string $read): bool
    {
        foreach (array_slice($steps, $i) as $step) {
            $name = match (true) {
                $step instanceof TaskBuilder => $step->build()->name,
                is_array($step) && ($step['type'] ?? '') === 'verification' => $step['name'] ?? null,
                is_array($step) && ($step['task'] ?? null) instanceof TaskBuilder => $step['task']->build()->name,
                default => null,
            };
            if ($name === $read) {
                return true;
            }
        }

        return false;
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
     * P1-7 `config.maxTokens`: a HARD run-wide token ceiling, checked before
     * every dispatch (stage, task, fan-out item, vote, retry, loop round,
     * included workflow). A dispatch that would start at or past it does not
     * start; its stage fails "budget exhausted (spent X of Y tokens)" and the
     * run PAUSES, so a resume under a raised ceiling continues. Also feeds
     * `{{budget.remaining}}` and `until: {budgetBelow: N}`. Null clears it.
     *
     * @throws \InvalidArgumentException When $tokens is below 1.
     */
    public function maxTokens(?int $tokens): self
    {
        if ($tokens !== null && $tokens < 1) {
            throw new \InvalidArgumentException("maxTokens() needs a whole number of at least 1, got {$tokens}");
        }

        $this->maxTokens = $tokens;
        return $this;
    }

    /**
     * P1-7 `config.maxCost`: a HARD run-wide USD ceiling, with exactly the
     * {@see maxTokens()} semantics ("budget exhausted (spent X of Y USD)").
     * Null clears it.
     *
     * @throws \InvalidArgumentException When $usd is not a positive number.
     */
    public function maxCost(int|float|null $usd): self
    {
        if ($usd !== null && (!is_finite((float) $usd) || $usd <= 0)) {
            throw new \InvalidArgumentException('maxCost() needs a positive number of USD, got ' . var_export($usd, true));
        }

        $this->maxCost = $usd === null ? null : (float) $usd;
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
     * C4 + P1-5: attach a `loop:` round policy to the stage most recently
     * added by {@see stage()}, {@see parallel()} or {@see withVerification()}.
     * The map is closed — `max` (whole number ≥ 1), `until` (a C2 clause,
     * which here also admits the loop-only leaves `{dry: N}` and
     * `{budgetBelow: N}`) and the optional `accumulate` — normalized and
     * refused at call time, the same raw-English InvalidArgumentException
     * shape {@see when()} rides. A {@see pipeline()} stage and a {@see uses()}
     * stage refuse it, so a built workflow can never carry a round policy its
     * runner would not honour.
     *
     * `accumulate: {pointer, key?}` (P1-5) keeps a deduplicated item set
     * across rounds: each round's typed row(s) must hold a JSON array at
     * `pointer` (a parallel stage unions its agents' rows), `key` names the
     * dedup leaf of each item (absent: the item itself is its identity). The
     * engine exposes `{{seen}}`/`{{fresh}}` and the dry-round count `{dry: N}`
     * reads; `{dry: N}` without `accumulate` could never hold, so it is
     * refused.
     *
     * @param array<string, mixed> $loop the author's `loop:` map
     *
     * @throws \InvalidArgumentException when no stage exists to attach to, the
     *         last stage cannot loop, or the map is not the closed grammar.
     */
    public function loop(array $loop): self
    {
        if ($this->stages === []) {
            throw new \InvalidArgumentException('loop() needs a stage to attach to; call it after stage(), parallel() or withVerification()');
        }

        $lastKey = array_key_last($this->stages);
        $last = $this->stages[$lastKey];
        if (!in_array($last['type'], self::LOOPABLE_TYPES, true)) {
            throw new \InvalidArgumentException(
                "loop() cannot repeat the last stage '" . $last['name'] . "': a '" . $last['type'] . "' stage does not loop (stage, parallel, verification and map stages do)"
            );
        }
        if (array_key_exists('uses', $last)) {
            throw new \InvalidArgumentException(
                "loop() cannot repeat the last stage '" . $last['name'] . "': it carries another workflow (uses), which does not loop"
            );
        }

        $unknown = array_diff(array_keys($loop), self::LOOP_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "loop() has unknown key(s) '" . implode("', '", $unknown) . "'; the grammar is " . implode(', ', self::LOOP_KEYS)
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

        if (!is_array($loop['until'])) {
            throw new \InvalidArgumentException('loop() needs "until" to be a condition map, got ' . get_debug_type($loop['until']));
        }
        $until = StageCondition::parse($loop['until'], allowLoopLeaves: true);

        $normalized = ['max' => $max, 'until' => $until];
        if (array_key_exists('accumulate', $loop)) {
            $normalized['accumulate'] = self::parseAccumulate($loop['accumulate']);
        } elseif (self::clauseUsesOp($until, 'dry')) {
            throw new \InvalidArgumentException(
                'loop() "until" uses {dry: N}, which counts rounds that add nothing new to an accumulated set; add "accumulate: {pointer, key}" or drop the dry leaf'
            );
        }

        $this->stages[$lastKey]['loop'] = $normalized;

        return $this;
    }

    /**
     * P1-5: the ONE `loop.accumulate` parser (builder and YAML loader):
     * `{pointer: <RFC 6901>, key?: <RFC 6901>}` → the same shape, both
     * pointers checked well-formed (`""` is legal: the whole row / item).
     *
     * @return array{pointer: string, key?: string}
     *
     * @throws \InvalidArgumentException naming the offending key.
     */
    public static function parseAccumulate(mixed $accumulate): array
    {
        if (!is_array($accumulate) || array_is_list($accumulate) && $accumulate !== []) {
            throw new \InvalidArgumentException(
                'loop "accumulate" must be a map {pointer: /items, key: /id}, got ' . (is_array($accumulate) ? 'a list' : get_debug_type($accumulate))
            );
        }
        $unknown = array_diff(array_keys($accumulate), self::ACCUMULATE_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "loop \"accumulate\" has unknown key(s) '" . implode("', '", $unknown) . "'; the grammar is pointer and key"
            );
        }
        if (!array_key_exists('pointer', $accumulate)) {
            throw new \InvalidArgumentException('loop "accumulate" needs a "pointer" (RFC 6901) to the array each round adds');
        }

        $spec = [];
        foreach (self::ACCUMULATE_KEYS as $name) {
            if (!array_key_exists($name, $accumulate)) {
                continue;
            }
            $value = $accumulate[$name];
            if (!is_string($value)) {
                throw new \InvalidArgumentException("loop \"accumulate\" \"{$name}\" must be a string (an RFC 6901 pointer), got " . get_debug_type($value));
            }
            if (!JsonPointer::isWellFormed($value)) {
                throw new \InvalidArgumentException(
                    "loop \"accumulate\" \"{$name}\" is not a valid RFC 6901 pointer: \"{$value}\" (\"~\" must be written ~0, \"/\" inside a key ~1)"
                );
            }
            $spec[$name] = $value;
        }

        return $spec;
    }

    /**
     * Whether a NORMALIZED condition carries a leaf with $op anywhere in it.
     *
     * @param array<string, mixed> $node
     */
    public static function clauseUsesOp(array $node, string $op): bool
    {
        if (isset($node['conditions']) && is_array($node['conditions'])) {
            foreach ($node['conditions'] as $child) {
                if (is_array($child) && self::clauseUsesOp($child, $op)) {
                    return true;
                }
            }

            return false;
        }

        return ($node['op'] ?? null) === $op;
    }

    /**
     * P1-3: attach a `minSuccess:` partial-failure tolerance to the stage
     * most recently added by {@see parallel()}. The threshold is a whole
     * number of agents (>= 1) or a `"NN%"` string (1-100%) of the stage's
     * agents, rounded UP. Absent (the default) means all must succeed —
     * byte-identical to every pre-P1-3 stage. When met despite failures the
     * stage COMPLETES, its output ends with a `[k of n agents failed: name
     * (status), …]` casualty line, and `{{<stage>.failed}}` interpolates the
     * JSON list of failed result names. A minSuccess stage is exempt from
     * {@see stopOnFirstFailure()}: cancelling siblings on the first casualty
     * would defeat the tolerance the stage declares.
     *
     * Parallel and map stages only (P1-2: a map counts ITEMS): a plain, pipeline, verification or `uses:` stage
     * refuses it. A static agent list is checked here too — a count above
     * the list's length could never be met.
     *
     * @throws \InvalidArgumentException when no parallel stage was added
     *         last, or the threshold is not one of the two spellings.
     */
    public function minSuccess(int|string $threshold): self
    {
        if ($this->stages === []) {
            throw new \InvalidArgumentException('minSuccess() needs a stage to attach to; call it after parallel() or map()');
        }

        $lastKey = array_key_last($this->stages);
        $last = $this->stages[$lastKey];
        if (!in_array($last['type'], self::TOLERANT_TYPES, true)) {
            throw new \InvalidArgumentException(
                "minSuccess() judges parallel and map stages only; the last stage '" . $last['name'] . "' is a '" . $last['type'] . "' stage"
            );
        }

        $normalized = self::parseMinSuccess($threshold);
        $agents = count($last['tasks'] ?? []);
        if (!isset($last['from']) && isset($normalized['count']) && $normalized['count'] > $agents) {
            throw new \InvalidArgumentException(
                "minSuccess {$normalized['count']} exceeds the {$agents} agent" . ($agents === 1 ? '' : 's')
                . " stage '" . $last['name'] . "' declares; it could never be met"
            );
        }

        $this->stages[$lastKey]['minSuccess'] = $normalized;

        return $this;
    }

    /**
     * P2-2: attach a settle announcement to the last stage — a template
     * interpolated when the stage settles (its own `{{<stage>.output}}`,
     * pointers and `{{<stage>.failed}}` in scope) and emitted on the run's
     * announcement stream ({@see WorkflowResult::$announcements}, the
     * engine's sink). Any stage shape may carry one.
     *
     * @throws \InvalidArgumentException With no stage, or a blank template.
     */
    public function announce(string $template): self
    {
        if ($this->stages === []) {
            throw new \InvalidArgumentException('announce() needs a stage to attach to; call it after the stage it narrates');
        }
        if (trim($template) === '') {
            throw new \InvalidArgumentException('announce() needs a non-empty template');
        }

        $this->stages[array_key_last($this->stages)]['announce'] = $template;

        return $this;
    }

    /**
     * The ONE `minSuccess:` parser, shared by {@see minSuccess()} and the
     * YAML loader: an int (or quoted whole number) >= 1 normalizes to
     * `['count' => n]`, an `"NN%"` string with NN in 1..100 to
     * `['percent' => NN]`. Floats, fractions and anything else are refused.
     *
     * @return array{count: int}|array{percent: int}
     *
     * @throws \InvalidArgumentException naming the accepted spellings.
     */
    public static function parseMinSuccess(mixed $threshold): array
    {
        if (is_string($threshold)) {
            $text = trim($threshold);
            if (preg_match('/^(\d+)\s*%$/', $text, $m) === 1) {
                $percent = (int) $m[1];
                if ($percent < 1 || $percent > 100) {
                    throw new \InvalidArgumentException("minSuccess percentage must be from 1% to 100%, got {$text}");
                }

                return ['percent' => $percent];
            }
            if (preg_match('/^-?\d+$/', $text) === 1) {
                $threshold = (int) $text;
            }
        }

        if (!is_int($threshold)) {
            throw new \InvalidArgumentException(
                'minSuccess must be a whole number of agents or a "NN%" percentage, got '
                . (is_string($threshold) ? "\"{$threshold}\"" : get_debug_type($threshold))
            );
        }

        if ($threshold < 1) {
            throw new \InvalidArgumentException("minSuccess must be at least 1, got {$threshold}");
        }

        return ['count' => $threshold];
    }

    /**
     * How many of $agents must succeed under a normalized threshold: the
     * count as written, or the percentage of $agents rounded UP (never 0).
     *
     * @param array{count?: int, percent?: int} $minSuccess
     */
    public static function requiredSuccesses(array $minSuccess, int $agents): int
    {
        if (isset($minSuccess['percent'])) {
            return max(1, (int) ceil($agents * $minSuccess['percent'] / 100));
        }

        return (int) ($minSuccess['count'] ?? $agents);
    }

    /**
     * The author's spelling of a normalized threshold, for diagnostics.
     *
     * @param array{count?: int, percent?: int} $minSuccess
     */
    public static function minSuccessLabel(array $minSuccess): string
    {
        return isset($minSuccess['percent']) ? $minSuccess['percent'] . '%' : (string) ($minSuccess['count'] ?? '?');
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
            maxTokens: $this->maxTokens,
            maxCost: $this->maxCost,
        );
    }
}

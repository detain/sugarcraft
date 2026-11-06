<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Permissions\PermissionRule;

/**
 * Static analysis of a compiled workflow plan — `/workflow lint` (C5).
 *
 * A READ-ONLY consumer of the DTOs the registry's public load()/fromYamlString()
 * already produced: nothing here dispatches an agent, requires a PHP workflow
 * file, or mutates a plan. Each check MIRRORS a runtime behavior of
 * WorkflowEngine so a plan's silent future surfaces at lint time instead:
 *
 *  - interpolateContext() leaves an unmatched `{{token}}` VERBATIM in the
 *    prompt the agent receives (engine's three passes: `X.results` over the
 *    `@results` map, `X.output` over `<X>.output` context keys, bare `X` over
 *    `$context[X]`). A ref to a producer that runs LATER misses just as
 *    silently, so order is part of the model here.
 *  - the run-time tool pre-flight refuses the WHOLE run when a declaration
 *    names nothing the session's registry can match; a malformed pattern
 *    throws before stage 1. Same verdicts here, before anything is billed.
 *  - firstRejectMisuse() refuses a `reject` verdict rule outside a
 *    verification stage's verifier slot. The walk here is its mirror.
 *
 * YAML plans now reach the whole stage surface — per-task timeout, reject
 * and rejectMinOccurrences all parse into the DTO (C1), and the stage shapes
 * themselves come from {@see Workflow::STAGE_TYPES}, the one list the engine
 * walker and the YAML parser share — so no leg degrades to no-op for a
 * YAML-loaded workflow anymore.
 *
 * The report is structural diagnostics and stays literal English — the same
 * law the engine's refusals follow (a workflow author reads raw text in the
 * transcript regardless of UI locale).
 */
final class WorkflowLinter
{
    /**
     * C1 parity: the accepted stage shapes live on the Workflow VO — this is
     * an alias, not a copy, so the lint verdict can never disagree with the
     * engine's run-time refusal after a list edit on either side.
     *
     * @var list<string>
     */
    private const SUPPORTED_TYPES = Workflow::STAGE_TYPES;

    /** Above this per-task retry count a runaway retry loop is probably a typo. */
    private const RETRIES_WARN_ABOVE = 10;

    /**
     * @param list<array{severity: string, stage: string, message: string}> $findings
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> $planRows
     */
    private function __construct(
        public readonly string $workflowName,
        public readonly array $findings,
        private readonly array $planRows,
    ) {}

    /**
     * Lint a compiled workflow.
     *
     * @param Workflow            $workflow    DTO as the registry produced it.
     * @param array<string,mixed> $seedContext run-context keys the plan will be handed (bare `{{X}}` resolves from these; [] for a pure lint).
     * @param ?list<string>       $toolNames   the session's tool universe; null = universe unknown (file lint without a backend) => the declared-vs-known leg is SKIPPED, pattern validity is still checked.
     */
    public static function lint(Workflow $workflow, array $seedContext = [], ?array $toolNames = null): self
    {
        $findings = [];
        $planRows = [];

        self::checkWorkflowLevel($workflow, $findings);

        $producers = [];   // resultKey => 1-based stage ordinal (the @results fold's keys)
        $outputs   = [];   // stage/step name => 1-based stage ordinal (`.output` producers only)

        $ordinal = 0;
        foreach ($workflow->stages as $index => $stage) {
            ++$ordinal;
            $type = is_array($stage) ? ($stage['type'] ?? '') : '';
            $stageName = is_array($stage) && is_string($stage['name'] ?? null) && $stage['name'] !== '' ? $stage['name'] : '';
            if ($stageName === '') {
                $stageName = 'unknown';
                self::add($findings, 'warning', $stageName, 'Stage #' . ($index + 1) . ' has no name; its result falls back to "unknown" and collides with any other unnamed stage.');
            }
            if (!is_string($type) || !in_array($type, self::SUPPORTED_TYPES, true)) {
                self::add($findings, 'error', $stageName, 'Stage type ' . (is_string($type) ? "'{$type}'" : get_debug_type($type)) . ' not supported; the engine refuses the run before stage 1.');
                $planRows[] = [$stageName, is_string($type) && $type !== '' ? $type : '?', '—', '—', '—', '—'];
                continue;
            }

            self::collectProducers($type, $stageName, is_array($stage) ? $stage : [], $ordinal, $producers, $outputs, $findings);
            self::notePlanRow($type, $stageName, is_array($stage) ? $stage : [], $workflow->timeout, $planRows);
        }

        $ordinal = 0;
        foreach ($workflow->stages as $stage) {
            ++$ordinal;
            $type = is_array($stage) ? ($stage['type'] ?? '') : '';
            if (!is_string($type) || !in_array($type, self::SUPPORTED_TYPES, true)) {
                continue;
            }
            $stageName = is_array($stage) && is_string($stage['name'] ?? null) && $stage['name'] !== '' ? $stage['name'] : 'unknown';
            self::checkIsolationSanity($type, $stageName, is_array($stage) ? $stage : [], $findings);
            foreach (self::tasksOf($type, is_array($stage) ? $stage : []) as [$slot, $task]) {
                self::checkTask($stageName, $slot, $task, $ordinal, $producers, $outputs, $seedContext, $toolNames, $findings);
            }
        }

        return new self($workflow->name, $findings, $planRows);
    }

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }

    public function hasErrors(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding['severity'] === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * The CLI exit code a lint maps to (0 clean / 1 findings). Exit 2 — the
     * cannot-load case — is decided by the caller before any report exists;
     * the slash surface has no exit codes, so the command carries that verdict
     * in its error-flagged row instead (protocol seam, lane report).
     */
    public function exitStatus(): int
    {
        return $this->hasFindings() ? 1 : 0;
    }

    public function errorCount(): int
    {
        return count(array_filter($this->findings, static fn (array $f): bool => $f['severity'] === 'error'));
    }

    public function warningCount(): int
    {
        return count(array_filter($this->findings, static fn (array $f): bool => $f['severity'] === 'warning'));
    }

    /**
     * One line per finding, `- severity [stage] message`, errors first — a
     * stable order so a neutered lint leg reddens exactly its own pins.
     */
    public function renderFindings(): string
    {
        if ($this->findings === []) {
            return '';
        }

        $ordered = [
            ...array_values(array_filter($this->findings, static fn (array $f): bool => $f['severity'] === 'error')),
            ...array_values(array_filter($this->findings, static fn (array $f): bool => $f['severity'] === 'warning')),
        ];

        $lines = [];
        foreach ($ordered as $finding) {
            $lines[] = "- {$finding['severity']} [{$finding['stage']}] {$finding['message']}";
        }

        return implode("\n", $lines);
    }

    /**
     * The plan table: stage | type | agents | tools | model | timeout, in
     * declaration order; nested pipeline steps ride as `stage/step` rows.
     */
    public function renderPlanTable(): string
    {
        $rows = [['Stage', 'Type', 'Agents', 'Tools', 'Model', 'Timeout']];
        foreach ($this->planRows as $row) {
            $rows[] = $row;
        }

        return self::renderTable($rows);
    }

    /**
     * Verdict line closing a lint report.
     */
    public function verdictLine(): string
    {
        if (!$this->hasFindings()) {
            return 'clean — no findings';
        }

        return $this->errorCount() . ' error(s), ' . $this->warningCount() . ' warning(s)';
    }

    // ------------------------------------------------------- pause ledger (C14)

    /**
     * Render a paused run's stage ledger — the per-stage duration/spend/
     * attempts columns of `/workflow status` (C14). Pure presentation over the
     * pause file WorkflowEngine already serializes (stageName/status/agents
     * with tokensUsed/costUsd/attempts + timing); nothing here re-runs or
     * recomputes a billing number. Literal-English rows like the lint report.
     *
     * @param array<string,mixed> $decoded the decoded pause-file document
     */
    public static function renderStageLedger(array $decoded): string
    {
        $stages = $decoded['stageResults'] ?? null;
        if (!is_array($stages) || $stages === []) {
            return '';
        }

        $rows = [['Stage', 'Status', 'Duration', 'Spend', 'Tokens', 'Attempts']];
        $totalTokens = 0;
        $totalCost = 0.0;
        foreach ($stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $agents = is_array($stage['agents'] ?? null) ? $stage['agents'] : [];
            $tokens = 0;
            $cost = 0.0;
            $attempts = 0;
            $sawAttempts = false;
            foreach ($agents as $agent) {
                if (!is_array($agent)) {
                    continue;
                }
                $tokens += (int) ($agent['tokensUsed'] ?? 0);
                $cost += (float) ($agent['costUsd'] ?? 0.0);
                if (array_key_exists('attempts', $agent)) {
                    $attempts += (int) $agent['attempts'];
                    $sawAttempts = true;
                }
            }
            $totalTokens += $tokens;
            $totalCost += $cost;
            $rows[] = [
                (string) ($stage['stageName'] ?? 'unknown'),
                (string) ($stage['status'] ?? '?'),
                self::formatSpan($stage['startedAt'] ?? null, $stage['completedAt'] ?? null, $agents),
                '$' . number_format($cost, 4),
                (string) $tokens,
                $sawAttempts ? (string) $attempts : '—',
            ];
        }

        $documentTokens = (int) ($decoded['totalTokens'] ?? $totalTokens);
        $documentCost = (float) ($decoded['totalCost'] ?? $totalCost);
        $totals = "Totals: {$documentTokens} tokens, $" . number_format($documentCost, 4);
        $pausedAt = $decoded['pausedAt'] ?? null;
        if (is_string($pausedAt) && $pausedAt !== '') {
            $totals .= " (paused {$pausedAt})";
        }

        return self::renderTable($rows) . "\n" . $totals;
    }

    /**
     * Read the paused-run ledger for an identifier the way the engine's own
     * pauseFileFor lookup does, minus the in-process memo it cannot share:
     * exact `<id>.json` first, then a per-file match on the recorded
     * `workflowId` (run ids are re-minted per process, so a file whose NAME
     * is a stale id but whose CONTENT records the asked-for id must still be
     * found). Returns null when no readable file names that run — a live
     * run's per-stage detail lives in the engine's private result memo, not
     * on the file plane, and the command prints its plain status line instead
     * (reported seam).
     *
     * @return ?array<string,mixed> the decoded document
     */
    public static function readPauseLedger(string $workflowsPath, string $identifier): ?array
    {
        if ($identifier === '' || str_contains($identifier, '/') || str_contains($identifier, '\\') || str_contains($identifier, '..') || str_contains($identifier, "\0")) {
            return null;
        }

        $directory = rtrim($workflowsPath, '/') . '/.running';
        if (!is_dir($directory)) {
            return null;
        }

        $direct = $directory . '/' . $identifier . '.json';
        $decodedDirect = self::decodeLedger($direct);
        if ($decodedDirect !== null && ($decodedDirect['workflowId'] ?? null) === $identifier) {
            return $decodedDirect;
        }

        $files = glob($directory . '/*.json');
        if ($files === false) {
            return null;
        }
        sort($files);
        foreach ($files as $file) {
            if ($file === $direct) {
                continue;
            }
            $decoded = self::decodeLedger($file);
            if ($decoded !== null && ($decoded['workflowId'] ?? null) === $identifier) {
                return $decoded;
            }
        }

        // A file named after the id that records a DIFFERENT id is still the
        // closest thing the user asked for ONLY when nothing matched; the
        // engine itself would not read it for this id, so neither do we —
        // return the direct hit solely on its own content match above.
        return $decodedDirect !== null && ($decodedDirect['workflowId'] ?? null) === null
            ? $decodedDirect
            : null;
    }

    /**
     * @return ?array<string,mixed>
     */
    private static function decodeLedger(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $contents = @file_get_contents($file);
        if (!is_string($contents)) {
            return null;
        }
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Duration for a stage row: its own startedAt/completedAt span when both
     * are present, else the widest agent span; unknown renders as an em dash
     * rather than as a zero the file never claimed.
     *
     * @param list<mixed> $agents
     */
    private static function formatSpan(mixed $startedAt, mixed $completedAt, array $agents): string
    {
        if (is_string($startedAt) && is_string($completedAt)) {
            $start = strtotime($startedAt);
            $end = strtotime($completedAt);
            if ($start !== false && $end !== false && $end >= $start) {
                return self::humanSpan($end - $start);
            }
        }

        $widest = 0;
        $sawSpan = false;
        foreach ($agents as $agent) {
            if (!is_array($agent)) {
                continue;
            }
            $start = is_string($agent['startedAt'] ?? null) ? strtotime($agent['startedAt']) : false;
            $end = is_string($agent['completedAt'] ?? null) ? strtotime($agent['completedAt']) : false;
            if ($start !== false && $end !== false && $end >= $start) {
                $widest = max($widest, $end - $start);
                $sawSpan = true;
            }
        }

        return $sawSpan ? self::humanSpan($widest) : '—';
    }

    private static function humanSpan(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
        }

        return intdiv($seconds, 3600) . 'h' . str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
    }

    // -------------------------------------------------------------- internals

    /**
     * @param array<array-key, array<array-key, mixed|string|int>> $rows cell grid
     */
    private static function renderTable(array $rows): string
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach (array_values($row) as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, strlen((string) $cell));
            }
        }

        $lines = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach (array_values($row) as $column => $cell) {
                $cells[] = str_pad((string) $cell, $widths[$column] ?? 0);
            }
            $lines[] = rtrim(implode(' | ', $cells));
            if ($rowIndex === 0) {
                $rules = [];
                foreach ($widths as $width) {
                    $rules[] = str_repeat('-', $width);
                }
                $lines[] = implode('-+-', $rules);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{severity: string, stage: string, message: string}> $findings
     */
    private static function add(array &$findings, string $severity, string $stage, string $message): void
    {
        $findings[] = ['severity' => $severity, 'stage' => $stage, 'message' => $message];
    }

    /**
     * @param array<array-key, mixed> $stage
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> $planRows
     */
    private static function notePlanRow(string $type, string $stageName, array $stage, int $workflowTimeout, array &$planRows): void
    {
        if ($type === 'pipeline') {
            foreach (is_array($stage['stages'] ?? null) ? $stage['stages'] : [] as $stepIndex => $step) {
                $stepName = is_array($step) && is_string($step['name'] ?? null) && $step['name'] !== ''
                    ? $step['name']
                    : $stageName . '_' . ($stepIndex + 1);
                $tasks = self::taskList(is_array($step) ? $step : []);
                $planRows[] = self::planRowCells("{$stageName}/{$stepName}", 'pipeline-step', $tasks, $workflowTimeout);
            }

            return;
        }

        if ($type === 'verification') {
            $tasks = array_values(array_filter(
                [$stage['task'] ?? null, $stage['verifier'] ?? null],
                static fn (mixed $t): bool => $t instanceof WorkflowTask,
            ));
            $planRows[] = self::planRowCells($stageName, 'verification', $tasks, $workflowTimeout);

            return;
        }

        $planRows[] = self::planRowCells($stageName, $type, self::taskList($stage), $workflowTimeout);
    }

    /**
     * @param array<array-key, mixed> $holder
     *
     * @return list<WorkflowTask>
     */
    private static function taskList(array $holder): array
    {
        $tasks = [];
        foreach (is_array($holder['tasks'] ?? null) ? $holder['tasks'] : [] as $task) {
            if ($task instanceof WorkflowTask) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * @param list<WorkflowTask> $tasks
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    private static function planRowCells(string $stage, string $type, array $tasks, int $workflowTimeout): array
    {
        $agents = [];
        $tools = 0;
        $model = '';
        $timeout = null;
        foreach ($tasks as $task) {
            $agentType = $task->agentType !== '' ? $task->agentType : '?';
            $agents[] = $task->name === null || $task->name === '' ? $agentType : "{$agentType}:{$task->name}";
            $tools += count($task->tools);
            if ($task->model !== null && $task->model !== '') {
                $model = $task->model;
            }
            if ($task->timeout !== null) {
                $timeout = $task->timeout;
            }
        }

        return [$stage, $type, implode(', ', $agents), (string) $tools, $model, (string) ($timeout ?? $workflowTimeout)];
    }

    private static function checkWorkflowLevel(Workflow $workflow, array &$findings): void
    {
        if ($workflow->timeout <= 0) {
            self::add($findings, 'error', 'workflow', "Workflow timeout {$workflow->timeout} is not positive; the engine arms the budget as-is.");
        }
        if ($workflow->maxConcurrent !== null && $workflow->maxConcurrent <= 0) {
            self::add($findings, 'error', 'workflow', "maxConcurrent {$workflow->maxConcurrent} is not positive; null is the documented uncapped value.");
        }
        if ($workflow->stages === []) {
            self::add($findings, 'warning', 'workflow', 'The plan has no stages; the run finishes without dispatching anything.');
        }
    }

    /**
     * Stage-order producer pass: mirrors the engine's recordContextResult /
     * withResults fold (the `@results` map keyed by resultKey, later writes
     * silently overwriting earlier) and the two `.output` writer sites
     * (sequential stage rows; pipeline step rows).
     *
     * @param array<string, int> $producers
     * @param array<string, int> $outputs
     */
    private static function collectProducers(string $type, string $stageName, array $stage, int $ordinal, array &$producers, array &$outputs, array &$findings): void
    {
        if ($type === 'pipeline') {
            foreach (is_array($stage['stages'] ?? null) ? $stage['stages'] : [] as $stepIndex => $step) {
                $stepName = is_array($step) && is_string($step['name'] ?? null) && $step['name'] !== ''
                    ? $step['name']
                    : $stageName . '_' . ($stepIndex + 1);
                self::noteProducer($outputs, $stepName, $ordinal, "pipeline step {$stageName}/{$stepName}", $findings);
                $tasks = self::taskList(is_array($step) ? $step : []);
                $multi = count($tasks) > 1;
                foreach ($tasks as $taskIndex => $task) {
                    $fallback = $multi ? $stepName . '_' . ($taskIndex + 1) : $stepName;
                    self::noteTaskKey($producers, $task, $fallback, $ordinal, "{$stageName}/{$stepName}", $findings);
                }
            }

            return;
        }

        if ($type === 'verification') {
            $task = $stage['task'] ?? null;
            $verifier = $stage['verifier'] ?? null;
            self::noteTaskKey($producers, $task instanceof WorkflowTask ? $task : null, $stageName, $ordinal, $stageName, $findings);
            self::noteTaskKey($producers, $verifier instanceof WorkflowTask ? $verifier : null, $stageName . '_verifier', $ordinal, $stageName, $findings);

            return;
        }

        // 'stage' and 'parallel' share the tasks[] shape: a single-task stage
        // records under the task name (or the stage name), a multi-task stage
        // labels each agent `<stage>_<n>`, 1-based declaration order.
        $tasks = self::taskList($stage);
        $multi = count($tasks) > 1;
        if ($type === 'stage') {
            self::noteProducer($outputs, $stageName, $ordinal, $stageName, $findings);
        }
        foreach ($tasks as $taskIndex => $task) {
            $fallback = $multi ? $stageName . '_' . ($taskIndex + 1) : $stageName;
            self::noteTaskKey($producers, $task, $fallback, $ordinal, $stageName, $findings);
        }
    }

    /**
     * @param array<string, int>           $producers
     */
    private static function noteTaskKey(array &$producers, ?WorkflowTask $task, string $fallback, int $ordinal, string $label, array &$findings): void
    {
        if ($task === null) {
            return;
        }
        $name = $task->name !== null && $task->name !== '' ? $task->name : null;
        // The engine records ONE key per task slot: the task name when set,
        // the positional fallback otherwise — never both.
        self::noteProducer($producers, $name ?? $fallback, $ordinal, $label, $findings);
    }

    /**
     * @param array<string, int> $map
     */
    private static function noteProducer(array &$map, string $key, int $ordinal, string $label, array &$findings): void
    {
        if (isset($map[$key])) {
            self::add($findings, 'error', $label, "Duplicate result key \"{$key}\": two producers write the same context slot, and the engine's fold lets the later one silently overwrite the earlier.");

            return;
        }
        $map[$key] = $ordinal;
    }

    /**
     * The tasks a stage dispatches, as [slot label, raw task array] pairs —
     * mirroring the engine walker's slots per type.
     *
     * @param array<array-key, mixed> $stage
     * @return list<array{0: string, 1: array<array-key, mixed>}>
     */
    private static function tasksOf(string $type, array $stage): array
    {
        if ($type === 'pipeline') {
            $pairs = [];
            foreach (is_array($stage['stages'] ?? null) ? $stage['stages'] : [] as $stepIndex => $step) {
                $stepName = is_array($step) && is_string($step['name'] ?? null) && $step['name'] !== ''
                    ? $step['name']
                    : (string) ($stage['name'] ?? 'unknown') . '_' . ($stepIndex + 1);
                foreach (self::taskList(is_array($step) ? $step : []) as $task) {
                    $pairs[] = [$stepName, $task];
                }
            }

            return $pairs;
        }

        if ($type === 'verification') {
            $pairs = [];
            foreach (['task', 'verifier'] as $slot) {
                if (($stage[$slot] ?? null) instanceof WorkflowTask) {
                    $pairs[] = [$slot, $stage[$slot]];
                }
            }

            return $pairs;
        }

        $pairs = [];
        foreach (self::taskList($stage) as $task) {
            $pairs[] = [(string) ($stage['name'] ?? 'unknown'), $task];
        }

        return $pairs;
    }

    /**
     * Parallel-shape sanity: two workers without worktree isolation write the
     * same checkout at the same time — not a refusal, a footgun worth one
     * line of warning.
     *
     * @param array<array-key, mixed> $stage
     */
    private static function checkIsolationSanity(string $type, string $stageName, array $stage, array &$findings): void
    {
        if ($type !== 'parallel') {
            return;
        }
        $tasks = is_array($stage['tasks'] ?? null) ? $stage['tasks'] : [];
        if (count($tasks) < 2) {
            return;
        }
        foreach ($tasks as $task) {
            if (!$task instanceof WorkflowTask) {
                continue;
            }
            if ($task->isolation === Isolation::Worktree) {
                return;
            }
        }
        self::add($findings, 'warning', $stageName, 'Every agent in this parallel stage writes the shared checkout (no worktree isolation); concurrent file edits can clobber each other.');
    }

    /**
     * @param array<string, int>   $producers
     * @param array<string, int>   $outputs
     * @param array<string, mixed> $seedContext
     * @param ?list<string>        $toolNames
     */
    private static function checkTask(string $stageName, string $slot, WorkflowTask $task, int $ordinal, array $producers, array $outputs, array $seedContext, ?array $toolNames, array &$findings): void
    {
        $taskName = $task->name ?? '';
        $label = $slot === $stageName ? $stageName : $stageName . '/' . $slot;
        $where = $taskName !== '' ? "{$label}:\"{$taskName}\"" : $label;

        if ($task->agentType === '') {
            self::add($findings, 'error', $where, 'The agent type is missing; the DTO requires a non-empty string.');
        }
        if ($task->model !== null && $task->model === '') {
            self::add($findings, 'error', $where, 'The model ask is present but not a non-empty string.');
        }

        $prompt = $task->prompt;
        if ($prompt === '') {
            self::add($findings, 'warning', $where, 'Empty prompt: the agent is dispatched with nothing to do.');
        }

        if ($task->timeout !== null && $task->timeout <= 0) {
            self::add($findings, 'error', $where, 'Task timeout ' . var_export($task->timeout, true) . ' is not a positive integer.');
        }
        $retries = $task->retries;
        if ($retries !== null) {
            if ($retries < 0) {
                self::add($findings, 'error', $where, 'Retries ' . var_export($retries, true) . ' is negative or not an integer; the pool takes maxRetries as-is.');
            } elseif ($retries > self::RETRIES_WARN_ABOVE) {
                self::add($findings, 'warning', $where, "Retries {$retries} is unusually high; every retry re-dispatches and re-bills the attempt.");
            }
        }

        $reject = $task->reject;
        if ($reject !== null) {
            if ($slot !== 'verifier') {
                self::add($findings, 'error', $where, 'reject() rejects a verifier VERDICT and belongs on the verifier slot of a verification stage — the engine refuses the run on this plan.');
            }
            if (@preg_match($reject, '') === false) {
                self::add($findings, 'error', $where, 'The reject pattern does not compile as PCRE — the engine fails the scan CLOSED and the verdict can never read clean.');
            }
            if ($task->rejectMinOccurrences < 1) {
                self::add($findings, 'warning', $where, "rejectMinOccurrences {$task->rejectMinOccurrences} can never be satisfied as written; the scan fails the rule CLOSED, so the verdict is always rejected.");
            }
        }

        foreach ($task->tools as $declaration) {
            if (!is_string($declaration) || $declaration === '') {
                self::add($findings, 'error', $where, 'A tool declaration is empty or not a string.');
                continue;
            }
            $reason = PermissionRule::patternRejectionReason($declaration);
            if ($reason !== null) {
                self::add($findings, 'error', $where, "A workflow task declares the tool pattern \"{$declaration}\", which {$reason} — the run pre-flight throws before stage 1.");
                continue;
            }
            if ($toolNames === null) {
                continue;
            }
            $namePattern = self::declaredToolName($declaration);
            $matched = false;
            foreach ($toolNames as $candidate) {
                if (PermissionRule::matchesToolName($namePattern, $candidate)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                self::add($findings, 'error', $where, "A workflow task declares tool \"{$declaration}\", which the session's tool registry does not contain — the run pre-flight refuses the whole run.");
            }
        }

        if ($prompt !== '') {
            self::checkInterpolation($prompt, $where, $ordinal, $producers, $outputs, $seedContext, $findings);
        }
    }

    /**
     * Mirrors interpolateContext()'s three passes, one token at a time:
     * dotted refs are definite (only the results fold and the `.output`
     * writers can satisfy them), bare refs may still arrive as run context
     * and so warn rather than error.
     *
     * @param array<string, int>   $producers
     * @param array<string, int>   $outputs
     * @param array<string, mixed> $seedContext
     */
    private static function checkInterpolation(string $prompt, string $where, int $ordinal, array $producers, array $outputs, array $seedContext, array &$findings): void
    {
        $tokens = [];
        if (preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)(\.results|\.output)?\}\}/', $prompt, $tokens, PREG_SET_ORDER) === false) {
            return;
        }
        foreach ($tokens as $token) {
            $name = $token[1];
            $suffix = $token[2] ?? '';
            if ($suffix === '.results') {
                if (isset($producers[$name])) {
                    if ($producers[$name] > $ordinal) {
                        self::add($findings, 'error', $where, "{{{$name}.results}} reads a result produced at stage #{$producers[$name]} — later than this task's stage #{$ordinal}, so the token stays verbatim in the dispatched prompt (a silent miss at run time).");
                    }

                    continue;
                }
                self::add($findings, 'error', $where, "{{{$name}.results}} names no producer in this plan: the token survives interpolation and the agent sees the raw braces.");

                continue;
            }
            if ($suffix === '.output') {
                if (isset($outputs[$name])) {
                    if ($outputs[$name] > $ordinal) {
                        self::add($findings, 'error', $where, "{{{$name}.output}} reads the output written at stage #{$outputs[$name]} — later than this task's stage #{$ordinal}; only sequential stages and pipeline steps write `.output`, and the miss is silent at run time.");
                    }

                    continue;
                }
                self::add($findings, 'error', $where, "{{{$name}.output}} names no sequential stage or pipeline step: parallel and verification stages never write `.output`, so the token survives interpolation.");

                continue;
            }
            if (array_key_exists($name, $seedContext) || isset($producers[$name])) {
                continue;
            }
            self::add($findings, 'warning', $where, "{{{$name}}} matches no run-context seed and no producer name; it interpolates only if the run is handed {$name}=... — otherwise the token stays verbatim, silently.");
        }
    }

    /**
     * Mirrors WorkflowEngine's name-half extraction: a declaration's pattern
     * is everything before the first '(' (PermissionRule::matchesToolName
     * takes the bare name pattern).
     */
    private static function declaredToolName(string $declaration): string
    {
        $position = strpos($declaration, '(');

        return $position === false ? $declaration : substr($declaration, 0, $position);
    }
}

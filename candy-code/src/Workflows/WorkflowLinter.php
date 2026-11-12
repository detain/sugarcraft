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
     * @param ?callable(string):(?Workflow) $resolver
     *        C11: loads a `uses:` name to its workflow so the include edges can
     *        be walked before the run is billed; null (the default) skips the
     *        walk entirely — a caller with no registry view keeps byte-identical
     *        pre-C11 verdicts. An edge whose name the resolver cannot load is
     *        left to load()'s own refusal, not guessed at here.
     * @param ?list<string> $presetNames P1-6: the agent-preset roster the run
     *        will resolve `preset:` against; null = roster unknown => only the
     *        spelling is checked (the engine still refuses an unknown preset
     *        before dispatch).
     */
    public static function lint(Workflow $workflow, array $seedContext = [], ?array $toolNames = null, ?callable $resolver = null, ?array $presetNames = null): self
    {
        $findings = [];
        $planRows = [];

        self::checkWorkflowLevel($workflow, $findings);

        $producers = [];   // resultKey => 1-based stage ordinal (the @results fold's keys)
        $failedProducers = [];   // P1-3: minSuccess stage name => 1-based stage ordinal (`{{X.failed}}` writers)
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

            // Past the type guard, $stage is necessarily an array: a non-array
            // yields '' here, which SUPPORTED_TYPES rejects with a continue.
            self::collectProducers($type, $stageName, $stage, $ordinal, $producers, $outputs, $findings);
            if (self::checkMinSuccess($type, $stageName, $stage, $workflow, $findings)) {
                $failedProducers[$stageName] = $ordinal;
            }
            self::notePlanRow($type, $stageName, $stage, $workflow->timeout, $planRows);
        }

        $ordinal = 0;
        foreach ($workflow->stages as $stage) {
            ++$ordinal;
            $type = is_array($stage) ? ($stage['type'] ?? '') : '';
            if (!is_string($type) || !in_array($type, self::SUPPORTED_TYPES, true)) {
                continue;
            }
            $stageName = is_array($stage) && is_string($stage['name'] ?? null) && $stage['name'] !== '' ? $stage['name'] : 'unknown';
            self::checkIsolationSanity($type, $stageName, $stage, $findings);
            self::checkFanOutShape($type, $stageName, $stage, $findings);
            $stageTokens = self::stageEngineTokens($stage);
            self::checkLoop($type, $stageName, $stage, $findings);
            self::checkDataFanOut($stageName, $stage, $ordinal, $outputs, $findings);
            if ($type === 'map') {
                self::checkMapSteps($stageName, $stage, $ordinal, $producers, $outputs, $failedProducers, $seedContext + $stageTokens, $toolNames, $findings);

                continue;
            }
            foreach (self::tasksOf($type, $stage) as $position => [$slot, $task]) {
                $engineTokens = $stageTokens + self::taskEngineTokens($type, $stage, $slot, $task, $position);
                self::checkTask($stageName, $slot, $task, $ordinal, $producers, $outputs, $seedContext + $engineTokens, $toolNames, $findings);
                self::checkFailedRefs($stageName, $task, $ordinal, $failedProducers, $producers, $findings);
            }
        }

        // C11: include-compose edges — cycles and too-deep chains are errors
        // here, before the run that would refuse them burns a single agent.
        self::checkIncludes($workflow, $findings, $resolver);
        self::checkPresets($workflow, $presetNames, $findings);
        self::checkBudgetAndAnnounce($workflow, $findings);

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
    /**
     * P1-7 / P2-2 mirrors: a blank or non-string `announce:` (hand-built) is
     * an error; `{{budget.remaining}}` and a loop's `until: {budgetBelow: N}`
     * on a plan with no `config.maxTokens` mean something only when the run
     * is handed a token budget — otherwise the token stays verbatim and the
     * loop runs to `max` — so each warns once.
     *
     * @param list<array{severity: string, stage: string, message: string}> $findings
     */
    private static function checkBudgetAndAnnounce(Workflow $workflow, array &$findings): void
    {
        $noCeiling = $workflow->maxTokens === null;
        $budgetToken = false;
        $scan = static function (mixed $node) use (&$scan, &$budgetToken): void {
            if ($node instanceof WorkflowTask) {
                $budgetToken = $budgetToken || str_contains($node->prompt, '{{budget.remaining}}');

                return;
            }
            if (is_array($node)) {
                foreach ($node as $child) {
                    $scan($child);
                }
            }
        };

        foreach ($workflow->stages as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $stageName = is_string($stage['name'] ?? null) && $stage['name'] !== '' ? $stage['name'] : 'unknown';
            if (array_key_exists('announce', $stage)) {
                if (!is_string($stage['announce']) || trim($stage['announce']) === '') {
                    self::add($findings, 'error', $stageName, 'announce must be a non-empty template string; the engine would announce nothing.');
                } else {
                    $budgetToken = $budgetToken || str_contains($stage['announce'], '{{budget.remaining}}');
                }
            }
            $scan($stage);
            $until = is_array($stage['loop']['until'] ?? null) ? $stage['loop']['until'] : null;
            if ($noCeiling && $until !== null && self::hasLeaf($until, 'budgetBelow')) {
                self::add($findings, 'warning', $stageName, 'until {budgetBelow: N} reads the tokens left under the run\'s token ceiling, and this plan sets no config.maxTokens; unless the run is handed a token budget the clause never holds and the loop runs to max.');
            }
        }

        if ($noCeiling && $budgetToken) {
            self::add($findings, 'warning', 'workflow', '{{budget.remaining}} resolves only when the run has a token ceiling (config.maxTokens or a run budget); without one it stays verbatim in the prompt.');
        }
    }

    /**
     * Whether a normalized condition clause carries a leaf with op $op.
     *
     * @param array<array-key, mixed> $node
     */
    private static function hasLeaf(array $node, string $op): bool
    {
        if (is_array($node['conditions'] ?? null)) {
            foreach ($node['conditions'] as $child) {
                if (is_array($child) && self::hasLeaf($child, $op)) {
                    return true;
                }
            }

            return false;
        }

        return ($node['op'] ?? null) === $op;
    }

    /**
     * P1-6 mirror of the engine's preset pre-flight: a blank preset name
     * (hand-built) is an error, and so is a name the given roster does not
     * hold — the engine refuses that run before dispatching anything.
     *
     * @param ?list<string> $presetNames
     * @param list<array{severity: string, stage: string, message: string}> $findings
     */
    private static function checkPresets(Workflow $workflow, ?array $presetNames, array &$findings): void
    {
        $walk = static function (mixed $node, string $stageName) use (&$walk, $presetNames, &$findings): void {
            if ($node instanceof WorkflowTask) {
                if ($node->preset === null) {
                    return;
                }
                $where = $node->name !== null && $node->name !== '' ? "{$stageName}:\"{$node->name}\"" : $stageName;
                if (trim($node->preset) === '') {
                    self::add($findings, 'error', $where, 'preset is blank; name an agent preset or drop the key.');
                } elseif ($presetNames !== null && !in_array($node->preset, $presetNames, true)) {
                    $known = $presetNames;
                    sort($known);
                    self::add($findings, 'error', $where, "preset \"{$node->preset}\" is not an agent preset this session knows (known: " . ($known === [] ? 'none' : implode(', ', $known)) . '); the engine refuses the run before dispatching anything.');
                }

                return;
            }
            if (is_array($node)) {
                foreach ($node as $child) {
                    $walk($child, $stageName);
                }
            }
        };

        foreach ($workflow->stages as $stage) {
            if (is_array($stage)) {
                $walk($stage, is_string($stage['name'] ?? null) && $stage['name'] !== '' ? $stage['name'] : 'unknown');
            }
        }
    }

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

        if ($type === 'map') {
            foreach (self::mapSteps($stage) as [$stepName, $pairs]) {
                $planRows[] = self::planRowCells("{$stageName}/{$stepName}", 'map-step', array_column($pairs, 1), $workflowTimeout);
            }

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
        // P0-3: null is UNSET (the CPU-derived default width), 0 the
        // explicit "no cap" — legal, but every agent of a fan-out then forks
        // at once, so it is worth one line. A negative names no width.
        if ($workflow->maxConcurrent !== null && $workflow->maxConcurrent < 0) {
            self::add($findings, 'error', 'workflow', "maxConcurrent {$workflow->maxConcurrent} is negative; 0 is the explicit no-cap value and leaving it unset runs the default width.");
        } elseif ($workflow->maxConcurrent === 0) {
            self::add($findings, 'warning', 'workflow', 'maxConcurrent 0 means no cap: every agent of a parallel stage is dispatched at once. Leave it unset for the default width of min(16, CPUs - 2), or set a number.');
        }
        if ($workflow->maxAgents < 1) {
            self::add($findings, 'error', 'workflow', "maxAgents {$workflow->maxAgents} is not positive; the run could dispatch nothing.");
        }
        // P1-7 mirrors (hand-built DTOs; the loader and builder refuse these).
        if ($workflow->maxTokens !== null && $workflow->maxTokens < 1) {
            self::add($findings, 'error', 'workflow', "maxTokens {$workflow->maxTokens} is not positive; the first dispatch would already be past the ceiling.");
        }
        if ($workflow->maxCost !== null && !($workflow->maxCost > 0)) {
            self::add($findings, 'error', 'workflow', 'maxCost ' . var_export($workflow->maxCost, true) . ' is not a positive number of USD; the first dispatch would already be past the ceiling.');
        }
        if ($workflow->stages === []) {
            self::add($findings, 'warning', 'workflow', 'The plan has no stages; the run finishes without dispatching anything.');
        }
    }

    /**
     * Stage-order producer pass: mirrors the engine's recordContextResult /
     * withResults fold (the `@results` map keyed by resultKey, later writes
     * silently overwriting earlier) and the `.output` writers: the engine
     * writes `<stage>.output` once EVERY stage settles — sequential,
     * parallel (agent outputs joined), verification, pipeline and `uses:`
     * alike — and a pipeline also writes each step's row.
     *
     * @param array<string, int> $producers
     * @param array<string, int> $outputs
     */
    private static function collectProducers(string $type, string $stageName, array $stage, int $ordinal, array &$producers, array &$outputs, array &$findings): void
    {
        // Stage names are unique (the loader refuses twins), so this slot is
        // only ever contested by a pipeline step of the same name — which
        // the engine writes too; neither is a collision worth an error.
        $outputs[$stageName] ??= $ordinal;

        if ($type === 'pipeline') {
            foreach (is_array($stage['stages'] ?? null) ? $stage['stages'] : [] as $stepIndex => $step) {
                $stepName = is_array($step) && is_string($step['name'] ?? null) && $step['name'] !== ''
                    ? $step['name']
                    : $stageName . '_' . ($stepIndex + 1);
                if ($stepName !== $stageName) {
                    self::noteProducer($outputs, $stepName, $ordinal, "pipeline step {$stageName}/{$stepName}", $findings);
                }
                $tasks = self::taskList(is_array($step) ? $step : []);
                $multi = count($tasks) > 1;
                foreach ($tasks as $taskIndex => $task) {
                    $fallback = $multi ? $stepName . '_' . ($taskIndex + 1) : $stepName;
                    self::noteTaskKey($producers, $task, $fallback, $ordinal, "{$stageName}/{$stepName}", $findings);
                }
            }

            return;
        }

        // P1-2: a map stage records ONE result — its JSON document — under
        // the stage name; its steps' results are item-scoped.
        if ($type === 'map') {
            self::noteProducer($producers, $stageName, $ordinal, $stageName, $findings);

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
     * The tasks a stage dispatches, as [slot label, task] pairs — mirroring
     * the engine walker's slots per type. Every arm yields WorkflowTask in
     * offset 1: taskList() filters for it, and the verification slot is
     * instanceof-guarded before being pushed.
     *
     * @param array<array-key, mixed> $stage
     * @return list<array{0: string, 1: WorkflowTask}>
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
     * P1-2: a map stage's steps as [step name, [[slot, task]…]] — a task
     * step's slot is its name, a verification step's `<step>/task` and
     * `<step>/verifier`.
     *
     * @param array<array-key, mixed> $stage
     * @return list<array{0: string, 1: list<array{0: string, 1: WorkflowTask}>, 2: array<array-key, mixed>}>
     */
    private static function mapSteps(array $stage): array
    {
        $steps = [];
        foreach (is_array($stage['steps'] ?? null) ? $stage['steps'] : [] as $i => $step) {
            if (!is_array($step)) {
                continue;
            }
            $name = is_string($step['name'] ?? null) && $step['name'] !== '' ? $step['name'] : 'step_' . ($i + 1);
            $pairs = [];
            if (($step['type'] ?? '') === 'verification') {
                foreach (['task', 'verifier'] as $slot) {
                    if (($step[$slot] ?? null) instanceof WorkflowTask) {
                        $pairs[] = ["{$name}/{$slot}", $step[$slot]];
                    }
                }
            } else {
                foreach (self::taskList($step) as $task) {
                    $pairs[] = [$name, $task];
                }
            }
            $steps[] = [$name, $pairs, $step];
        }

        return $steps;
    }

    /**
     * P1-2 mirror of a map stage's run law, step by step: every step sees
     * `{{prevResult}}`, the item tokens and — item-scoped — the results and
     * `.output` of the steps BEFORE it (a verifier also its own task's); a
     * later step's are a silent miss like a later stage's. A step `when:`
     * that reads neither an earlier step nor an earlier stage is never
     * satisfied, so the step always skips.
     *
     * @param array<string, int> $producers
     * @param array<string, int> $outputs
     * @param array<string, int> $failedProducers
     * @param array<string, mixed> $seedContext
     * @param ?list<string> $toolNames
     */
    private static function checkMapSteps(string $stageName, array $stage, int $ordinal, array $producers, array $outputs, array $failedProducers, array $seedContext, ?array $toolNames, array &$findings): void
    {
        $steps = self::mapSteps($stage);
        if ($steps === []) {
            self::add($findings, 'error', $stageName, 'This map stage has no steps; the engine fails it before any item runs.');

            return;
        }

        $seen = [];
        foreach ($steps as [$stepName, $pairs, $raw]) {
            if (isset($seen[$stepName])) {
                self::add($findings, 'error', "{$stageName}/{$stepName}", "Duplicate map step name \"{$stepName}\"; the item row and {{{$stepName}.results}} can name only one of them.");
            }
            $type = $raw['type'] ?? '';
            if ($type !== 'stage' && $type !== 'verification') {
                self::add($findings, 'error', "{$stageName}/{$stepName}", "A '" . (is_string($type) ? $type : get_debug_type($type)) . "' map step is refused; a step is a task or a verification sub-stage.");
            }
            if (is_array($raw['when'] ?? null)) {
                foreach (WorkflowBuilder::clauseStages($raw['when']) as $read) {
                    if (!isset($seen[$read]) && !(isset($outputs[$read]) && $outputs[$read] < $ordinal)) {
                        self::add($findings, 'warning', "{$stageName}/{$stepName}", "when reads \"{$read}\", which is neither an earlier step of this map nor an earlier stage; the guard is never satisfied and the step always skips.");
                    }
                }
            }

            $stepTokens = ['prevResult' => true];
            foreach ($pairs as [$slot, $task]) {
                $tokens = $stepTokens;
                if ($task->input !== null) {
                    $tokens['input'] = true;
                }
                if (self::isVerifierSlot($slot) && $task->lenses !== null) {
                    $tokens['lens'] = true;
                }
                self::checkTask($stageName, $slot, $task, $ordinal, $producers, $outputs, $seedContext + $tokens, $toolNames, $findings);
                self::checkFailedRefs($stageName, $task, $ordinal, $failedProducers, $producers, $findings);
                // Item-scoped: what this slot wrote is visible to the slots
                // and steps after it, as if produced at this ordinal.
                $key = $task->name !== null && $task->name !== '' ? $task->name : (str_ends_with($slot, '/verifier') ? $stepName . '_verifier' : $stepName);
                $producers[$key] = $ordinal;
            }
            $outputs[$stepName] = $ordinal;
            $seen[$stepName] = true;
        }
    }

    /**
     * P1-3 mirror of the `minSuccess:` law {@see WorkflowBuilder::minSuccess()}
     * and the YAML loader enforce, for a hand-built stage neither door saw:
     * parallel-only, one of the two normalized spellings, and a count a
     * static agent list can meet. Plus one warning the loader cannot give:
     * the workflow-wide stopOnFirstFailure does not apply to this stage.
     *
     * @param array<array-key, mixed> $stage
     * @return bool whether the stage records `{{<stage>.failed}}`
     */
    private static function checkMinSuccess(string $type, string $stageName, array $stage, Workflow $workflow, array &$findings): bool
    {
        if (!array_key_exists('minSuccess', $stage)) {
            return false;
        }
        if (!in_array($type, WorkflowBuilder::TOLERANT_TYPES, true) || array_key_exists('uses', $stage)) {
            self::add($findings, 'error', $stageName, "minSuccess on a '{$type}' stage is never read; only a parallel or map stage tolerates failures.");

            return false;
        }

        $spec = $stage['minSuccess'];
        $count = is_array($spec) && is_int($spec['count'] ?? null) && count($spec) === 1 ? $spec['count'] : null;
        $percent = is_array($spec) && is_int($spec['percent'] ?? null) && count($spec) === 1 ? $spec['percent'] : null;
        if (($count === null || $count < 1) && ($percent === null || $percent < 1 || $percent > 100)) {
            self::add($findings, 'error', $stageName, 'minSuccess ' . json_encode($spec) . ' is not {count: n >= 1} or {percent: 1..100}; the engine cannot judge the stage by it.');

            return false;
        }

        $agents = count(self::taskList($stage));
        if ($count !== null && !array_key_exists('from', $stage) && $count > $agents) {
            self::add($findings, 'error', $stageName, "minSuccess {$count} exceeds the {$agents} agents this stage declares; the stage can never complete.");
        }
        if ($workflow->stopOnFirstFailure) {
            self::add($findings, 'warning', $stageName, 'stopOnFirstFailure does not apply to this minSuccess stage: its agents all run so the tolerance can be judged.');
        }

        return true;
    }

    /**
     * P1-3: `{{X.failed}}` interpolates only once a minSuccess stage named X
     * has settled; any other X leaves the token verbatim in the prompt —
     * unless X names a RESULT, whose typed row may carry a `failed` leaf the
     * pointer form reads (that is the C10 pointer surface, not ours).
     *
     * @param array<string, int> $failedProducers
     * @param array<string, int> $producers
     */
    private static function checkFailedRefs(string $stageName, WorkflowTask $task, int $ordinal, array $failedProducers, array $producers, array &$findings): void
    {
        if (preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\.failed\}\}/', $task->prompt, $m) < 1) {
            return;
        }
        foreach (array_unique($m[1]) as $name) {
            if (!isset($failedProducers[$name])) {
                if (isset($producers[$name])) {
                    continue;
                }
                self::add($findings, 'warning', $stageName, "{{{$name}.failed}} names no minSuccess parallel stage; only one records a casualty list, so the token may stay verbatim.");
            } elseif ($failedProducers[$name] >= $ordinal) {
                self::add($findings, 'error', $stageName, "{{{$name}.failed}} reads a casualty list recorded at stage #{$failedProducers[$name]} — not earlier than this stage #{$ordinal}, so the token stays verbatim.");
            }
        }
    }

    /**
     * The bare `{{token}}`s the ENGINE injects for every task of this stage
     * (keyed like a seed context, so checkInterpolation() treats them as
     * resolvable): `{{round}}`/`{{previous}}` inside a `loop:` (`previous`
     * resolves from round 2 on), `{{seen}}`/`{{fresh}}` beside
     * `loop.accumulate`, and on a `from:` fan-out `{{item}}`/`{{index}}`
     * (plus `{{path}}` for a glob).
     *
     * @param array<array-key, mixed> $stage
     * @return array<string, true>
     */
    private static function stageEngineTokens(array $stage): array
    {
        $tokens = [];
        if (is_array($stage['loop'] ?? null)) {
            $tokens += ['round' => true, 'previous' => true];
            if (is_array($stage['loop']['accumulate'] ?? null)) {
                $tokens += ['seen' => true, 'fresh' => true];
            }
        }
        if (is_array($stage['from'] ?? null)) {
            $tokens += ['item' => true, 'index' => true];
            if (isset($stage['from']['glob'])) {
                $tokens['path'] = true;
            }
        }

        return $tokens;
    }

    /**
     * The task-scoped engine tokens: `{{input}}` on a task with an `input:`
     * join, `{{prevResult}}` on a `tasks:` entry after the first, on every
     * pipeline step (the first sees ""), and on a verifier.
     *
     * @param array<array-key, mixed> $stage
     * @return array<string, true>
     */
    private static function taskEngineTokens(string $type, array $stage, string $slot, WorkflowTask $task, int $position): array
    {
        $tokens = [];
        if ($task->input !== null) {
            $tokens['input'] = true;
        }
        if ($type === 'pipeline'
            || ($type === 'verification' && $slot === 'verifier')
            || ($type === 'stage' && $position > 0)) {
            $tokens['prevResult'] = true;
        }
        if (self::isVerifierSlot($slot) && $task->lenses !== null) {
            $tokens['lens'] = true;
        }

        return $tokens;
    }

    /**
     * P1-5 mirror of the `loop:` law {@see WorkflowBuilder::loop()} and the
     * loader enforce, for a hand-built stage neither door saw — a loop on a
     * shape the engine does not repeat, `{dry: N}` without `accumulate`
     * (never satisfied: the loop always runs to max) — plus one warning the
     * loader cannot give: `{dry: N}` with N >= max can never fire before
     * `max` ends the loop anyway.
     *
     * @param array<array-key, mixed> $stage
     */
    private static function checkLoop(string $type, string $stageName, array $stage, array &$findings): void
    {
        $loop = $stage['loop'] ?? null;
        if (!is_array($loop)) {
            return;
        }
        if (!in_array($type, WorkflowBuilder::LOOPABLE_TYPES, true) || array_key_exists('uses', $stage)) {
            self::add($findings, 'error', $stageName, "loop on a '{$type}'" . (array_key_exists('uses', $stage) ? ' uses' : '') . ' stage is never honoured; stage, parallel, verification and map stages loop.');

            return;
        }
        $until = is_array($loop['until'] ?? null) ? $loop['until'] : [];
        $dry = self::dryLeaf($until);
        if ($dry === null) {
            return;
        }
        if (!is_array($loop['accumulate'] ?? null)) {
            self::add($findings, 'error', $stageName, "until {dry: {$dry}} counts rounds that add nothing new to loop.accumulate, which this loop does not declare; it can never hold and the loop always runs to max.");
        } elseif (is_int($loop['max'] ?? null) && $dry >= $loop['max']) {
            self::add($findings, 'warning', $stageName, "until {dry: {$dry}} needs {$dry} dry rounds after at least one productive round, but max is {$loop['max']}; max ends the loop first.");
        }
    }

    /**
     * The N of the first `{dry: N}` leaf in a normalized clause, or null.
     *
     * @param array<array-key, mixed> $node
     */
    private static function dryLeaf(array $node): ?int
    {
        if (is_array($node['conditions'] ?? null)) {
            foreach ($node['conditions'] as $child) {
                $found = is_array($child) ? self::dryLeaf($child) : null;
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        return ($node['op'] ?? null) === 'dry' && is_int($node['value'] ?? null) ? $node['value'] : null;
    }

    /**
     * P1-1 mirror: `from: {stage: X}` reads a typed row written by a stage
     * BEFORE this one (the loader judges the same order law; a hand-built
     * array is checked here).
     *
     * @param array<array-key, mixed> $stage
     * @param array<string, int> $outputs
     */
    private static function checkDataFanOut(string $stageName, array $stage, int $ordinal, array $outputs, array &$findings): void
    {
        $source = is_array($stage['from'] ?? null) ? ($stage['from']['stage'] ?? null) : null;
        if (!is_string($source)) {
            return;
        }
        if (!isset($outputs[$source]) || $outputs[$source] >= $ordinal) {
            self::add($findings, 'error', $stageName, "from.stage \"{$source}\" is not a stage declared before this one; the fan-out reads prior stages only and fails at run time.");
        }
    }

    /**
     * P0-3 mirror of the `from:` spec law the YAML loader and
     * {@see WorkflowBuilder::parallel()} enforce — reached only by a
     * hand-built stage array, which neither door saw: `from` on a
     * non-parallel stage, and a `max` outside 1..{@see Workflow::FAN_OUT_CEILING}.
     *
     * @param array<array-key, mixed> $stage
     */
    private static function checkFanOutShape(string $type, string $stageName, array $stage, array &$findings): void
    {
        if (!array_key_exists('from', $stage)) {
            if ($type === 'map') {
                self::add($findings, 'error', $stageName, 'This map stage has no "from" source; the engine fails it before any item runs.');
            }

            return;
        }
        if ($type !== 'parallel' && $type !== 'map') {
            self::add($findings, 'error', $stageName, "\"from\" on a '{$type}' stage fans nothing out; only a parallel or map stage reads it.");
            return;
        }
        $from = is_array($stage['from']) ? $stage['from'] : [];
        if (array_key_exists('max', $from) && $from['max'] !== null
            && (!is_int($from['max']) || $from['max'] < 1 || $from['max'] > Workflow::FAN_OUT_CEILING)) {
            self::add($findings, 'error', $stageName, 'from.max ' . var_export($from['max'], true) . ' is not a whole number from 1 to ' . Workflow::FAN_OUT_CEILING . '; the engine cannot honour it.');
        }
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
     * C11: walk the `uses:` edges outward from this workflow. Two verdicts,
     * both errors: a name already on the path being walked CYCLES (the engine
     * refuses it at dispatch), and a path past WorkflowEngine::MAX_USES_DEPTH
     * is TOO DEEP. Both findings spell the chain `a -> b -> c -> d` because a
     * two-name message cannot locate the hop a long plan went wrong at.
     * Absent resolver = no registry view = the whole leg is skipped; a name
     * the resolver cannot load is left to load()'s run-start refusal.
     *
     * @param list<array{severity: string, stage: string, message: string}> $findings
     * @param ?callable(string):(?Workflow) $resolver
     */
    private static function checkIncludes(Workflow $workflow, array &$findings, ?callable $resolver): void
    {
        if ($resolver === null) {
            return;
        }

        foreach ($workflow->stages as $stage) {
            if (!is_array($stage) || !is_string($stage['uses'] ?? null) || $stage['uses'] === '') {
                continue;
            }

            $stageName = is_string($stage['name'] ?? null) && $stage['name'] !== '' ? $stage['name'] : 'unknown';
            self::walkInclude($stageName, $stage['uses'], [$workflow->name], $findings, $resolver);
        }
    }

    /**
     * One include edge, then its subtree. Path-relative DFS with no visit
     * memo: cycle verdicts depend on the path, human plans are tiny, and the
     * worst case a DAG admits is bounded by the depth refusal itself.
     *
     * @param list<string> $path   workflows already running around this edge
     * @param list<array{severity: string, stage: string, message: string}> $findings
     * @param callable(string):(?Workflow) $resolver
     */
    private static function walkInclude(string $stageName, string $included, array $path, array &$findings, callable $resolver): void
    {
        if (in_array($included, $path, true)) {
            self::add($findings, 'error', $stageName, 'uses-chain cycles: ' . implode(' -> ', [...$path, $included]) . ' — the engine refuses the include before any agent runs.');

            return;
        }

        $chain = [...$path, $included];

        if (count($chain) > WorkflowEngine::MAX_USES_DEPTH) {
            self::add($findings, 'error', $stageName, 'uses-chain too deep: ' . implode(' -> ', $chain) . ' — maximum depth is ' . WorkflowEngine::MAX_USES_DEPTH . '.');

            return;
        }

        $inner = $resolver($included);

        if ($inner === null) {
            return;
        }

        foreach ($inner->stages as $innerStage) {
            if (!is_array($innerStage) || !is_string($innerStage['uses'] ?? null) || $innerStage['uses'] === '') {
                continue;
            }

            $innerStageName = is_string($innerStage['name'] ?? null) && $innerStage['name'] !== '' ? $innerStage['name'] : $included;
            self::walkInclude($innerStageName, $innerStage['uses'], $chain, $findings, $resolver);
        }
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
        // P2-4 mirror: the YAML loader resolves `{$ref: <name>}` against the
        // file's `schemas:` map, so a ref that survives into the DTO was
        // hand-built and never resolved — the agent would be held to a
        // schema that only names another one.
        if (is_array($task->outputSchema) && count($task->outputSchema) === 1 && array_key_exists('$ref', $task->outputSchema)) {
            $ref = $task->outputSchema['$ref'];
            self::add($findings, 'error', $where, 'outputSchema is an unresolved {$ref: ' . (is_string($ref) ? $ref : get_debug_type($ref)) . '}; only a YAML plan\'s top-level "schemas:" map resolves a reference — inline the schema document.');
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
            if (!self::isVerifierSlot($slot)) {
                self::add($findings, 'error', $where, 'reject() rejects a verifier VERDICT and belongs on the verifier slot of a verification stage — the engine refuses the run on this plan.');
            }
            if (@preg_match($reject, '') === false) {
                self::add($findings, 'error', $where, 'The reject pattern does not compile as PCRE — the engine fails the scan CLOSED and the verdict can never read clean.');
            }
            if ($task->rejectMinOccurrences < 1) {
                self::add($findings, 'warning', $where, "rejectMinOccurrences {$task->rejectMinOccurrences} can never be satisfied as written; the scan fails the rule CLOSED, so the verdict is always rejected.");
            }
        }

        // P1-4 mirror: the verifier-panel keys' placement (the engine's
        // pre-flight refusal) and the shape law the builder and loader hold.
        if ($task->declaresPanel()) {
            if (!self::isVerifierSlot($slot)) {
                self::add($findings, 'error', $where, 'votes, lenses and quorum convene a verifier panel and belong on the verifier slot of a verification stage — the engine refuses the run on this plan.');
            }
            if ($task->votes < 1) {
                self::add($findings, 'error', $where, "votes {$task->votes} is not positive; a panel needs at least one vote.");
            }
            if ($task->lenses !== null && count($task->lenses) !== $task->votes) {
                self::add($findings, 'error', $where, 'lenses lists ' . count($task->lenses) . " lens(es) but votes is {$task->votes}; the panel needs exactly one lens per vote.");
            }
            if ($task->quorum !== null && ($task->quorum < 1 || $task->quorum > $task->votes)) {
                self::add($findings, 'error', $where, "quorum {$task->quorum} is outside 1..{$task->votes}; the verdict can never pass (or always passes) as written.");
            }
            if (self::isVerifierSlot($slot) && $task->reject === null && $task->votes > 1) {
                self::add($findings, 'warning', $where, 'This verifier panel has no reject pattern, so only a vote that fails or times out counts against the quorum; add reject to make a vote\'s words a verdict.');
            }
        }
        if (str_contains($task->prompt, '{{lens}}') && !(self::isVerifierSlot($slot) && $task->lenses !== null)) {
            self::add($findings, 'warning', $where, '{{lens}} is interpolated only inside a verifier panel that declares lenses; here it stays verbatim in the prompt.');
            $seedContext['lens'] = true;
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

    /** A verification stage's verifier slot, top-level (`verifier`) or a map step's (`<step>/verifier`). */
    private static function isVerifierSlot(string $slot): bool
    {
        return $slot === 'verifier' || str_ends_with($slot, '/verifier');
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
            // P1-1: inside a fan-out `{{item.output}}` is a pointer into the
            // item (the engine's item pass claims it first), not a stage row.
            if ($suffix !== '' && $name === 'item' && array_key_exists('index', $seedContext) && array_key_exists('item', $seedContext)) {
                continue;
            }
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
                        self::add($findings, 'error', $where, "{{{$name}.output}} reads the output written at stage #{$outputs[$name]} — later than this task's stage #{$ordinal}, so the token stays verbatim in the dispatched prompt (a silent miss at run time).");
                    }

                    continue;
                }
                self::add($findings, 'error', $where, "{{{$name}.output}} names no stage or pipeline step in this plan, so the token survives interpolation.");

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

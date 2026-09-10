<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * The model-callable Task tool: delegate one bounded task to a sub-agent from
 * the session's agent roster and return the sub-agent's final text (crush_code.md P8.13).
 *
 * This is the seam the whole sub-agent machinery was built toward but never had:
 * {@see AgentManager::createSubAgent()} and {@see AgentManager::executeAll()}
 * existed, `Chat::executeAgents()` reached them only through slash-command
 * plumbing, and `src/Renderer.php`'s header recorded the absence in terms —
 * "nothing in src/ or bin/ calls createSubAgent()/executeSubAgent() directly,
 * because there is no Task/Agent tool". The name deliberately mirrors the
 * upstream delegation tool rather than the unrelated `SugarCraft\Crush\Agents\Task`
 * value object, which this class neither reads nor extends; the `Tool` suffix
 * on the class follows the `LspTool`/`SkillTool` precedent, the bare wire name
 * `Task` follows what the model already knows from upstream.
 *
 * THE CEILING IS INHERITED, NOT REIMPLEMENTED. Dispatch goes through
 * {@see AgentManager::executeAll()}, which resolves each batch member's grants
 * against the session registry (E644) and hands the worker pool a per-agent
 * resolver — the same governed path `Chat::executeAgents()` drives. A Task call
 * can never widen what the named agent declared, and a batch that fails grant
 * resolution settles its members with the reason and fails loud here rather
 * than forking anything.
 *
 * FAIL-CLOSED IN THREE PLACES, because a delegation tool that fabricates is
 * worse than no delegation tool at all:
 *  - unbound (no session {@see AgentManager} wired in) → refusal naming the
 *    missing wiring; the corpus builds this instance standalone, and the one
 *    consumer that calls `execute()` on every corpus tool must get an error
 *    result, never a spawn and never a throw;
 *  - no worker provider spec (a pool bound without one falls through to the
 *    manager's default pool, whose refusing worker reports FAILED naming the
 *    absence) → the refusal text comes back as this result's error;
 *  - anything the pool reports as Failed/Stopped/TimedOut → loud, with the
 *    status and the child's own message, never a plausible-looking summary.
 *  - unknown agent → the roster is named in full, so the model can correct
 *    itself on the next turn instead of hallucinating one.
 *
 * COARSE BY DECISION: the tool surfaces as one ordinary ToolStarted/ToolFinished
 * pair; the sub-agent's streamed partials stay inside the worker. Upstream's
 * Task tool behaves the same way from the parent model's point of view — one
 * call, one final report — and the live-progress surfaces (pane, status strip)
 * keep their own feed through Chat/WorkflowEngine, which this path does not
 * duplicate.
 *
 * Mirrors the delegation role of sugar-crush's plan P8.13 (`Task` tool) over
 * the in-tree {@see AgentManager}/{@see AgentWorkerPool} machinery; see
 * {@see AgentWorkerPool::executeAll()} for what a dispatched worker actually
 * carries across the fork.
 */
final readonly class TaskTool implements Tool
{
    public function __construct(
        private ?AgentManager $agentManager = null,
        private ?AgentWorkerPool $workerPool = null,
    ) {}

    public function name(): string
    {
        return 'Task';
    }

    public function description(): string
    {
        return 'Delegate one self-contained task to a sub-agent from the agent roster and return that agent\'s final text.'
            . ' The sub-agent does not see this conversation, so the prompt must carry every path, constraint and'
            . ' expected output the task needs; the tool returns once the sub-agent finishes, not as it works.'
            . ' Do not reach for it for work you can do directly in one call, and never parallelise a dependency with'
            . ' it — the delegated agent runs under the roster entry\'s own tool grants and permission gate, so a'
            . ' narrower agent cannot be widened by phrasing.'
            . ' The result is the sub-agent\'s report or a loud failure naming why; it is not a diff, and the working'
            . ' tree may have moved underneath by the time it returns.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear, concise 5-10 word description in active voice of what is delegated'
                        . ' (e.g. "Audit the auth middleware", not "delegates a task")',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'The complete task for the sub-agent, self-contained: it never sees this'
                        . ' conversation, so include every path, constraint and expected-output shape inline',
                ],
                'agent' => [
                    'type' => 'string',
                    'description' => 'Roster name of the agent to run (e.g. "coder", "reviewer", "debugger",'
                        . ' "architect", "tester", "devops"); an unknown name is refused and the live roster is'
                        . ' named in the failure',
                ],
            ],
            'required' => ['description', 'prompt', 'agent'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $startedAt = microtime(true);
        $toolCallId = (string) ($args['id'] ?? '');

        $prompt = trim((string) ($args['prompt'] ?? ''));
        if ($prompt === '') {
            return $this->refusal($toolCallId, 'the Task tool needs a non-empty "prompt": the complete, self-contained task the sub-agent should carry out');
        }

        $agentName = trim((string) ($args['agent'] ?? ''));
        if ($agentName === '') {
            return $this->refusal($toolCallId, 'the Task tool needs a non-empty "agent": the roster name to run the task as');
        }

        // Bound or absent — the LAUNCH decision, before anything is dispatched.
        if ($this->agentManager === null) {
            return $this->refusal(
                $toolCallId,
                'the Task tool is not wired to a session AgentManager on this launch, so it cannot delegate'
                . ' (the registration feed is the documented Bootstrap seam; refusing rather than inventing a manager)',
            );
        }

        // The roster is the authority on names; answering with the live list on
        // a miss is what keeps a hallucinated agent cheap to recover from.
        if ($this->agentManager->get($agentName) === null) {
            return $this->refusal($toolCallId, sprintf(
                'agent "%s" is not in the session roster (registered: %s)',
                $agentName,
                $this->rosterNames(),
            ));
        }

        try {
            $subAgent = $this->agentManager->createSubAgent($agentName, $prompt);
        } catch (\RuntimeException|\LogicException $refusal) {
            return $this->refusal($toolCallId, $refusal->getMessage());
        }

        $request = new CompleteRequest(
            model: $subAgent->agent->model,
            messages: [],
            tools: null,
            systemPrompt: $subAgent->agent->prompt,
        );

        try {
            /** @var list<AgentResult> $results */
            $results = iterator_to_array(
                $this->agentManager->executeAll([$subAgent], $request, $this->workerPool),
                false,
            );
        } catch (\RuntimeException $refusal) {
            // E644 grant-resolution failures arrive here AFTER the manager has
            // settled the batch with the reason; forwarding that text is what
            // keeps the model-facing story identical to the operator-facing one.
            return $this->refusal($toolCallId, $refusal->getMessage());
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($results === []) {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" was cancelled before it produced a result',
                $agentName,
            ), $durationMs);
        }

        $result = $results[0];

        if ($result->isFailure()) {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" %s%s%s',
                $agentName,
                $result->status->value,
                $result->error !== null ? ': ' . $result->error->getMessage() : '',
                $result->output !== null && trim($result->output) !== ''
                    ? ' — partial output: ' . trim($result->output)
                    : '',
            ), $durationMs);
        }

        if ($result->output === null || trim($result->output) === '') {
            return $this->refusal($toolCallId, sprintf(
                'sub-agent "%s" completed without any output text',
                $agentName,
            ), $durationMs);
        }

        return new ToolResult(
            toolCallId: $toolCallId,
            content: trim($result->output),
            isError: false,
            durationMs: $durationMs,
        );
    }

    /**
     * @param non-empty-string $why
     */
    private function refusal(string $toolCallId, string $why, ?int $durationMs = null): ToolResult
    {
        return new ToolResult(
            toolCallId: $toolCallId,
            content: 'Error: Task refused — ' . $why,
            isError: true,
            durationMs: $durationMs,
        );
    }

    /**
     * Comma-joined names of every agent the bound manager currently answers.
     */
    private function rosterNames(): string
    {
        $names = [];
        foreach ($this->agentManager?->all() ?? [] as $agent) {
            $names[] = $agent->name;
        }
        sort($names);

        return $names === [] ? 'none' : implode(', ', $names);
    }
}

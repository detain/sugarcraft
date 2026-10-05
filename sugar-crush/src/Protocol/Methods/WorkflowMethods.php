<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol\Methods;

use SugarCraft\Crush\Host\SessionHost;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Protocol\CallContext;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\MethodRegistry;
use SugarCraft\Crush\Protocol\MethodSpec;
use SugarCraft\Crush\Protocol\Params;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Protocol\Scope;
use SugarCraft\Crush\Tools\BuiltIn\WorkflowTool;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Workflows\WorkflowEngineInterface;
use SugarCraft\Crush\Workflows\WorkflowNotRunningException;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * `workflow.*` (Appendix O §6.3, roadmap O-6c): the `/workflow` surface over
 * the wire, and the runs a session has seen.
 *
 * A RUN IS A TURN. `workflow.run` and `workflow.resume` go through the
 * session's host exactly as typing `/workflow run …` does
 * ({@see SessionHost::runCommand()} → `Host\Commands\WorkflowCommand`): the
 * run occupies the session's turn, is stepped from the loop (the server never
 * blocks on it), shows its sub-agents live, and lands its report as the turn's
 * reply — the session's ordinary events carry all of it. A turn already in
 * flight refuses a new run (`command_refused`). `workflow.pause` and
 * `workflow.status` answer at once and work mid-run, as `/workflow pause` does.
 *
 * `workflow.runs` lists what the session's transcript holds of both kinds of
 * run — the `/workflow` reports and the model's own `Workflow` tool calls
 * (roadmap 4.10-2), the latter live while they run — so a panel can show
 * them without parsing the transcript itself.
 */
final class WorkflowMethods
{
    /** Bytes of one run's report `workflow.runs` carries; `tool.output` has the rest of a tool run's. */
    public const REPORT_MAX_BYTES = 16_384;

    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/';

    private const VAR_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/';

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new('workflow.list', Scope::Read, 'The workflows /workflow run can start.', self::list(...)));
        $registry->add(MethodSpec::new('workflow.run', Scope::Write, 'Run a workflow in a session, as /workflow run does: it occupies the session\'s turn.', self::run(...), true));
        $registry->add(MethodSpec::new('workflow.pause', Scope::Write, 'Pause a workflow run before its next stage.', self::pause(...), true));
        $registry->add(MethodSpec::new('workflow.resume', Scope::Write, 'Resume a paused workflow run in a session.', self::resume(...), true));
        $registry->add(MethodSpec::new('workflow.status', Scope::Read, 'A workflow run\'s status.', self::status(...)));
        $registry->add(MethodSpec::new('workflow.runs', Scope::Read, 'The workflow runs a session\'s transcript holds: /workflow reports and Workflow tool calls.', self::runs(...)));
    }

    /** @return array<string, mixed> */
    private static function list(CallContext $call, Params $params): array
    {
        $engine = $call->server->hub()->workspace()->workflowEngine;

        return [
            'available' => $engine !== null,
            'items' => $engine === null ? [] : \array_values(\array_map(
                static fn (mixed $name): array => ['name' => (string) $name],
                $engine->listWorkflows(),
            )),
        ];
    }

    /** @return array<string, mixed> */
    private static function run(CallContext $call, Params $params): array
    {
        self::refuseWhileDraining($call);
        $host = $call->host(SessionMethods::sessionId($params));
        self::engine($call);
        $name = $params->string('name', 128);
        if (\preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw RpcError::invalidParams('name is a workflow name: letters, digits, dot, underscore, dash', 'invalid_workflow_name');
        }

        $args = [$name];
        foreach ($params->has('vars') ? $params->object('vars') : [] as $key => $value) {
            if (!\is_string($key) || \preg_match(self::VAR_KEY_PATTERN, $key) !== 1) {
                throw RpcError::invalidParams(\sprintf('vars key "%s" must be a name (letters, digits, underscore, dot, dash)', $key), 'invalid_workflow_vars');
            }
            if (!\is_scalar($value)) {
                throw RpcError::invalidParams(\sprintf('vars.%s must be a string, number or boolean', $key), 'invalid_workflow_vars');
            }
            $text = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            // `/workflow run` splits its arguments on whitespace: a value
            // holding any would be cut, so refuse it rather than mangle it.
            if ($text === '' || \preg_match('/\s/u', $text) === 1 || \strlen($text) > 4096) {
                throw RpcError::invalidParams(\sprintf('vars.%s must be 1-4096 bytes with no whitespace', $key), 'invalid_workflow_vars');
            }
            $args[] = $key . '=' . $text;
        }

        return self::command($host, 'run ' . \implode(' ', $args));
    }

    /** @return array<string, mixed> */
    private static function pause(CallContext $call, Params $params): array
    {
        $engine = self::engine($call);
        $workflowId = self::workflowId($params);
        try {
            $engine->pause($workflowId);
        } catch (WorkflowNotRunningException $e) {
            throw RpcError::notFound($e->getMessage(), 'workflow_not_running');
        } catch (\Throwable $e) {
            throw RpcError::of(ErrorCode::Conflict, $e->getMessage(), 'workflow_refused');
        }

        return ['workflowId' => $workflowId, 'status' => self::statusOf($engine, $workflowId)];
    }

    /** @return array<string, mixed> */
    private static function resume(CallContext $call, Params $params): array
    {
        self::refuseWhileDraining($call);
        $host = $call->host(SessionMethods::sessionId($params));
        self::engine($call);

        return self::command($host, 'resume ' . self::workflowId($params));
    }

    /** @return array<string, mixed> */
    private static function status(CallContext $call, Params $params): array
    {
        $engine = self::engine($call);
        $workflowId = self::workflowId($params);
        try {
            $status = $engine->getStatus($workflowId)->value;
        } catch (WorkflowNotRunningException $e) {
            throw RpcError::notFound($e->getMessage(), 'workflow_not_found');
        }

        return ['workflowId' => $workflowId, 'status' => $status];
    }

    /** @return array<string, mixed> */
    private static function runs(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));

        return ['items' => self::transcriptRuns($host->history())];
    }

    // ── helpers ────────────────────────────────────────────────────────

    /**
     * The workflow runs in $history, oldest first: every `/workflow`
     * report a run settled with, and every `Workflow` tool call — still
     * running, or with its report.
     *
     * @param list<Message> $history
     * @return list<array<string, mixed>>
     */
    public static function transcriptRuns(array $history): array
    {
        $runs = [];
        foreach ($history as $row) {
            if ($row->pendingToolCallId !== null && $row->pendingToolName === WorkflowTool::NAME) {
                $runs[] = [
                    'source' => 'tool',
                    'toolCallId' => $row->pendingToolCallId,
                    'name' => self::planName($row->pendingToolArguments),
                    'status' => WorkflowStatus::Running->value,
                    'running' => true,
                ];
                continue;
            }
            foreach ($row->toolResults as $result) {
                if ($result instanceof ToolResult && $result->name === WorkflowTool::NAME) {
                    $runs[] = self::toolRun($result);
                }
            }
            if ($row->toolResults === [] && \preg_match("/^\\*\\*Workflow '([^']*)' (?:resumed and )?([a-z]+)\\*\\*/", $row->content, $m) === 1) {
                $id = \preg_match('/^ID: `([^`]+)`/m', $row->content, $idMatch) === 1 ? $idMatch[1] : null;
                $runs[] = \array_filter([
                    'source' => 'command',
                    'name' => $m[1],
                    'status' => $m[2],
                    'workflowId' => $id,
                    'running' => false,
                    'report' => self::clip($row->content),
                ], static fn (mixed $v): bool => $v !== null);
            }
        }

        return $runs;
    }

    /** @return array<string, mixed> */
    private static function toolRun(ToolResult $result): array
    {
        $report = $result->isError() ? (string) ($result->error ?? $result->result) : $result->result;
        $status = \preg_match("/^Workflow '[^']*' ([a-z]+):/", $report, $m) === 1 ? $m[1] : ($result->isError() ? WorkflowStatus::Failed->value : WorkflowStatus::Completed->value);
        $name = \preg_match("/^Workflow '([^']*)'/", $report, $n) === 1 ? $n[1] : self::planName($result->arguments);

        return \array_filter([
            'source' => 'tool',
            'toolCallId' => $result->id,
            'name' => $name,
            'status' => $status,
            'running' => false,
            'report' => self::clip($report),
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The `name:` a Workflow tool plan gives itself, or '' — read off the
     * YAML's top-level line, never by running a parser on model output here.
     *
     * @param array<string, mixed> $arguments
     */
    private static function planName(array $arguments): string
    {
        $plan = $arguments['plan'] ?? null;
        if (\is_string($plan) && \preg_match('/^name:\s*["\']?([^"\'\r\n#]+?)["\']?\s*(?:#.*)?$/m', $plan, $m) === 1) {
            return \trim($m[1]);
        }

        return '';
    }

    private static function clip(string $text): string
    {
        return \strlen($text) <= self::REPORT_MAX_BYTES ? $text : \mb_strcut($text, 0, self::REPORT_MAX_BYTES, 'UTF-8') . "\n[… truncated]";
    }

    /**
     * `/workflow $args` on $host: its rows and effects, or why it did not run
     * (`command_refused` while another turn holds the session).
     *
     * @return array<string, mixed>
     */
    private static function command(SessionHost $host, string $args): array
    {
        $result = $host->runCommand('workflow', $args);
        if ($result->isRefused()) {
            throw RpcError::of(ErrorCode::Conflict, (string) $result->error, 'command_refused', ['command' => 'workflow']);
        }

        return $result->toArray();
    }

    private static function engine(CallContext $call): WorkflowEngineInterface
    {
        return $call->server->hub()->workspace()->workflowEngine
            ?? throw RpcError::of(ErrorCode::UnsupportedInServer, 'this workspace has no workflow engine', 'workflows_unavailable');
    }

    private static function workflowId(Params $params): string
    {
        $workflowId = $params->string('workflowId', 256);
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,255}$/', $workflowId) !== 1) {
            throw RpcError::invalidParams('workflowId is a run id or a workflow name', 'invalid_workflow_id');
        }

        return $workflowId;
    }

    private static function statusOf(WorkflowEngineInterface $engine, string $workflowId): string
    {
        try {
            return $engine->getStatus($workflowId)->value;
        } catch (\Throwable) {
            return WorkflowStatus::Paused->value;
        }
    }

    private static function refuseWhileDraining(CallContext $call): void
    {
        if ($call->server->isDraining()) {
            throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
        }
    }
}

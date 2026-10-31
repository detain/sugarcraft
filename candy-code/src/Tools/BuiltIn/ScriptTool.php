<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Agents\PathJail as AgentPathJail;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\AcceptsWorktreeJail;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Code\Tools\DispatchesNestedTools;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\Sandbox\SandboxFactory;
use SugarCraft\Code\Tools\Script\ScriptRun;
use SugarCraft\Code\Tools\Script\ScriptRunner;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * The `Script` catalog tool (C-TOOL-6.d): runs a short PHP script whose only
 * effects on the world are nested tool calls, each one permissioned exactly
 * like a direct call.
 *
 * The engine half ({@see ScriptRunner}, C-TOOL-6.c) is policy-free by design
 * and wires nothing; this class is the wiring step the design assigned here.
 * It builds its OWN jail — the `no-network` profile straight from
 * {@see SandboxFactory}, deliberately independent of the `bashSandbox`
 * setting: a script's tool calls mutate the workspace through the gate, so
 * the sandbox's job here is only to keep the CHILD process itself from
 * reaching the network or the un-writable world, which is a different
 * question from what Bash's user-chosen mode should be. Where no jail
 * backend exists for the platform, or the backend's own probe says it
 * cannot start here, Script REFUSES (§6.3 ruling: no ungated every-call-Ask
 * arm — a script whose jail cannot run is not secretly a Bash with Ask
 * forced on every call).
 *
 * Nested calls ride {@see DispatchesNestedTools}: the Runtime binds, on the
 * way into every execution, a dispatch closure that runs each inner call
 * through the SAME gate and hook chain as an in-process tool call. There is
 * no second validator anywhere on this path. The bound pair is per-execution
 * state, so it is passed to {@see withNestedDispatch()} by the caller that
 * owns the live call, and the result of the run ends with a call ledger —
 * one line per bridge call, `name hint → verdict` — so the model (and the
 * person reading the transcript) sees exactly what the script did.
 *
 * Deliberately NOT wired this step (disclosed seams, plan §7 follow-up
 * list): inner calls stay dark in the live UI until the child settles
 * (an `AcceptsToolProgress` carry is the follow-up); `--restricted` does
 * not drop Script (the drop list lives in Bootstrap, this step's ownership
 * does not reach it, and Script without its jail or without bound
 * dispatch refuses anyway).
 */
#[BuiltInTool(
    name: self::NAME,
    permission: ToolPermissionClass::Write,
    position: 46,
    gloss: 'run a short PHP script whose only effects are tool calls, each permissioned like a direct call',
)]
final readonly class ScriptTool implements Tool, BuildsFromCatalog, AcceptsHeartbeat, AcceptsWorktreeJail, DispatchesNestedTools
{
    use CapturesProcessOutput;

    public const NAME = 'Script';

    /** Ceiling on the script body, matching the bridge's framing budget for one request. */
    public const MAX_CODE_BYTES = 65536;

    /** Wall-clock ceiling handed to the runner when the call sends no `timeout`. */
    public const DEFAULT_TIMEOUT_SECONDS = 60.0;

    /** Hard ceiling on a sent `timeout` — a per-PROCESS run bound, never a request timeout (E646). */
    public const MAX_TIMEOUT_SECONDS = 600.0;

    /** Rows of the call ledger kept in the result text; older rows collapse into a count note. */
    public const MAX_LEDGER_ROWS = 64;

    /** Width of the argument hint quoted per ledger row. */
    private const LEDGER_HINT_WIDTH = 72;

    /**
     * @param string|null $root the workspace the child starts in, from the build context
     * @param SandboxBackend|null $jail this tool's no-network jail, or null on a platform with none
     * @param AgentPathJail|null $worktreeJail the isolated-teammate jail, bound by the spawner
     * @param \Closure(string, array<array-key, mixed>): ToolResult|null $nestedCall per-execution binding from {@see withNestedDispatch()}
     * @param \Closure(): list<array{name: string, signature?: string}>|null $catalogRows per-execution binding from {@see withNestedDispatch()}
     */
    public function __construct(
        private ?string $root = null,
        private ?SandboxBackend $jail = null,
        private ?AgentPathJail $worktreeJail = null,
        private ?\Closure $nestedCall = null,
        private ?\Closure $catalogRows = null,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Run a short PHP script whose only effects on the world are tool calls. Inside the script, `call(\'Edit\', [...])` dispatches that tool exactly as if the model had called it directly — same permission gate, same hooks, same rules — and `tools()` lists what is dispatchable. Reads and writes land in the transcript as if made one at a time; a long mechanical sequence (rename a symbol across twenty files) costs one row here instead of twenty turns. The child runs jailed with no network access; where no sandbox can start on this host the tool refuses rather than run unconfined. Script cannot launch Task or Workflow, call Script again, or touch the context ledger; each inner call still needs its own grant, so an always-ask session keeps asking per write. The result ends with a ledger of every call the script made.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'PHP source to run (with or without the opening tag), at most ' . self::MAX_CODE_BYTES . ' bytes. Its effects are tool calls via call(); it has no other reach.',
                ],
                'timeout' => [
                    'type' => 'number',
                    'description' => 'Wall-clock seconds for the script itself, default ' . self::DEFAULT_TIMEOUT_SECONDS . ', capped at ' . self::MAX_TIMEOUT_SECONDS . '. Paused while a nested call awaits its grant.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'One short sentence, shown in the tool row and the permission prompt.',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public static function fromCatalog(ToolBuildContext $context): ?Tool
    {
        // The jail choice is NOT read from the bashSandbox setting (design
        // floor): a script's child is sandboxed for what the CHILD could do
        // off-bridge, and that answer is always "no network, nothing
        // outside the working directory". fromSetting() yields null on a
        // platform with no backend at all — the execute() arm turns that
        // into the documented refusal.
        return new self(
            root: $context->root,
            jail: SandboxFactory::fromSetting(SandboxBackend::MODE_NO_NETWORK),
        );
    }

    public function withWorktreeJail(AgentPathJail $jail): static
    {
        return new self(...array_replace(get_object_vars($this), [
            'worktreeJail' => $jail,
        ]));
    }

    public function withNestedDispatch(\Closure $call, \Closure $catalog): static
    {
        return new self(...array_replace(get_object_vars($this), [
            'nestedCall' => $call,
            'catalogRows' => $catalog,
        ]));
    }

    public function execute(array $args): ToolResult
    {
        return $this->executeWithHeartbeat($args, static function (): void {});
    }

    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        $code = $args['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            return $this->error('a non-empty `code` string is required');
        }
        if (strlen($code) > self::MAX_CODE_BYTES) {
            return $this->error(sprintf(
                'the script is %d bytes, over the %d-byte ceiling of this tool; split it or raise it by calling tools directly',
                strlen($code),
                self::MAX_CODE_BYTES,
            ));
        }

        $timeout = self::DEFAULT_TIMEOUT_SECONDS;
        if (isset($args['timeout']) && is_numeric($args['timeout'])) {
            $timeout = min(self::MAX_TIMEOUT_SECONDS, max(1.0, (float) $args['timeout']));
        }

        if ($this->nestedCall === null || $this->catalogRows === null) {
            // Defensive only: the Runtime binds every DispatchesNestedTools
            // before execution. Loud refusal, never a silently effect-less run.
            return $this->error('Script was launched without its nested dispatch binding; this is a wiring bug, not something the script did');
        }

        $chdir = $this->worktreeJail?->root() ?? $this->root ?? (string) getcwd();

        $refusal = $this->jailRefusal($chdir);
        if ($refusal !== null) {
            return $this->error($refusal);
        }

        /** @var list<array{name: string, hint: string, verdict: string}> $ledger */
        $ledger = [];
        $nestedCall = $this->nestedCall;
        $dispatch = static function (string $name, array $arguments) use ($nestedCall, &$ledger): array {
            $result = $nestedCall($name, $arguments);
            $verdict = self::ledgerVerdict($result);
            $ledger[] = ['name' => $name, 'hint' => self::ledgerHint($arguments), 'verdict' => $verdict];

            return ['content' => $result->content(), 'isError' => $result->isError()];
        };
        $catalog = $this->catalogRows;
        $catalogFn = static function () use ($catalog): array {
            return $catalog();
        };

        $run = ScriptRunner::run(
            $code,
            $chdir,
            $dispatch,
            $catalogFn,
            $this->jail,
            $timeout,
            static function () use ($heartbeat): void {
                $heartbeat();
            },
        );

        if ($run->refusal !== null) {
            return $this->error($run->refusal);
        }

        return new ToolResult(
            toolCallId: '',
            content: self::render($args, $run, $ledger),
            isError: $run->exitCode !== 0 || $run->timedOut,
        );
    }

    /**
     * Why this host cannot run scripts under a jail right now, or null when
     * it can. Mirrors Bash's discipline exactly: a backend whose binary is
     * missing, or whose once-per-process probe says it cannot start here,
     * is answered with a refusal quoting the backend's own describe() of
     * the situation — the tool never degrades to running the child
     * unconfined (§6.3).
     */
    private function jailRefusal(string $writableRoot): ?string
    {
        if ($this->jail === null) {
            return 'no sandbox backend exists for this platform, so Script refuses to run: its child would otherwise be unconfined while it can dispatch tool calls';
        }

        $probeRefusal = $this->jail->probe(function (string $probe): array {
            $result = $this->runCaptured($probe, null, 4096, 10.0);

            return ['exitCode' => $result['exitCode'], 'stderr' => $result['stderr']];
        });
        if ($probeRefusal === null) {
            return null;
        }

        return sprintf(
            'the %s jail cannot start on this host (%s); Script refuses rather than run the child unconfined. %s',
            $this->jail->name(),
            $probeRefusal,
            $this->jail->describe($writableRoot),
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private static function ledgerHint(array $arguments): string
    {
        // The first scalar value names the call's subject well enough for a
        // ledger row: command, file_path, url, pattern all ride this.
        foreach ($arguments as $value) {
            if (is_scalar($value)) {
                $hint = str_replace(["\r", "\n", "\t"], ' ', trim((string) $value));
                if (function_exists('mb_strimwidth')) {
                    return mb_strimwidth($hint, 0, self::LEDGER_HINT_WIDTH, '…');
                }

                return strlen($hint) > self::LEDGER_HINT_WIDTH
                    ? substr($hint, 0, self::LEDGER_HINT_WIDTH - 1) . '…'
                    : $hint;
            }
        }

        return '';
    }

    private static function ledgerVerdict(ToolResult $result): string
    {
        $denial = $result->denial();
        if ($denial !== null) {
            return 'denied (' . $denial->value . ')';
        }

        return $result->isError() ? 'error' : 'ok';
    }

    /**
     * @param array<string, mixed> $args
     * @param list<array{name: string, hint: string, verdict: string}> $ledger
     */
    private static function render(array $args, ScriptRun $run, array $ledger): string
    {
        $description = isset($args['description']) && is_string($args['description'])
            ? trim($args['description'])
            : '';

        $head = $description !== '' ? $description : 'script';
        $lines = [$head];
        $lines[] = sprintf(
            'exit %d%s',
            $run->exitCode,
            $run->timedOut ? ' (timed out: the script ran past its wall-clock ceiling; nested calls already settled kept their effects)' : '',
        );

        $returnJson = json_encode(
            $run->return,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        $lines[] = 'returned: ' . ($returnJson === false ? 'null' : $returnJson);

        if ($run->stdout !== '') {
            $lines[] = '--- stdout';
            foreach (explode("\n", rtrim($run->stdout, "\r\n")) as $row) {
                $lines[] = $row;
            }
        }
        if ($run->stderr !== '') {
            $lines[] = '--- stderr';
            foreach (explode("\n", rtrim($run->stderr, "\r\n")) as $row) {
                $lines[] = $row;
            }
        }
        foreach ($run->notes as $note) {
            $lines[] = 'note: ' . $note;
        }
        foreach ($run->capBreaches as $cap => $count) {
            $lines[] = sprintf('cap: %s exceeded %d time(s)', $cap, $count);
        }

        $lines[] = '--- calls';
        if ($ledger === []) {
            $lines[] = 'none';
        } else {
            $dropped = max(0, count($ledger) - self::MAX_LEDGER_ROWS);
            foreach (array_slice($ledger, $dropped) as $row) {
                $hint = $row['hint'] !== '' ? ' ' . $row['hint'] : '';
                $lines[] = $row['name'] . $hint . ' -> ' . $row['verdict'];
            }
            if ($dropped > 0) {
                $lines[] = sprintf('(+%d earlier calls not shown)', $dropped);
            }
        }

        return implode("\n", $lines);
    }

    private function error(string $message): ToolResult
    {
        return new ToolResult(
            toolCallId: '',
            content: 'Error: Script did not run — ' . $message,
            isError: true,
        );
    }
}

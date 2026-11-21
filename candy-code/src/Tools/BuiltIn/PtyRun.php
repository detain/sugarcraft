<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\AnsiParser\Codec\KeySequence;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\PtyBackend;
use SugarCraft\Code\Support\WindowsShell;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;
use SugarCraft\Code\Tools\Sandbox\SandboxFactory;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\AcceptsToolProgress;
use SugarCraft\Code\Tools\AcceptsWorktreeJail;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Code\Tools\ShellTailRing;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Expect;

/**
 * Runs a terminal program on a real pseudo-terminal and DRIVES it: each step
 * sends keystrokes and reads the screen bytes back, with an optional
 * expectation that fails loudly when the frame does not change the way the
 * caller predicted. This is how a model tests TUI software — including, in
 * principle, a second candy-code session painted inside this one — with real
 * input, real output, and the same size a human terminal would report.
 *
 * WHY A TOOL RATHER THAN A WIDER Bash `interactive` FLAG: the interactive
 * Bash branch answers "what did it paint before going quiet" and can never
 * receive input (its own refusal message says so). PtyRun answers "what does
 * the screen say AFTER I press this", needs per-step result rows, and carries
 * its own geometry/budget surface; bolting that onto Bash would rewrite the
 * merged stdout/stderr shape every existing pin depends on. The session
 * engine is {@see runSession()}: it spawns here and reads every step through
 * sugar-pty's {@see Expect::collectWindow()}; folding Bash's drain onto that
 * window is the recorded follow-up.
 *
 * SAFETY mirrors Bash-interactive and then some: the program runs under
 * ProcessContainment's fail-fast env (no prompt will ever wait on a password
 * this tool's sends did not type), the whole session dies at a hard wall
 * deadline, output is byte-budgeted across ALL steps together, sends are
 * size-refused before anything spawns, and the child leaves through the
 * reaper's TERM→KILL ladder on every exit path — including a mid-script
 * throw. The sandbox (roadmap 5.12) applies here too (ISS-002): a configured
 * `bashSandbox` prefixes the bubblewrap argv onto the spawn — the interactive
 * argv form drops `--new-session` so the pty stays controlling, and a jail
 * that cannot start REFUSES the session with nothing run, exactly as under
 * Bash. Before this the pty path ran every command unconfined while Bash's
 * honoured the setting.
 */
#[BuiltInTool(
    name: 'PtyRun',
    permission: ToolPermissionClass::Write,
    position: 35,
    gloss: 'runs a terminal program on a real pseudo-terminal and drives it — each step types keystrokes and captures the screen bytes that follow, and an expectation fails loudly when the frame does not change as predicted',
)]
final readonly class PtyRun implements Tool, AcceptsWorktreeJail, AcceptsHeartbeat, BuildsFromCatalog, TakesToolCallId, AcceptsToolProgress
{
    use RebindsWorktreeJail;

    public const DEFAULT_TIMEOUT_SECONDS = 30;

    public const MAX_TIMEOUT_SECONDS = 21600;

    public const DEFAULT_MAX_OUTPUT_BYTES = 65536;

    /** The ceiling on `maxBytes`: a driven program repaints fast; 1 MiB of screen is already far past any useful transcript. */
    public const MAX_CAPTURE_BYTES = 1048576;

    public const MIN_COLS = 2;

    public const MAX_COLS = 512;

    public const MIN_ROWS = 2;

    public const MAX_ROWS = 256;

    public const MAX_STEPS = 64;

    public const MAX_SEND_BYTES_PER_STEP = 65536;

    public const MAX_SEND_BYTES_TOTAL = 262144;

    public const MAX_STEP_WAIT_MS = 30000;

    public const DEFAULT_STEP_WAIT_MS = 1000;

    public const DEFAULT_EXPECT_TIMEOUT_MS = 5000;

    /**
     * Named key tokens for `send`, decoded by {@see decode()}. Values are the
     * REAL wire bytes a keyboard produces — arrows are `ESC [ C` etc., never a
     * glyph — because a driven program only believes what a terminal sends
     * (the repo's Python-PTY-harness audit lesson: literal `→` bytes ride the
     * paste/IME path and exercise a completely different parser).
     *
     * S-T11 (R8): the table is the family's single encode table,
     * {@see KeySequence::TABLE} in sugar-ansi-parser; this alias keeps the
     * `PtyRun::KEY_TOKENS` vocabulary the shells protocol and tests spell.
     */
    public const KEY_TOKENS = KeySequence::TABLE;

    /** Backslash escapes understood inside `send`. */
    private const CHAR_ESCAPES = [
        'e' => "\x1b",
        'r' => "\r",
        'n' => "\n",
        't' => "\t",
        '\\' => '\\',
        '<' => '<',
    ];

    /**
     * Fixed one-second courtesy between "the script ended" and "we call it
     * still running" — see {@see teardownSession()} for the race it settles.
     */
    private const EXIT_GRACE_SECONDS = 1.0;

    /**
     * The teardown drain's own window. With the child gone, the window's
     * post-exit drain answers after two quiet reads; with a child the ladder
     * could not confirm gone, it is all the drain waits.
     */
    private const TEARDOWN_DRAIN_WINDOW_SECONDS = 0.1;

    /**
     * Hard ceiling on the teardown drain. It runs after the wall deadline may
     * already have passed, so it carries its own bound rather than the wall's.
     */
    private const TEARDOWN_DRAIN_CEILING_SECONDS = 2.0;

    public function __construct(
        private ?string $root = null,
        private int $defaultTimeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private int $maxTimeoutSeconds = self::MAX_TIMEOUT_SECONDS,
        private ?\SugarCraft\Code\Agents\PathJail $worktreeJail = null,
        private ?SandboxBackend $sandbox = null,
        private ?\Closure $toolProgressEmitter = null,
    ) {
        // The wall ceiling is what the turn waits on. Same purpose as Bash's —
        // so no single call can hold the loop forever — and it shares Bash's
        // ceiling (hour-scale drives are legitimate); what stays deliberately
        // tighter than Bash is the DEFAULT (30 s against Bash's 120 s): an
        // interactive driver is meant to nudge a program step-by-step, and a
        // session that needs longer says so through `timeout`.
        if ($this->defaultTimeoutSeconds < 1 || $this->defaultTimeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException(sprintf('default timeout must be between 1 and %d seconds', self::MAX_TIMEOUT_SECONDS));
        }
        if ($this->maxTimeoutSeconds < 1 || $this->maxTimeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException(sprintf('maximum timeout must be between 1 and %d seconds', self::MAX_TIMEOUT_SECONDS));
        }
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        // ISS-002: the pty honours the same `bashSandbox` setting as Bash, read
        // through this catalog entry exactly like Bash does — a jail decided
        // anywhere else is a jail one of the two shell tools skips.
        return new self(
            $context->root,
            sandbox: SandboxFactory::fromSetting(Bootstrap::readUserConfig()['bashSandbox'] ?? null),
        );
    }

    /**
     * This tool with its sandbox replaced (null runs unconfined), every other
     * knob carried over — the twin of {@see Bash::withSandbox()}.
     */
    public function withSandbox(?SandboxBackend $sandbox): self
    {
        return new self(...array_replace(get_object_vars($this), ['sandbox' => $sandbox]));
    }

    public function sandbox(): ?SandboxBackend
    {
        return $this->sandbox;
    }

    /**
     * C-TOOL-3.a: the turn's ToolProgress channel, bound exactly as Bash's is
     * (EngineBackend::turnTools, pid-gated closure). The ring is built per
     * drive() only when this AND a call id are present, so an unbound or
     * anonymous call runs the identical path it ran before.
     */
    public function withToolProgressEmitter(\Closure $emit): self
    {
        return new self(...array_replace(get_object_vars($this), ['toolProgressEmitter' => $emit]));
    }

    /** The directory the jail leaves writable — same rule as Bash's. */
    private function writableRoot(): string
    {
        return $this->worktreeJail?->root() ?? $this->root ?? (string) \getcwd();
    }

    public function name(): string
    {
        return 'PtyRun';
    }

    public function description(): string
    {
        return 'Runs a terminal program on a real pseudo-terminal and drives it interactively: each step sends keystrokes and captures the screen bytes that follow, with an optional expectation that fails loudly when the frame does not change as predicted. Use it to exercise TUI software — menus, prompts, editors, a second agent session — the way a keyboard user would. The terminal has no human at it and no password is ever accepted: a program that blocks waiting for input the plan never sends is terminated at the wall deadline, with everything it painted returned as the transcript.'
            . ($this->sandbox === null ? '' : ' ' . $this->sandbox->describe($this->writableRoot()));
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'Shell command (run via /bin/sh -c) that starts the terminal program to drive.'],
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear, concise 5-10 word description in active voice of what this session tests or does.',
                ],
                'cols' => [
                    'type' => 'integer',
                    'description' => sprintf('Terminal width in columns the program is born with (default 80, %d..%d, clamped). Applied BEFORE the first draw, so the program sees a real size.', self::MIN_COLS, self::MAX_COLS),
                    'minimum' => self::MIN_COLS,
                    'maximum' => self::MAX_COLS,
                ],
                'rows' => [
                    'type' => 'integer',
                    'description' => sprintf('Terminal height in rows (default 24, %d..%d, clamped).', self::MIN_ROWS, self::MAX_ROWS),
                    'minimum' => self::MIN_ROWS,
                    'maximum' => self::MAX_ROWS,
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => sprintf('Hard wall-clock ceiling for the WHOLE session in seconds. Default %d, maximum %d; larger values are clamped. Every step window is additionally capped by what remains of it.', $this->defaultTimeoutSeconds, $this->maxTimeoutSeconds),
                    'minimum' => 1,
                    'maximum' => $this->maxTimeoutSeconds,
                ],
                'maxBytes' => [
                    'type' => 'integer',
                    'description' => sprintf('Total screen bytes kept across all steps (default %d, capped at %d). Bytes past the budget are dropped and counted in a marker; the program still runs.', self::DEFAULT_MAX_OUTPUT_BYTES, self::MAX_CAPTURE_BYTES),
                    'minimum' => 1,
                    'maximum' => self::MAX_CAPTURE_BYTES,
                ],
                'harvest' => [
                    'type' => 'string',
                    'enum' => ['transcript', 'screen'],
                    'description' => 'transcript (default) returns every step segment; screen returns only the final frame the program painted, for "just show me the end state" reads.',
                ],
                'steps' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_STEPS,
                    'description' => sprintf('The driving script, executed in order (max %d). Each step needs at least one of send/wait/expect. Omit steps to just run the program and collect its paint until it exits or the wall fires.', self::MAX_STEPS),
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'send' => [
                                'type' => 'string',
                                'description' => 'Bytes to type at the program. Named keys in angle brackets — <Enter> <Esc> <Tab> <Backspace> <Up> <Down> <Left> <Right> <Home> <End> <PageUp> <PageDown> <Delete> <Insert> <C-x> — decode to the real terminal sequences; backslash escapes \\xHH \\e \\r \\n \\t \\\\ \\< also decode; anything unrecognised stays literal. Max ' . self::MAX_SEND_BYTES_PER_STEP . ' bytes per step, ' . self::MAX_SEND_BYTES_TOTAL . ' total.',
                            ],
                            'wait' => [
                                'type' => 'integer',
                                'description' => sprintf('Milliseconds to collect screen bytes after sending (default %d, max %d). Ignored when expect is set, which uses timeout instead.', self::DEFAULT_STEP_WAIT_MS, self::MAX_STEP_WAIT_MS),
                                'minimum' => 0,
                                'maximum' => self::MAX_STEP_WAIT_MS,
                            ],
                            'expect' => [
                                'type' => 'string',
                                'description' => 'Substring the step must see in the bytes captured since it opened; wrap in slashes (/pattern/i) for a regular expression. A miss does not abort the script — it marks the step failed, makes the result an error, and the next step still runs, so you can probe several states per session.',
                            ],
                            'timeout' => [
                                'type' => 'integer',
                                'description' => sprintf('Milliseconds this step waits for its expect before declaring a miss (default %d, max %d).', self::DEFAULT_EXPECT_TIMEOUT_MS, self::MAX_STEP_WAIT_MS),
                                'minimum' => 1,
                                'maximum' => self::MAX_STEP_WAIT_MS,
                            ],
                        ],
                    ],
                ],
            ],
            'required' => ['command', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        return $this->drive($args, null);
    }

    /**
     * {@see execute()} with the turn's liveness beat fired from every read
     * pass, so a session allowed its full `timeout` is not killed first by
     * the turn's idle watchdog (same contract Bash honours).
     */
    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        return $this->drive($args, $heartbeat);
    }

    /**
     * Decode a `send` template into the bytes to write. Public for its unit
     * pins and for the Bash-interactive fold, which will share this grammar.
     */
    public static function decode(string $template): string
    {
        $out = '';
        $length = \strlen($template);

        for ($i = 0; $i < $length; $i++) {
            $char = $template[$i];

            if ($char === '<') {
                $close = \strpos($template, '>', $i + 1);
                if ($close !== false) {
                    $name = \substr($template, $i + 1, $close - $i - 1);
                    $keyBytes = KeySequence::encode($name);
                    if ($keyBytes !== null) {
                        $out .= $keyBytes;
                        $i = $close;

                        continue;
                    }
                }
                // Unknown or unterminated <token>: literal text, by contract.
                $out .= $char;

                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $next = $template[$i + 1];

                if ($next === 'x' && $i + 3 < $length && \ctype_xdigit($template[$i + 2]) && \ctype_xdigit($template[$i + 3])) {
                    $out .= \chr((int) \hexdec(\substr($template, $i + 2, 2)));
                    $i += 3;

                    continue;
                }

                if (isset(self::CHAR_ESCAPES[$next])) {
                    $out .= self::CHAR_ESCAPES[$next];
                    ++$i;

                    continue;
                }

                // Unknown escape: the backslash stays, the next char is
                // reconsidered on its own — refusing silently here would eat
                // legitimate Windows paths and regex-ish payloads.
                $out .= $char;

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function drive(array $args, ?\Closure $heartbeat): ToolResult
    {
        $toolCallId = \is_string($args['id'] ?? null) ? $args['id'] : '';

        $command = $args['command'] ?? null;
        if (!\is_string($command) || \trim($command) === '') {
            return $this->refusal($toolCallId, 'pty refused: `command` must be a non-empty string; nothing was started.');
        }

        $cols = self::clampedInt($args['cols'] ?? null, 80, self::MIN_COLS, self::MAX_COLS);
        $rows = self::clampedInt($args['rows'] ?? null, 24, self::MIN_ROWS, self::MAX_ROWS);
        $timeout = self::clampedInt($args['timeout'] ?? null, $this->defaultTimeoutSeconds, 1, $this->maxTimeoutSeconds);
        $maxBytes = self::clampedInt($args['maxBytes'] ?? null, self::DEFAULT_MAX_OUTPUT_BYTES, 1, self::MAX_CAPTURE_BYTES);
        $harvest = \is_string($args['harvest'] ?? null) && $args['harvest'] === 'screen' ? 'screen' : 'transcript';

        $parsed = $this->parseSteps($args['steps'] ?? []);
        if (\is_string($parsed)) {
            // Fail fast BEFORE spawning: a malformed plan costs a process,
            // and the model fixes it faster when nothing ran.
            return $this->refusal($toolCallId, 'pty refused: ' . $parsed . '; nothing was started.');
        }

        $cwd = $this->worktreeJail?->root() ?? $this->root;

        // ISS-002: the same jail contract Bash enforces. The probe runs the
        // wrapped /bin/true through the pty itself (no raw spawn: the
        // child-lifetime census owns every other way a process is born here),
        // and a jail that cannot start refuses the whole session — running
        // unconfined because the jail failed is the promise this tool does
        // not make. The frame the failed jail paints stands in for stderr.
        if ($this->sandbox !== null) {
            $refusal = $this->sandbox->probe(function (string $probe): array {
                $verdict = self::runSession(
                    command: $probe,
                    cwd: null,
                    cols: 80,
                    rows: 24,
                    wallSeconds: 10.0,
                    maxBytes: 4096,
                    steps: [],
                );

                return [
                    'exitCode' => $verdict['refusal'] === null ? $verdict['exitCode'] : 1,
                    'stderr' => (string) ($verdict['steps'][0]['output'] ?? ''),
                ];
            });
            if ($refusal !== null) {
                return $this->refusal($toolCallId, sprintf(
                    'Not run: the bashSandbox setting is "%s" but the %s sandbox cannot start on this host (%s). '
                    . 'Nothing ran, sandboxed or otherwise. Tell the user: they can fix %s (%s) '
                    . 'or set bashSandbox to "off".',
                    $this->sandbox->mode(),
                    $this->sandbox->name(),
                    $refusal,
                    $this->sandbox->name(),
                    $this->sandbox->refusalRemedy(),
                ));
            }
        }

        // C-TOOL-3.a: live tail frames on Bash's exact ring contract. The
        // budgets are Bash's consts on purpose — one shared shell-tail size
        // for every streaming tool, so a row resizes identically either way.
        $progress = $this->toolProgressEmitter !== null && $toolCallId !== ''
            ? new ShellTailRing(
                $toolCallId,
                $this->name(),
                $this->toolProgressEmitter,
                Bash::PROGRESS_TAIL_BYTES,
                Bash::PROGRESS_FRAME_INTERVAL_SECONDS,
            )
            : null;
        $onChunk = $progress === null ? null : static function (string $chunk) use ($progress): void {
            // Pty bytes are screen-shaped (cursor moves, colours); the tail is
            // the best-effort PLAIN text of what the program is showing.
            $progress->onChunk(\SugarCraft\Core\Util\Ansi::strip($chunk));
        };

        $run = self::runSession(
            command: $command,
            cwd: $cwd,
            cols: $cols,
            rows: $rows,
            wallSeconds: (float) $timeout,
            maxBytes: $maxBytes,
            steps: $parsed,
            heartbeat: $heartbeat,
            onChunk: $onChunk,
            spawnArgvPrefix: $this->sandbox?->argv($this->writableRoot(), interactive: true) ?? [],
        );

        $progress?->finish();

        if ($run['refusal'] !== null) {
            return $this->refusal($toolCallId, $run['refusal']);
        }

        return $this->render($toolCallId, $cols, $rows, $run, $harvest);
    }

    /**
     * Run one scripted PTY session: spawn the program on a pseudo-terminal of
     * the requested size, drive it with the parsed sends and read windows, and
     * always leave through the TERM→KILL ladder.
     *
     * THE HANG BUDGET IS THE DESIGN. Every read window is clamped to the
     * smaller of the step window and the remaining wall deadline (sugar-pty's
     * {@see Expect::collectWindow()} owns that clamp); `$child->wait()` — an
     * unbounded poll in sugar-pty — is called ONLY when
     * {@see BoundedShutdown::escalate()} has reported the child gone, so a
     * child that traps SIGTERM cannot turn a bounded refusal back into a hang.
     * Teardown fires on every exit path, including expect-timeout and
     * mid-script exceptions.
     *
     * THE TRANSCRIPT IS A BYTE PREFIX. The byte budget is shared across the
     * whole session: the running `retained` and `dropped` totals are threaded
     * from window to window. Once the cap has refused a byte (including the
     * bytes a codepoint back-off gave up), every later byte is refused, in that
     * step and in every later one, so nothing is spliced in after the gap.
     *
     * $steps is the tool-boundary-PARSED plan (never raw model input): each
     * entry `{send: string, windowSeconds: float, regex: ?string}` where a
     * non-null `$regex` is a compiled-true PCRE matched against the bytes
     * retained SINCE this step's window opened, and a null one just collects
     * for `windowSeconds`. Every step yields one result row, aligned with the
     * plan, even when the program exited or the wall fired before it ran
     * (`skipped`).
     *
     * @param list<array{send: string, windowSeconds: float, regex: ?string}> $steps
     * @param (\Closure(): void)|null $heartbeat fired once per read pass
     * @param (\Closure(string): void)|null $onChunk raw bytes per read window (C-TOOL-3.a live tail);
     *     best-effort — fed inside every step window, not the teardown drain
     * @param list<string> $spawnArgvPrefix jail argv (ISS-002) prefixed onto the spawn argv
     * @return array{
     *     refusal: ?string,
     *     pid: int,
     *     steps: list<array{output: string, matched: ?bool, note: string}>,
     *     retained: int,
     *     dropped: int,
     *     exitCode: ?int,
     *     exited: bool,
     *     killed: bool,
     * }
     */
    private static function runSession(
        string $command,
        ?string $cwd,
        int $cols,
        int $rows,
        float $wallSeconds,
        int $maxBytes,
        array $steps,
        ?\Closure $heartbeat = null,
        ?\Closure $onChunk = null,
        array $spawnArgvPrefix = [],
    ): array {
        $spawned = self::spawnSession($command, $cwd, $cols, $rows, $spawnArgvPrefix);
        if (\is_string($spawned)) {
            return self::sessionResult(refusal: $spawned, pid: -1, ran: [], retained: 0, dropped: 0, exitCode: null, exited: false, killed: false);
        }

        [$master, $child] = $spawned;

        // An empty plan is still a session: collect until the program exits or
        // the wall fires — the sized, safe shape of the old drain.
        $plan = $steps === []
            ? [['send' => '', 'windowSeconds' => $wallSeconds, 'regex' => null]]
            : $steps;

        /** @var list<array{output: string, matched: ?bool, note: string}> $ran */
        $ran = [];
        $retained = 0;
        $dropped = 0;
        $deadline = \microtime(true) + $wallSeconds;
        $childExited = false;
        $expect = Expect::on($master);
        $killed = false;

        try {
            foreach ($plan as $step) {
                if ($childExited) {
                    $ran[] = ['output' => '', 'matched' => null, 'note' => 'skipped (program exited)'];

                    continue;
                }

                if ($step['send'] !== '') {
                    try {
                        $master->write($step['send']);
                    } catch (\Throwable $writeFailure) {
                        // A dead master (program raced us to exit) must not
                        // abort the script: note it and let the windows below
                        // observe the exit through their normal path.
                        $ran[] = ['output' => '', 'matched' => $step['regex'] === null ? null : false, 'note' => 'send failed: ' . $writeFailure->getMessage()];

                        $childExited = $child->exited();

                        continue;
                    }
                }

                $window = $expect->collectWindow(
                    windowSeconds: $step['windowSeconds'],
                    deadline: $deadline,
                    maxBytes: $maxBytes,
                    retained: $retained,
                    dropped: $dropped,
                    regex: $step['regex'],
                    child: $child,
                    heartbeat: $heartbeat,
                    onChunk: $onChunk,
                );
                $expect = $window->next ?? $expect;
                $retained = $window->retained;
                $dropped = $window->dropped;
                $childExited = $window->childExited;

                $note = $window->wallHit ? 'wall deadline hit mid-step' : '';
                if ($window->matched === false) {
                    $note = 'no-match within ' . \round($step['windowSeconds'] * 1000) . 'ms';
                }
                $ran[] = ['output' => $window->output, 'matched' => $window->matched, 'note' => $note];

                if ($window->wallHit) {
                    // Pad the untouched tail so the result rows stay aligned
                    // with the plan the tool reported to the model.
                    foreach (\array_slice($plan, \count($ran)) as $unused) {
                        $ran[] = ['output' => '', 'matched' => null, 'note' => 'skipped (wall deadline)'];
                    }

                    break;
                }
            }
        } finally {
            // Teardown runs on EVERY path out of the loop — normal finish,
            // wall hit, or a throw from inside a step.
            [$killed, $tail, $retained, $dropped] = self::teardownSession($master, $child, $expect, $heartbeat, $maxBytes, $retained, $dropped);
            // The final frame rides the answer even when the exit beat the
            // last read; it belongs to the step that was open when the
            // program went.
            if ($tail !== '') {
                $lastIndex = \max(0, \count($ran) - 1);
                $ran[$lastIndex]['output'] = ($ran[$lastIndex]['output'] ?? '') . $tail;
            }
        }

        return self::sessionResult(
            refusal: null,
            pid: $child->pid(),
            ran: $ran,
            retained: $retained,
            dropped: $dropped,
            exitCode: $killed ? 124 : $child->exitCode(),
            exited: $child->exited(),
            killed: $killed,
        );
    }

    /**
     * Refuse or spawn: the availability gates first, then the master opened
     * and the child spawned at the requested size, so the master's winsize is
     * set before the child's first draw (`spawn()` applies it before forking
     * and re-asserts it best-effort after, the Darwin zeroing quirk) and a
     * TUI's first frame already sees the requested geometry.
     *
     * @param list<string> $spawnArgvPrefix
     * @return string|array{0: MasterPty, 1: Child} the refusal sentence, or the live pair
     */
    private static function spawnSession(string $command, ?string $cwd, int $cols, int $rows, array $spawnArgvPrefix): string|array
    {
        if (!ProcessContainment::interactiveAvailable()) {
            return 'pty mechanism unavailable on this host (no pty); the program was refused and never started';
        }

        if (ProcessContainment::interactiveBashMissing()) {
            // ConPTY is fine here; the plan it would carry is not. §6.3: name
            // the install that fixes it, never a cmd.exe substitute.
            return WindowsShell::BASH_MISSING_NOTICE;
        }

        $master = null;
        try {
            // The backend seam asks the platform factory, so this same run
            // loop speaks to a ConPTY master on Windows and the canonical
            // POSIX master everywhere else (C-TUI-6.d).
            $pair = PtyBackend::open($cols, $rows);
            $master = $pair->master();
            $child = $pair->slave()->spawn(
                ProcessContainment::interactiveSpawnCommand($command, $cwd, $spawnArgvPrefix),
                // Scrubbed for the same reason as every other capture path:
                // the pty transcript is the model's to read (audit F-E1), and
                // NONINTERACTIVE_ENV's fail-fast prompts keep a driven program
                // from ever blocking on a password this terminal will not type.
                ProcessContainment::scrubbedEnv(),
                $cols,
                $rows,
                // Not a hard-coded true: ConPTY has no ctty and refuses the
                // request outright, so the capability answers (C-TUI-6.d's
                // other half — the seam that picked ConPTY must also decide
                // whether the ctty wrapper applies). POSIX stays true.
                PtyBackend::honoursControllingTerminal(),
            );
        } catch (\Throwable $failure) {
            // spawn() throwing AFTER open() succeeded would otherwise strand the
            // master handle — the teardown belongs to the run loop, which this
            // path never reaches. open() itself throwing leaves $master null,
            // and the systems close their own partials on a failed open.
            if ($master !== null) {
                try {
                    $master->close();
                } catch (\Throwable) {
                    // Nothing to escalate a failure to; the refusal is the answer.
                }
            }

            return 'pty refused: the pseudo-terminal could not be allocated (' . $failure->getMessage() . ')';
        }

        return [$master, $child];
    }

    /**
     * Leave the session: a bounded exit grace, then the TERM→KILL ladder for a
     * child still running, then one last bounded drain through the same Expect
     * window (and the same threaded byte totals, so the byte-prefix rule holds
     * for the tail too), and the master closed whatever the drain does.
     *
     * @return array{0: bool, 1: string, 2: int, 3: int} killed, tail, retained, dropped
     */
    private static function teardownSession(MasterPty $master, Child $child, Expect $expect, ?\Closure $heartbeat, int $maxBytes, int $retained, int $dropped): array
    {
        $killed = false;
        $tail = '';

        try {
            // A clean exit races its own last frame: the `BYE` a program
            // prints fires the final match a hair BEFORE its exit(0), and the
            // /bin/sh wrapper needs a beat more to follow its exec'd child.
            // One bounded grace poll (never a hang — it is fixed at a second
            // regardless of the plan) keeps "answered and left" from being
            // misreported as "terminated by us". A child already gone skips it.
            $graceEnd = \microtime(true) + self::EXIT_GRACE_SECONDS;
            while (!$child->exited() && \microtime(true) < $graceEnd) {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                \usleep(10000);
            }

            if (!$child->exited()) {
                $killed = true;
                $gone = BoundedShutdown::escalate(
                    static function (int $signal) use ($child): void {
                        ProcessContainment::terminatePid($child->pid(), $signal);
                    },
                    static fn (): bool => $child->exited(),
                );
                if ($gone) {
                    $child->wait();
                }
            }

            $drain = $expect->collectWindow(
                windowSeconds: self::TEARDOWN_DRAIN_WINDOW_SECONDS,
                deadline: \microtime(true) + self::TEARDOWN_DRAIN_CEILING_SECONDS,
                maxBytes: $maxBytes,
                retained: $retained,
                dropped: $dropped,
                child: $child,
            );
            $tail = $drain->output;
            $retained = $drain->retained;
            $dropped = $drain->dropped;
        } finally {
            $master->close();
        }

        return [$killed, $tail, $retained, $dropped];
    }

    /**
     * @param list<array{output: string, matched: ?bool, note: string}> $ran
     * @return array{refusal: ?string, pid: int, steps: list<array{output: string, matched: ?bool, note: string}>, retained: int, dropped: int, exitCode: ?int, exited: bool, killed: bool}
     */
    private static function sessionResult(?string $refusal, int $pid, array $ran, int $retained, int $dropped, ?int $exitCode, bool $exited, bool $killed): array
    {
        return [
            'refusal' => $refusal,
            'pid' => $pid,
            'steps' => $ran,
            'retained' => $retained,
            'dropped' => $dropped,
            'exitCode' => $exitCode,
            'exited' => $exited,
            'killed' => $killed,
        ];
    }

    /**
     * Boundary parse of the model's step plan into the engine's trusted shape.
     * Returns an error sentence (string) or the parsed list.
     *
     * @return string|list<array{send: string, windowSeconds: float, regex: ?string}>
     */
    private function parseSteps(mixed $steps): string|array
    {
        if ($steps === null || $steps === '' || $steps === []) {
            return [];
        }

        if (!\is_array($steps) || !array_is_list($steps)) {
            return '`steps` must be an array of step objects.';
        }

        if (\count($steps) > self::MAX_STEPS) {
            return sprintf('`steps` carries %d entries; the ceiling is %d.', \count($steps), self::MAX_STEPS);
        }

        $parsed = [];
        $totalSend = 0;

        foreach ($steps as $index => $step) {
            $label = 'step ' . ($index + 1);

            if (!\is_array($step)) {
                return "{$label} must be an object with send/wait/expect keys.";
            }

            $unknown = \array_diff(\array_keys($step), ['send', 'wait', 'expect', 'timeout']);
            if ($unknown !== []) {
                return "{$label} has unknown key(s): " . \implode(', ', $unknown) . '.';
            }

            $send = $step['send'] ?? '';
            if (!\is_string($send)) {
                return "{$label} `send` must be a string.";
            }

            $hasExpect = \array_key_exists('expect', $step) && $step['expect'] !== null && $step['expect'] !== '';
            $hasWait = \array_key_exists('wait', $step) && $step['wait'] !== null;

            if ($send === '' && !$hasExpect && !$hasWait) {
                return "{$label} needs at least one of send/wait/expect.";
            }

            if (\strlen($send) > self::MAX_SEND_BYTES_PER_STEP) {
                return sprintf('%s `send` is %d bytes; the per-step ceiling is %d.', $label, \strlen($send), self::MAX_SEND_BYTES_PER_STEP);
            }

            $totalSend += \strlen($send);
            if ($totalSend > self::MAX_SEND_BYTES_TOTAL) {
                return sprintf('`steps` send total exceeds %d bytes at %s.', self::MAX_SEND_BYTES_TOTAL, $label);
            }

            $regex = null;
            $windowMs = self::DEFAULT_STEP_WAIT_MS;

            if ($hasExpect) {
                $expect = $step['expect'];
                if (!\is_string($expect)) {
                    return "{$label} `expect` must be a string.";
                }

                $regex = self::compileExpect($expect);
                if ($regex === null) {
                    return "{$label} `expect` is not usable: {$expect} (slash-wrapped values must be a valid /pattern/flags regular expression).";
                }

                $windowMs = self::clampedInt($step['timeout'] ?? self::DEFAULT_EXPECT_TIMEOUT_MS, self::DEFAULT_EXPECT_TIMEOUT_MS, 1, self::MAX_STEP_WAIT_MS);
            } elseif (\array_key_exists('timeout', $step)) {
                return "{$label} sets `timeout` without `expect`; timeout bounds an expectation (use wait to just collect).";
            }

            if ($hasWait) {
                if (!\is_int($step['wait']) || $step['wait'] < 0 || $step['wait'] > self::MAX_STEP_WAIT_MS) {
                    return sprintf('%s `wait` must be an integer number of milliseconds between 0 and %d.', $label, self::MAX_STEP_WAIT_MS);
                }

                $windowMs = $step['wait'];
            }

            $parsed[] = ['send' => self::decode($send), 'windowSeconds' => $windowMs / 1000.0, 'regex' => $regex];
        }

        return $parsed;
    }

    /**
     * `/pattern/flags` is regex, anything else is a literal substring — the
     * delimiter must CLOSE the pattern with only flag letters behind it, so a
     * plain path like /home/x stays a string to look for. A pattern that does
     * not compile is a refusal (the engine may never see `false` from a
     * preg_match it trusts).
     */
    private static function compileExpect(string $expect): ?string
    {
        if (\preg_match('#^/(.+)/([imsxuADSUXJ]*)$#s', $expect, $parts) === 1) {
            $candidate = '/' . $parts[1] . '/' . $parts[2];
            if (@\preg_match($candidate, '') === false) {
                return null;
            }

            return $candidate;
        }

        return '/' . \preg_quote($expect, '/') . '/s';
    }

    private static function clampedInt(mixed $value, int $default, int $min, int $max): int
    {
        if (\is_string($value) && \ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!\is_int($value)) {
            return $default;
        }

        return \max($min, \min($max, $value));
    }

    private function refusal(string $toolCallId, string $message): ToolResult
    {
        return new ToolResult(toolCallId: $toolCallId, content: $message, isError: true);
    }

    /**
     * @param array{pid:int, steps:list<array{output:string,matched:?bool,note:string}>, retained:int, dropped:int, exitCode:?int, exited:bool, killed:bool} $run
     */
    private function render(string $toolCallId, int $cols, int $rows, array $run, string $harvest): ToolResult
    {
        $status = match (true) {
            // Killed and reaped: the ladder fired and the child's death landed
            // before the result was built. Reading `exited` first would hide the
            // death, so killed wins — but only claim "reaped" when the reap
            // actually happened.
            $run['killed'] && $run['exited'] => 'still running at the wall deadline: terminated and reaped via TERM/KILL (code 124)',
            // Ladder fired, child not yet confirmed gone: don't overclaim a reap
            // that the late-reaper may still be armed for.
            $run['killed'] => 'still running at the wall deadline: terminated via TERM/KILL, not yet reaped (late reap armed)',
            $run['exited'] => 'exited code=' . ($run['exitCode'] ?? '?'),
            default => 'detached (uninterruptible child, late reap armed)',
        };

        $lines = [sprintf(
            '[pty %dx%d pid=%d %s · %dB captured%s]',
            $cols,
            $rows,
            $run['pid'],
            $status,
            $run['retained'],
            $run['dropped'] > 0 ? sprintf(' · %dB dropped past maxBytes', $run['dropped']) : '',
        )];

        $failed = false;
        $segments = [];

        foreach ($run['steps'] as $index => $step) {
            if ($step['matched'] === true) {
                $verdict = 'matched';
            } elseif ($step['matched'] === false) {
                $failed = true;
                $verdict = 'NO-MATCH';
            } elseif (\str_starts_with($step['note'], 'skipped')) {
                $verdict = 'skipped';
            } else {
                $verdict = 'collected';
            }

            $segments[] = $step['output'];

            $header = sprintf('── step %d (%s%s) ──', $index + 1, $verdict, $step['note'] !== '' ? ', ' . $step['note'] : '');
            $lines[] = $header;
            $lines[] = $step['output'] === '' ? '(no output)' : $step['output'];
        }

        if ($harvest === 'screen') {
            // The FINAL frame the program painted — for "what is on screen
            // now" reads where the history is noise. Falls back to the whole
            // capture when every step stayed empty.
            $screen = '';
            foreach ($segments as $segment) {
                if ($segment !== '') {
                    $screen = $segment;
                }
            }

            $lines = [$lines[0], $screen === '' ? '(no output)' : $screen];
        }

        $content = \rtrim(\implode("\n", $lines), "\r\n");

        return new ToolResult(
            toolCallId: $toolCallId,
            content: $content,
            isError: $failed || $run['killed'] || !$run['exited'] || ($run['exitCode'] ?? 0) !== 0,
        );
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\PtyDriver;
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
 * merged stdout/stderr shape every existing pin depends on. The shared engine
 * is {@see PtyDriver}; folding Bash's drain onto it is the recorded follow-up.
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

    public const MAX_TIMEOUT_SECONDS = 300;

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
     */
    public const KEY_TOKENS = [
        'Enter' => "\r",
        'Esc' => "\x1b",
        'Tab' => "\t",
        'Backspace' => "\x7f",
        'Up' => "\x1b[A",
        'Down' => "\x1b[B",
        'Right' => "\x1b[C",
        'Left' => "\x1b[D",
        'Home' => "\x1b[H",
        'End' => "\x1b[F",
        'PageUp' => "\x1b[5~",
        'PageDown' => "\x1b[6~",
        'Delete' => "\x1b[3~",
        'Insert' => "\x1b[2~",
        'C-a' => "\x01",
        'C-b' => "\x02",
        'C-c' => "\x03",
        'C-d' => "\x04",
        'C-e' => "\x05",
        'C-f' => "\x06",
        'C-g' => "\x07",
        'C-h' => "\x08",
        'C-i' => "\x09",
        'C-j' => "\x0a",
        'C-k' => "\x0b",
        'C-l' => "\x0c",
        'C-m' => "\x0d",
        'C-n' => "\x0e",
        'C-o' => "\x0f",
        'C-p' => "\x10",
        'C-q' => "\x11",
        'C-r' => "\x12",
        'C-s' => "\x13",
        'C-t' => "\x14",
        'C-u' => "\x15",
        'C-v' => "\x16",
        'C-w' => "\x17",
        'C-x' => "\x18",
        'C-y' => "\x19",
        'C-z' => "\x1a",
    ];

    /** Backslash escapes understood inside `send`. */
    private const CHAR_ESCAPES = [
        'e' => "\x1b",
        'r' => "\r",
        'n' => "\n",
        't' => "\t",
        '\\' => '\\',
        '<' => '<',
    ];

    public function __construct(
        private ?string $root = null,
        private int $defaultTimeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private int $maxTimeoutSeconds = self::MAX_TIMEOUT_SECONDS,
        private ?\SugarCraft\Code\Agents\PathJail $worktreeJail = null,
        private ?SandboxBackend $sandbox = null,
        private ?\Closure $toolProgressEmitter = null,
    ) {
        // The wall ceiling is what the turn waits on. Same purpose as Bash's —
        // so no single call can hold the loop forever — but deliberately
        // tighter than Bash's (30s default / 300s max against Bash's
        // 120s / 600s): an interactive driver is meant to nudge a program
        // step-by-step, not to babysit a long job.
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
                    if (isset(self::KEY_TOKENS[$name])) {
                        $out .= self::KEY_TOKENS[$name];
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
                $verdict = PtyDriver::run(
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
                    . 'Nothing ran, sandboxed or otherwise. Tell the user: they can fix %s '
                    . '(on Ubuntu, unprivileged user namespaces are restricted by AppArmor) or set bashSandbox to "off".',
                    $this->sandbox->mode(),
                    $this->sandbox->name(),
                    $refusal,
                    $this->sandbox->name(),
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

        $run = PtyDriver::run(
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

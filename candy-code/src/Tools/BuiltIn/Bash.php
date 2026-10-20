<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Agents\PathJail as AgentPathJail;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\AcceptsToolProgress;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Code\Tools\Concerns\TruncatesOutput;
use SugarCraft\Code\Tools\PromptGuidance;
use SugarCraft\Code\Tools\ShellTailRing;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\AcceptsWorktreeJail;
use SugarCraft\Code\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Code\Tools\Sandbox\Bubblewrap;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Sessions\BackgroundShellRegistry;
use SugarCraft\Code\Tools\CarriesSessionState;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;

/**
 * Runs a shell command.
 *
 * SECURITY — intentional PathJail asymmetry: unlike {@see Edit}, {@see Read},
 * {@see Glob} and {@see Grep}, Bash is deliberately NOT path-jailed. `$root`
 * only sets the working directory (a `cd` prefix); it does NOT confine the
 * command. Arbitrary shell can `cat /etc/passwd`, `cd /`, follow symlinks, or
 * reach anything the PHP process can — jailing free-form shell by rewriting the
 * command string is not sound, so we don't pretend to.
 *
 * When a worktree PathJail is injected, the `cd` prefix targets the worktree
 * root so git/file operations run within that isolated tree. The command
 * itself is still unconstrained.
 *
 * Callers that need containment have three layers: the `bashSandbox` setting
 * (roadmap 5.12), which runs every command inside a {@see Bubblewrap} write
 * jail on Linux and refuses to run it at all when that jail cannot start; run
 * the process itself in a real jail/container; and/or opt into
 * {@see \SugarCraft\Code\Hooks\BuiltIn\BashEscapeDenyHook}, a heuristic
 * PreToolUse hook that denies commands referencing paths outside `$root`.
 */
#[BuiltInTool(name: 'Bash', permission: ToolPermissionClass::Write, position: 1)]
final readonly class Bash implements Tool, AcceptsWorktreeJail, PromptGuidance, AcceptsHeartbeat, BuildsFromCatalog, TakesToolCallId, AcceptsToolProgress, CarriesSessionState
{
    use CapturesProcessOutput;
    use RebindsWorktreeJail;
    use TruncatesOutput;

    /**
     * $maxOutputBytes bounds what a command can push into the context window.
     * Shell output is the least predictable of any tool's — `find /`, a `cat`
     * of a build artefact or a verbose test run all produce megabytes with no
     * warning — so the bound applies during capture as well as after it (see
     * {@see CapturesProcessOutput::runCaptured()}). Zero or negative disables
     * the cap for a caller that has arranged its own containment.
     *
     * $includeGitInstructions, $commitAttribution and $prAttribution are the
     * `includeGitInstructions` and `attribution` layered settings (step 0.3),
     * read by {@see \SugarCraft\Code\Cli\Bootstrap::tools()} and shaping
     * {@see promptGuidance()} only: false drops the whole `<git_commits>`
     * block, and a non-empty attribution string is the trailer a commit
     * message (or the closing line a pull-request description) ends with.
     * The defaults are the shipped behaviour: the block on, no trailer.
     *
     * $sandbox is the `bashSandbox` setting's jail (roadmap 5.12); null — the
     * default — runs commands exactly as before.
     *
     * $defaultTimeoutSeconds and $maxTimeoutSeconds are the `timeout`
     * parameter's default and ceiling — the `bashTimeoutSeconds` and
     * `bashMaxTimeoutSeconds` settings, applied per turn through
     * {@see \SugarCraft\Code\Tools\ToolLimits::applyTo()} and
     * {@see withTimeoutBounds()}; the constants when unset.
     *
     * $toolProgressEmitter is production wiring, not a setting: the turn's
     * live event channel (bound through {@see withToolProgressEmitter()} by
     * {@see \SugarCraft\Code\Backend\EngineBackend::turnTools()}), fed a
     * frame-rate-capped {@see \SugarCraft\Code\Events\ToolProgress} tail of
     * the command's output while it runs (WEB-4). Null — every launch-built
     * and standalone caller — streams nothing, exactly as before.
 *
 * $registry (SHL-1) is the launch's {@see \SugarCraft\Code\Sessions\BackgroundShellRegistry},
 * the table `run_in_background` spawns into and ShellOutput/ShellStop read.
 * Null — a standalone Bash, an embedder’s context built without one — makes
 * `run_in_background` an immediate refusal, never a silently-different spawn.
     */
    public function __construct(
        private ?string $root = null,
        private ?AgentPathJail $worktreeJail = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private bool $includeGitInstructions = true,
        private string $commitAttribution = '',
        private string $prAttribution = '',
        private ?Bubblewrap $sandbox = null,
        private int $defaultTimeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private int $maxTimeoutSeconds = self::MAX_TIMEOUT_SECONDS,
        private ?\Closure $toolProgressEmitter = null,
        private ?BackgroundShellRegistry $registry = null,
    ) {}

    /**
     * This tool streaming its live tail through $emit and every other field
     * kept — the {@see withGitGuidance()} rebuild, for the same reason: every
     * property is constructor-promoted, so the object's own vars are the
     * constructor's argument list. The turn's channel binds here (WEB-4);
     * {@see AcceptsToolProgress} is the seam that hands it over.
     *
     * @param \Closure(\SugarCraft\Code\Events\ToolProgress): void $emit
     */
    public function withToolProgressEmitter(\Closure $emit): self
    {
        return new self(...array_replace(get_object_vars($this), ['toolProgressEmitter' => $emit]));
    }

    /**
     * This tool with the `timeout` parameter's default and ceiling replaced
     * (null keeps that one), every other field kept — the
     * {@see withGitGuidance()} rebuild. A ceiling below 1 is 1, and a default
     * above the ceiling is the ceiling: a call is never left unbounded, and
     * the default never promises more than the ceiling allows.
     */
    public function withTimeoutBounds(?int $defaultSeconds, ?int $maxSeconds): self
    {
        $max = max(1, $maxSeconds ?? $this->maxTimeoutSeconds);

        return new self(...array_replace(get_object_vars($this), [
            'maxTimeoutSeconds' => $max,
            'defaultTimeoutSeconds' => min($max, max(1, $defaultSeconds ?? $this->defaultTimeoutSeconds)),
        ]));
    }

    /** @return array{default: int, max: int} the `timeout` parameter's bounds */
    public function timeoutBounds(): array
    {
        return ['default' => $this->defaultTimeoutSeconds, 'max' => $this->maxTimeoutSeconds];
    }

    /**
     * This tool with its sandbox replaced (null runs unconfined), every other
     * field kept — the {@see withGitGuidance()} rebuild.
     */
    public function withSandbox(?Bubblewrap $sandbox): self
    {
        return new self(...array_replace(get_object_vars($this), ['sandbox' => $sandbox]));
    }

    public function sandbox(): ?Bubblewrap
    {
        return $this->sandbox;
    }

    /**
     * This tool with the git-guidance settings replaced and every other field
     * kept - the {@see RebindsWorktreeJail} rebuild, for the same reason:
     * every property is constructor-promoted, so the object's own vars are
     * the constructor's argument list.
     */
    public function withGitGuidance(bool $include, string $commitAttribution = '', string $prAttribution = ''): self
    {
        return new self(...array_replace(get_object_vars($this), [
            'includeGitInstructions' => $include,
            'commitAttribution' => $commitAttribution,
            'prAttribution' => $prAttribution,
        ]));
    }

    /**
     * Reads `bashSandbox` HERE rather than in `Bootstrap::tools()`, for the
     * reason that method applies its filter where it does: every launch path
     * that builds a Bash — the TUI, `-p`, the turn child, a Task sub-agent —
     * builds it through this catalog entry, so a sandbox decided anywhere else
     * is a sandbox one of them skips. The key is user-tier only, so a
     * checked-out repository cannot switch it off.
     */
    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self(
            $context->root,
            sandbox: Bubblewrap::fromSetting(Bootstrap::readUserConfig()['bashSandbox'] ?? null),
            registry: $context->backgroundShells,
        );
    }

    public function name(): string
    {
        return 'Bash';
    }
    /**
     * The three facts a first-time caller cannot infer and pays a wasted turn
     * for: nothing survives between calls (each {@see execute()} builds a
     * fresh `bash -c`, so a `cd`, an export or a shell function is gone by the
     * next one), the result is bounded (see {@see $maxOutputBytes}), and stderr
     * is NOT unconditionally part of the answer.
     *
     * That last clause is stated per branch because
     * {@see CapturesProcessOutput::mergeCapturedOutput()} decides per branch,
     * and the three branches disagree: stderr is appended when the command
     * FAILED, stands in for the whole answer when stdout was empty, and is
     * replaced by a marker when a SUCCEEDING command wrote to both. An earlier
     * draft of this description said "stdout and stderr are merged"
     * unconditionally, which reads a green `phpunit` or compiler run as
     * warning-free when the warnings went to stderr and were dropped — a
     * false claim is worse than the terse sentence it replaced.
     *
     * The tool-preference and one-command-per-call sentences are about the
     * user's keyboard, not taste: Read/Grep/Glob are read-class tools that run
     * without an approval prompt, while a shell line is let through unasked
     * only when {@see \SugarCraft\Code\Permissions\ReadOnlyCommands} can prove
     * every part of it read-only — a `cd <root> &&` prefix, a `python3 -c`
     * slice or a long chain with one unprovable link costs the user a prompt.
     * Replayed against a user's logged asks (2026-10-11), most of them were
     * exactly such lines.
     *
     * The byte figure is READ OFF $maxOutputBytes rather than written out,
     * because a caller that raised or disabled the cap would otherwise be
     * advertising a number that is not its own.
     */
    public function description(): string
    {
        $bound = $this->maxOutputBytes > 0
            ? sprintf(
                'The result is clipped at %s bytes, with a marker naming what was dropped.',
                number_format($this->maxOutputBytes),
            )
            : 'There is no size cap on this instance.';

        return 'Execute a bash command. Each call is a fresh `bash -c`, so nothing carries '
            . 'over from the previous one — not the working directory (a `cd` here has no '
            . 'effect on the next call), not environment variables, not shell functions. '
            . 'Output is captured, not written to the terminal. You get stdout; stderr is '
            . 'appended after it only when the command exits non-zero, and is returned on '
            . 'its own when the command wrote nothing to stdout. A command that SUCCEEDS '
            . 'while writing to stderr has that stderr replaced by a one-line marker, not '
            . 'included — so append 2>&1 yourself when the warnings are what you are after. '
            . $bound . ' '
            . $this->startsIn() . ' '
            . 'Use this for build, test and git work and for what no other tool can do; use '
            . 'Read to read a file (its offset and limit take a slice) and Grep and Glob to '
            . 'search and list, rather than cat, head, tail, grep, rg, find, ls, sed or awk '
            . 'here — those tools run without an approval prompt, and a shell line '
            . 'that cannot be proven read-only stops to ask the user. Give each call one '
            . 'simple command, not a long && chain or a for loop over files, and send '
            . 'independent commands as separate calls in one batch. '
            . 'Commands run detached from any controlling terminal with interactive '
            . 'prompts disabled: sudo, ssh, git credentials and pagers fail fast and '
            . 'say so on stderr. A command that needs a human at a keyboard cannot '
            . 'run here — configure passwordless access or have the user run it '
            . 'themselves. For a program that merely REFUSES to run without a '
            . 'terminal, `interactive: true` attaches a private pseudo-terminal it '
            . 'can paint on and its screen comes back as the transcript; no one '
            . 'types answers there and no password is ever accepted, so a program '
            . 'that waits for input is terminated at its idle deadline. '
            . sprintf(
                'Every command is bounded by `timeout` seconds (default %d, max %d): past it the '
                . 'command and everything it started are killed, and the output it produced so far '
                . 'comes back with a line saying it timed out — raise it for a long build or test run.',
                $this->defaultTimeoutSeconds,
                $this->maxTimeoutSeconds,
            )
            . ($this->registry === null ? '' : sprintf(
                ' `run_in_background: true` starts the command detached and answers a shell_id at once: read it with ShellOutput (cursor-offset tail), end it with ShellStop; a background shell is killed with its whole group after %d seconds.',
                BackgroundShellRegistry::MAX_LIFETIME_SECONDS,
            ))
            . ($this->sandbox === null ? '' : ' ' . $this->sandbox->describe($this->writableRoot()));
    }

    /**
     * Where every call starts, stated because the model's habit is to open a
     * line with `cd <project root> &&` — a no-op that costs the user an
     * approval prompt whenever the rest of the line is not read-only.
     * TRUE BY CONSTRUCTION, and conditional exactly as {@see execute()} is:
     * with a root (or a worktree jail) the command runs after
     * {@see ProcessContainment::cdGuard()} on a line of its own, so it starts
     * in that directory or does not run at all; with neither it inherits this
     * process's working directory, and the sentence says that instead.
     */
    private function startsIn(): string
    {
        if ($this->worktreeJail !== null) {
            return 'Every call starts in your worktree\'s root, so never begin a command with a `cd` to it.';
        }
        if ($this->root !== null) {
            return 'Every call starts in the project root, so never begin a command with `cd <project root> &&`.';
        }

        return 'Every call starts in this process\'s working directory.';
    }

    /**
     * The directory the sandbox leaves writable: the worktree jail's root for
     * an isolated teammate, never the project root above it, else the root
     * the commands `cd` into, else this process's working directory (which
     * is where an unrooted command runs).
     */
    private function writableRoot(): string
    {
        return $this->worktreeJail?->root() ?? $this->root ?? (string) getcwd();
    }

    /**
     * Generic git discipline beside the tool that runs it, for ANY repository:
     * inspect, stage by name, commit, and each safety rule stated WITH the
     * reason it exists — a failed pre-commit hook means no commit landed, so
     * the next `--amend` would land on the PREVIOUS one, which is exactly why
     * `--amend` is checked against the exit status and `--no-verify` never
     * used. Spelled as numbered steps and a message template rather than free
     * prose because the sequence is genuinely fragile and the model performs
     * it through THIS tool.
     *
     * NO REPOSITORY'S CADENCE (step 0.3). This block used to carry the
     * SugarCraft monorepo's ship-as-you-go chain - branch prefixes,
     * `gh pr create`/`gh pr merge`, `<lib>:` titles, a `composer validate`
     * exemption - and so sent one project's process into every project the
     * agent ran in. That cadence lives in the monorepo's own AGENTS.md, which
     * the instruction loader already puts in front of the model there; here
     * the block defers to whatever rules the repository ships.
     *
     * Shaped by two layered settings ({@see __construct()}):
     * `includeGitInstructions: false` returns '' (the guidance layer then drops
     * the fragment entirely), and `attribution.commit` / `attribution.pr` are
     * rendered into the template when non-empty.
     *
     * It rides the session prompt, not the per-call schema, so the always-on
     * {@see description()} stays terse ({@see PromptGuidance}). The body names
     * no sibling tool so it holds when this tool is wired alone, and it wraps
     * its own tag pair because this layer renders unframed.
     */
    public function promptGuidance(): string
    {
        if (!$this->includeGitInstructions) {
            return '';
        }

        $steps = [
            'Branch names, message conventions and pull-request steps belong to the repository: follow its own contribution rules (an AGENTS.md, CONTRIBUTING.md or similar) where it has them. What follows holds in every repository.',
            'The steps are serial — each changes state the next depends on — so none of them batch in parallel.',
            '',
            '1. Look before staging: `git status` and `git diff` for what changed, `git log` for the message style this repository uses.',
            '2. Stage exactly the paths this change touched, by name.',
            '3. Commit as the repository\'s configured git identity, then check the exit status before doing anything else.',
        ];

        $safety = [
            'State each safety rule with the reason it exists, because the reason is what generalises to the case not listed.',
            'A failed pre-commit hook means the commit DID NOT happen, so the next `--amend` lands on the PREVIOUS commit — check the exit status first, fix the cause, and make a new commit.',
            'Never pass `--no-verify`: it skips the very hook whose failure you are trying to get past.',
            'Never force-push to the default branch: the rewritten history no longer matches any other clone.',
            'Never `git add -A`: it stages files this step never meant to touch.',
            'Never change the git config: the identity and settings are the user\'s, not this session\'s.',
        ];

        $template = [
            'The message is one fragile operation, so pass it through a heredoc rather than stacked flags and the blank lines between sections survive:',
            "git commit -F - <<'MSG'",
            '<summary line>',
            '',
            'What changed, and why.',
        ];
        if (trim($this->commitAttribution) !== '') {
            $template[] = '';
            $template[] = trim($this->commitAttribution);
        }
        $template[] = 'MSG';

        $closing = [];
        if (trim($this->commitAttribution) !== '') {
            $closing[] = 'End every commit message with the trailer shown above, exactly as written.';
        }
        if (trim($this->prAttribution) !== '') {
            $closing[] = 'End every pull-request description you write with this line, exactly as written:';
            $closing[] = trim($this->prAttribution);
        }

        $sections = array_merge($steps, [''], $safety, [''], $template);
        if ($closing !== []) {
            $sections = array_merge($sections, [''], $closing);
        }

        return "<git_commits>\n" . implode("\n", $sections) . "\n</git_commits>";
    }

    public function inputSchema(): array
    {
        return [
        'type' => 'object',
        'properties' => [
            'command' => ['type' => 'string', 'description' => 'The bash command to execute'],
            'description' => [
                'type' => 'string',
                'description' => 'Clear, concise 5-10 word description in active voice of what this command does (e.g. "List files in current directory", not "runs ls").',
            ],
            // Phase 9 layer C: the opt-in landed together with the mechanism
            // that honours it (InteractivePromptContainmentTest pinned its
            // absence until then). Default OFF — omitting it is today's
            // detached pipe spawn, byte for byte.
            'interactive' => [
                'type' => 'boolean',
                'description' => 'Run the command attached to a private pseudo-terminal it can paint on, for programs that refuse to run without a terminal. Default false. There is no human at this terminal and no password is ever accepted: a program that blocks waiting for input is terminated once its output stops, with its screen returned as the transcript.',
            ],
            'timeout' => [
                'type' => 'integer',
                'description' => sprintf(
                    'Seconds the command may run before it and every process it started are killed. Default %d, maximum %d; larger values are clamped.',
                    $this->defaultTimeoutSeconds,
                    $this->maxTimeoutSeconds,
                ),
                'minimum' => 1,
                'maximum' => $this->maxTimeoutSeconds,
            ],
            // SHL-1: the background opt-in. Off (the default, and the shape a
            // caller that never heard of it sends) is the synchronous capture
            // path, byte for byte; on returns a shell id the same launch's
            // ShellOutput/ShellStop address. The wall figure is stated so a
            // deliberate long-runner is restarted by asking, not discovered.
            'run_in_background' => [
                'type' => 'boolean',
                'description' => sprintf(
                    'Return immediately with a shell_id instead of waiting for the command. Read its output with ShellOutput(shell_id, cursor) and end it with ShellStop(shell_id). A background shell is killed with its whole process group after %d seconds whether or not anything reads it; raise `timeout` is not an option here — restart it if it needs to live longer. Default false.',
                    BackgroundShellRegistry::MAX_LIFETIME_SECONDS,
                ),
            ],
        ],
        'required' => ['command', 'description'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        return $this->runCommand($args, null);
    }

    /**
     * {@see execute()} with the turn's liveness beat fired from the capture's
     * wait loop (item 0.4-b), so a command allowed to run for its full
     * `timeout` is not killed first by the turn's 120 s idle watchdog.
     */
    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        return $this->runCommand($args, $heartbeat);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function runCommand(array $args, ?\Closure $heartbeat): ToolResult
    {
        $command = $args['command'] ?? '';
        $output = [];
        $exitCode = 0;
        // Worktree jail takes precedence over root for isolated teammates.
        // root() says "the directory this jail is bound to" outright, where
        // the previous jailPath('') got the same string by relying on an
        // edge case of a join helper that checks no containment at all.
        $cwd = $this->worktreeJail?->root() ?? $this->root ?? null;
        // Mirrors charmbracelet/bubbletea.*.Exec.
        // Use bash -c to interpret shell syntax; escapeshellarg prevents command injection.
        //
        // The prefix is ProcessContainment::cdGuard() — `cd ROOT || exit 1` on
        // a line of its own, never `cd ROOT && COMMAND` (audit F-E3; the
        // helper's doc-block carries the parse that makes `&&` wrong). It is
        // shared with interactiveSpawnCommand() since F-E5, which had kept
        // the old form, so the two spellings cannot drift apart again.
        //
        // proc_open's own cwd parameter is not the alternative it looks like.
        // MEASURED, PHP 8.3.6: a missing cwd makes proc_open() raise
        // "posix_spawn() failed: No such file or directory" as a PHP warning
        // and return false — a warning painted outside the TUI frame and a
        // spawn failure the capture path would have to special-case, where
        // the shell guard gives an ordinary non-zero exit with a reason.
        if ($cwd !== null) {
            $cmd = "bash -c " . escapeshellarg(ProcessContainment::cdGuard($cwd) . $command);
        } else {
            $cmd = "bash -c " . escapeshellarg($command);
        }
        // runCaptured(), not exec(): exec() leaves the child's stderr
        // attached to the real terminal, so a command that writes there
        // paints outside the TUI frame -- and the model never sees the
        // reason a command failed.
        //
        // The `interactive` opt-in (Phase 9 layer C) chooses the MECHANISM,
        // never the policy: both branches carry ProcessContainment's
        // fail-fast env, the default branch is untouched layer-A behaviour,
        // and neither branch accepts secrets.
        //
        // `timeout` (item 0.4-a) bounds BOTH mechanisms: runCaptured() kills
        // the setsid group at the deadline, and the interactive path takes it
        // as the wall bound beside its idle ceiling. The timed-out line rides
        // stderr so mergeCapturedOutput() files it as the failure's tail —
        // the part truncateMerged() budgets first, so a megabyte of output
        // before it cannot push the reason off the end.
        //
        // Captured past the cap when a spill can keep the overflow (roadmap
        // 2.8): bytes the capture drops are bytes no saved file can hold, so
        // a capture bounded at the cap would leave the spilled file without
        // the real middle of a large log. The RESULT is still clipped to
        // $maxOutputBytes by truncateMerged() below.
        $maxBytes = $this->captureBound($this->maxOutputBytes);
        $timeout = $this->timeoutSeconds($args['timeout'] ?? null);
        $interactive = ($args['interactive'] ?? false) === true;
        $background = ($args['run_in_background'] ?? false) === true;

        // SHL-1, and it is checked BEFORE the sandbox probe so a refused
        // combination costs no spawn at all (fail fast, fail loud): a pty is
        // the synchronous mechanism by design — its whole point is a screen
        // captured while a human-less program paints on it — and a detached
        // background shell has no one left to watch one. The two flags ask
        // for opposite lives for the same child.
        if ($background && $interactive) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: 'run_in_background and interactive cannot both be true: an interactive run is a captured terminal screen this call waits for, and a background shell has no reader between calls. Pick one.',
                isError: true,
            );
        }

        // WEB-4: the live tail. The ring is built only when the turn bound a
        // channel AND the call has its engine-spliced id — a tail without an
        // id has no row to hang on, and inventing one would let a forged
        // `id` argument steer where frames land (TakesToolCallId is what
        // makes $args['id'] the engine's own value). Both capture mechanisms
        // feed the same ring; the trait calls back per chunk it keeps.
        $callId = (string) ($args['id'] ?? '');
        $progress = $this->toolProgressEmitter !== null && $callId !== ''
            ? new ShellTailRing(
                $callId,
                self::NAME,
                $this->toolProgressEmitter,
                self::PROGRESS_TAIL_BYTES,
                self::PROGRESS_FRAME_INTERVAL_SECONDS,
            )
            : null;
        $onChunk = $progress === null
            ? null
            : static function (string $chunk) use ($progress): void {
                $progress->onChunk($chunk);
            };

        // The sandbox (roadmap 5.12) is a PREFIX on the same shell string, so
        // both capture mechanisms — and the setsid group kill the timeout
        // fires — keep working unchanged. A configured sandbox that cannot
        // start REFUSES the command: running it unconfined because the jail
        // failed would turn the setting into a promise nobody keeps.
        if ($this->sandbox !== null) {
            $refusal = $this->sandbox->probe(function (string $probe): array {
                $result = $this->runCaptured($probe, null, 4096, 10.0);

                return ['exitCode' => $result['exitCode'], 'stderr' => $result['stderr']];
            });
            if ($refusal !== null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: sprintf(
                        'Not run: the bashSandbox setting is "%s" but the bubblewrap sandbox cannot start on this host (%s). '
                        . 'Nothing ran, sandboxed or otherwise. Tell the user: they can fix bwrap '
                        . '(on Ubuntu, unprivileged user namespaces are restricted by AppArmor) or set bashSandbox to "off".',
                        $this->sandbox->mode(),
                        $refusal,
                    ),
                    isError: true,
                );
            }
            $cmd = $this->sandbox->wrap($cmd, $this->writableRoot(), $interactive);
        }

        // The background half of SHL-1, riding the SAME wrapped command the
        // synchronous path was about to run — cd guard, sandbox prefix and
        // all — into the launch's registry. `start()` owns the rest: file
        // sinks, exit-code trailer, containment spawn, group bookkeeping.
        // No ShellTailRing fires (nothing streams); the call answers after a
        // return that costs milliseconds, so no heartbeat is owed either.
        if ($background) {
            if ($this->registry === null) {
                return new ToolResult(
                    toolCallId: $args['id'] ?? '',
                    content: 'this Bash has no background shell registry attached, so run_in_background cannot start the command; run it without the flag (the `timeout` bound applies) — the launch that built this tool decided whether background shells exist.',
                    isError: true,
                );
            }

            return $this->backgroundAnswer($args, $this->registry->start($cmd, (string) ($args['description'] ?? '')));
        }

        $run = $interactive
            ? $this->runCapturedInteractive($cmd, null, $maxBytes, null, (float) $timeout, $heartbeat, $onChunk)
            : $this->runCaptured($cmd, null, $maxBytes, (float) $timeout, [], $heartbeat, $onChunk);
        // The last frame: bytes that arrived between the final cadence slice
        // and EOF would otherwise never have been shown. tool.finished lands
        // the full contract right after, so a client that missed this frame
        // loses nothing it could not re-derive.
        $progress?->finish();
        if (($run['timedOut'] ?? false) === true) {
            // ISS-014: the group-kill claim rides the CONFIRMATION, never
            // the timeout flag. An unconfirmed stop (no recorded group, or
            // a group that kept answering kill(0) past both rungs) says so
            // instead of narrating a kill that may not have landed.
            $killing = ($run['groupKilled'] ?? false) === true
                ? 'the command and its process group were killed'
                : 'the command was signalled but its death was not confirmed — anything it backgrounded may still be running';
            $run['stderr'] = ltrim($run['stderr'] . "\n" . sprintf(
                '[timed out after %d s: %s; pass a larger `timeout` (max %d) if it needs longer]',
                $timeout,
                $killing,
                $this->maxTimeoutSeconds,
            ), "\n");
        }

        // The merge can concatenate stdout AND stderr, so each being within
        // the bound is not the same as the result being within it — the final
        // string gets the authoritative clip. It is handed the merge's own
        // account of what survived rather than the capture's raw totals: only
        // the merge knows whether the stderr the capture clipped is even part
        // of this answer, and whether the failure explanation still has to be
        // fitted in alongside a large stdout.
        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: $this->truncateMerged(
                $this->mergeCapturedOutput($run),
                $this->maxOutputBytes,
            ),
            isError: $run['exitCode'] !== 0,
        );
    }

    /**
     * The answer to a successful (or refused) background start: the shell id
     * front and centre, the two tools that address it named with their
     * arguments, and the wall stated up front so a forgotten shell is never
     * a mystery. Raw prose, no denial-shaped frame (DenialPrefixRoster law).
     *
     * @param array<string, mixed> $args
     * @param array{ok: bool, error?: string, shell?: array<string, mixed>} $started
     */
    private function backgroundAnswer(array $args, array $started): ToolResult
    {
        if ($started['ok'] !== true) {
            return new ToolResult(
                toolCallId: $args['id'] ?? '',
                content: (string) $started['error'],
                isError: true,
            );
        }
        $shell = $started['shell'];
        $id = (string) ($shell['id'] ?? '');

        return new ToolResult(
            toolCallId: $args['id'] ?? '',
            content: sprintf(
                'Started background shell %s. Read new output with ShellOutput {shell_id: "%s", cursor: <the cursor the last answer gave>}; stop it with ShellStop {shell_id: "%s"}. It is killed with its whole process group after %d seconds whether or not anything reads it — the wrapper enforces that wall in the kernel, beside the launch-side ladder. Each output file is capped at %d MiB: a shell writing past the cap dies with exit 153 and ShellOutput names the limit. Its output and exit code stay readable while this launch tracks it, and any shell file nobody tracks and nobody has written for a day is swept when the next background shell starts.',
                $id,
                $id,
                $id,
                BackgroundShellRegistry::MAX_LIFETIME_SECONDS,
                \intdiv(BackgroundShellRegistry::OUTPUT_LIMIT_BYTES, 1048576),
            ),
        );
    }

    /**
     * The registry's rows ride the forked tool-child boundary under the
     * {@see \SugarCraft\Code\Tools\CarriesSessionState} contract: a
     * background shell started inside a forked child must be addressable by
     * the parent's later ShellOutput/ShellStop calls, and the child's
     * `proc_open` resource never serialises — the parent re-derives liveness
     * from the exit file and the recorded group.
     *
     * @return array<string, mixed>
     */
    public function exportSessionState(): array
    {
        return [BackgroundShellRegistry::SESSION_STATE_KEY => $this->registry?->toArray() ?? []];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mergeSessionState(array $state): void
    {
        $this->registry?->merge($state[BackgroundShellRegistry::SESSION_STATE_KEY] ?? null);
    }

    /**
     * The `timeout` parameter's bounds (item 0.4-a). The default is the
     * figure a model would otherwise have to guess, and the ceiling stops
     * one call from holding a turn for longer than any build here takes; a
     * value outside the range is CLAMPED rather than refused, because a
     * model asking for 900 s wants "as long as allowed", not an error.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 120;
    public const MAX_TIMEOUT_SECONDS = 600;

    /**
     * The live tail's frame budget and cadence (WEB-4). 4 KiB is the newest
     * bytes a watcher needs to follow a build's scroll; half a second is the
     * pacing every live surface here already keeps (the media progress loop
     * polls on the same beat, and TokenDelta frames cost the reducer more
     * than they paint below this interval). The bound lives on the ring, not
     * the wire: a frame past MAX_FRAME_BYTES would fail the whole turn, and
     * the 256 KiB tool.finished contract is the durable surface.
     */
    public const PROGRESS_TAIL_BYTES = 4096;
    public const PROGRESS_FRAME_INTERVAL_SECONDS = 0.5;

    /** The tool name this call streams under ({@see self::name()}). */
    public const NAME = 'Bash';

    /**
     * The model's `timeout` as the seconds this call may run: a positive
     * number (or a numeric string, the shape a lax tool-call parser hands
     * over) rounded up and clamped to the ceiling; zero, a negative or
     * anything non-numeric is the default — never "no bound".
     */
    private function timeoutSeconds(mixed $raw): int
    {
        if (is_string($raw) && preg_match('/^\s*\d+(\.\d+)?\s*$/', $raw) === 1) {
            $raw = (float) $raw;
        }
        if (!is_int($raw) && !is_float($raw)) {
            return $this->defaultTimeoutSeconds;
        }
        if (is_nan((float) $raw) || $raw <= 0) {
            return $this->defaultTimeoutSeconds;
        }
        if (is_infinite((float) $raw)) {
            return $this->maxTimeoutSeconds;
        }

        return min($this->maxTimeoutSeconds, (int) ceil((float) $raw));
    }
}

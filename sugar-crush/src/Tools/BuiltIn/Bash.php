<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tools\AcceptsHeartbeat;
use SugarCraft\Crush\Tools\Concerns\CapturesProcessOutput;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\PromptGuidance;
use SugarCraft\Crush\Tools\AcceptsWorktreeJail;
use SugarCraft\Crush\Tools\Concerns\RebindsWorktreeJail;
use SugarCraft\Crush\Tools\Sandbox\Bubblewrap;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;

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
 * {@see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook}, a heuristic
 * PreToolUse hook that denies commands referencing paths outside `$root`.
 */
#[BuiltInTool(name: 'Bash', permission: ToolPermissionClass::Write, position: 1)]
final readonly class Bash implements Tool, AcceptsWorktreeJail, PromptGuidance, AcceptsHeartbeat, BuildsFromCatalog
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
     * read by {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} and shaping
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
     * {@see \SugarCraft\Crush\Tools\ToolLimits::applyTo()} and
     * {@see withTimeoutBounds()}; the constants when unset.
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
    ) {}

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
            . 'Prefer Read/Grep/Glob for reading and searching files; reach for this for '
            . 'build, test and git work, and for anything those tools cannot do. '
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
            . ($this->sandbox === null ? '' : ' ' . $this->sandbox->describe($this->writableRoot()));
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

        $run = $interactive
            ? $this->runCapturedInteractive($cmd, null, $maxBytes, null, (float) $timeout, $heartbeat)
            : $this->runCaptured($cmd, null, $maxBytes, (float) $timeout, [], $heartbeat);
        if (($run['timedOut'] ?? false) === true) {
            $run['stderr'] = ltrim($run['stderr'] . "\n" . sprintf(
                '[timed out after %d s: the command and its process group were killed; pass a larger `timeout` (max %d) if it needs longer]',
                $timeout,
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
     * The `timeout` parameter's bounds (item 0.4-a). The default is the
     * figure a model would otherwise have to guess, and the ceiling stops
     * one call from holding a turn for longer than any build here takes; a
     * value outside the range is CLAMPED rather than refused, because a
     * model asking for 900 s wants "as long as allowed", not an error.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 120;
    public const MAX_TIMEOUT_SECONDS = 600;

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

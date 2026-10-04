<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Lint;

/**
 * What one run of the configured test command produced, and the
 * model-visible text a failing run becomes (step 3.H).
 *
 * THE TEXT IS AIDER'S `run_output` PROMPT, because auto-test reflection is
 * Aider's loop (`--auto-test`, `base_coder.py` reflections) and that wording is
 * the one models have seen most:
 *
 *     I ran this command:
 *
 *     composer test
 *
 *     And got this output:
 *
 *     FAILURES!
 *     Tests: 12, Assertions: 30, Failures: 1.
 *
 * The output is stderr interleaved with stdout as the command wrote them
 * ({@see TestRunner} runs it under `exec 2>&1`), and its TAIL is what is kept
 * when it is long: a test runner prints its verdict and its failure list
 * last, so the end is the part that says what to fix.
 *
 * Built by {@see TestRunner::run()}; immutable.
 */
final readonly class TestReport
{
    /**
     * Bytes of the command's output kept in {@see runOutput()} — the hook-note
     * cap ({@see \SugarCraft\Crush\Hooks\HookResult::MAX_ADDITIONAL_CONTEXT_BYTES}),
     * since this text rides the same channel back to the model.
     */
    public const MAX_OUTPUT_BYTES = 10000;

    /** Exit codes the shell gives a command it could not run (126 not executable, 127 not found). */
    private const COULD_NOT_RUN = [126, 127];

    /**
     * @param string $command the command line as the user configured it
     * @param string $output the combined output, trimmed
     * @param int $droppedBytes output discarded by the capture bound, so the
     *        kept text is known to be partial
     */
    public function __construct(
        public string $command,
        public int $exitCode,
        public string $output,
        public bool $timedOut,
        public float $timeoutSeconds,
        public int $droppedBytes = 0,
    ) {}

    /** True when the command ran to completion and exited 0. */
    public function passed(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }

    /**
     * True when the shell could not run the command at all — a typo in
     * `testCommand`, not a failing test, so there is nothing for the model to
     * fix.
     */
    public function couldNotRun(): bool
    {
        return !$this->timedOut && in_array($this->exitCode, self::COULD_NOT_RUN, true);
    }

    /**
     * Aider's `run_output` text for this run — see the class docblock. A run
     * stopped at its bound says so after the output, since a hang the edit
     * introduced is something the model can fix.
     */
    public function runOutput(): string
    {
        $output = $this->output === ''
            ? sprintf('(the command exited %d and printed nothing)', $this->exitCode)
            : self::tail($this->output, $this->droppedBytes);

        if ($this->timedOut) {
            $output .= sprintf(
                "\n\n(the command did not finish within %s seconds and was stopped)",
                self::seconds($this->timeoutSeconds),
            );
        }

        return "I ran this command:\n\n" . $this->command . "\n\nAnd got this output:\n\n" . $output . "\n";
    }

    /**
     * One line for the operator when the run cannot be reflected on: the
     * command would not start.
     */
    public function couldNotRunReason(): string
    {
        $first = trim((string) strtok(mb_scrub($this->output, 'UTF-8'), "\n"));

        return sprintf(
            'the test command `%s` could not run (exit %d)%s',
            $this->command,
            $this->exitCode,
            $first === '' ? '' : ': ' . mb_strimwidth($first, 0, 200, '…', 'UTF-8'),
        );
    }

    /**
     * The output's last {@see MAX_OUTPUT_BYTES}, cut on a UTF-8 boundary, with
     * a leading marker when anything before it was left out — and a trailing
     * one when the capture bound ({@see TestRunner::MAX_CAPTURE_BYTES}, which
     * keeps the HEAD of a stream) dropped the real end, so a run that printed
     * megabytes is never presented as if its last line were shown.
     */
    private static function tail(string $output, int $droppedBytes): string
    {
        // A runner that colours its output anyway leaves SGR codes and other
        // controls in it; the model reads text, so only newline and tab stay.
        $output = (string) preg_replace(
            ['/\e\[[0-9;?]*[ -\/]*[@-~]/', '/[\x00-\x08\x0B-\x1F\x7F]/'],
            '',
            str_replace(["\r\n", "\r"], "\n", mb_scrub($output, 'UTF-8')),
        );
        $kept = strlen($output) <= self::MAX_OUTPUT_BYTES
            ? $output
            : mb_strcut($output, strlen($output) - self::MAX_OUTPUT_BYTES, null, 'UTF-8');

        $text = strlen($kept) < strlen($output)
            ? sprintf("[… test output truncated: the first %d bytes are not shown]\n", strlen($output) - strlen($kept)) . $kept
            : $kept;

        return $droppedBytes === 0
            ? $text
            : $text . sprintf("\n[… a further %d bytes of output past the capture bound were not kept]", $droppedBytes);
    }

    private static function seconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
    }
}

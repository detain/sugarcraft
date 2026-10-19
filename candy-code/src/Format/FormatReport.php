<?php

declare(strict_types=1);

namespace SugarCraft\Code\Format;

/**
 * What one format run of one file did, and the model-visible text it becomes
 * (EDT-1). Built by {@see FormatRunner::format()}; immutable.
 *
 * THE NOTE IS NOT THE LINT'S AIDER BLOCK. A lint report locates an error for
 * the model to fix; a format report says what happened TO THE FILE the model
 * just wrote — it was re-written (so stop trusting your line numbers), or it
 * was put back because the formatter misbehaved (so its own output is the
 * evidence). Two short shapes, below; a run that exited 0 and left the bytes
 * alone renders as nothing, the clean-lint doctrine: a note that only says
 * "fine" costs context and teaches nothing.
 */
final readonly class FormatReport
{
    /**
     * Bytes of the formatter's own output kept in the rendered text — the
     * lint report's reason: the note rides a bounded chain budget, and the
     * diagnosis, not the transcript, is what the model needs.
     */
    public const MAX_OUTPUT_BYTES = 4096;

    /**
     * @param string $file the path the command was given, relative to the project root
     * @param string $absolute the resolved path inside the jail — what a caller holding a
     *        {@see \SugarCraft\Code\Tools\ReadLedger} records after a kept rewrite
     * @param string $command the full command line that ran
     * @param string $output stderr, then stdout, trimmed
     * @param bool $changed the run exited 0 and the bytes are different — a KEPT rewrite
     * @param ?string $restored why the file was put back, null when it was not
     */
    public function __construct(
        public string $file,
        public string $absolute,
        public string $command,
        public int $exitCode,
        public string $output,
        public bool $timedOut,
        public float $timeoutSeconds,
        public bool $changed,
        public ?string $restored,
    ) {}

    /** True when the formatter ran to completion and exited 0. */
    public function passed(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }

    /** Whether anything reached the model: a kept rewrite, or anything wrong. */
    public function needsNote(): bool
    {
        return $this->changed || $this->restored !== null || !$this->passed();
    }

    /** The model-visible text — '' when the run is clean AND left the file alone. */
    public function render(): string
    {
        if (!$this->needsNote()) {
            return '';
        }

        $out = strlen($this->output) > self::MAX_OUTPUT_BYTES
            ? substr($this->output, 0, self::MAX_OUTPUT_BYTES) . '…'
            : $this->output;

        if ($this->restored !== null) {
            $text = "## Post-edit format: {$this->file} was put back unchanged\n\n"
                . "The format command `{$this->command}` {$this->restored}; the file holds the bytes it had before the run."
                . ($this->passed() ? '' : sprintf(' It exited %d%s.', $this->exitCode, $this->timedOut ? " after running past its {$this->timeoutSeconds} s wall" : ''));

            return $out === '' ? $text : $text . "\n\n" . $out;
        }

        if (!$this->passed()) {
            $text = "## Post-edit format: the formatter for {$this->file} did not run cleanly\n\n"
                . sprintf('`%s` exited %d%s; the file was left as the edit wrote it.', $this->command, $this->exitCode, $this->timedOut ? " after running past its {$this->timeoutSeconds} s wall" : '');

            return $out === '' ? $text : $text . "\n\n" . $out;
        }

        return "## Post-edit format\n\n`{$this->command}` rewrote {$this->file}; its bytes are no longer the ones you wrote — re-read before editing against line numbers.";
    }
}

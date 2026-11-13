<?php

declare(strict_types=1);

namespace SugarCraft\Forms;

/**
 * Outcome of one {@see ActionSpinner::run()} — SF-46 (sugar-forms-067).
 *
 * Before this VO existed, "how did my deploy go?" was answerable only by
 * closure-dance: the action runs in a forked child, so in-memory writes do
 * not cross back and the parent threw a generic failure for any non-zero
 * exit. The result object collects what the fork/inline paths already knew
 * internally and hands it to the caller as data:
 *
 * - `exitCode` — the child's exit status when it exited normally
 *   (`0` = success). `null` when the child did not exit normally: killed
 *   by a signal, or its status was already reaped out of our hands by a
 *   signal handler's bounded ladder.
 * - `signal` — the terminating signal number when the child died by
 *   signal, otherwise `null`. A signal-killed child previously fell
 *   through the exit-status check and looked like success; the VO reports
 *   it honestly instead.
 * - `timedOut` — always `false` on the returned object: a ceiling expiry
 *   still throws (the child's fate — reaped or abandoned — is a process
 *   hygiene fact, not a verdict to swallow).
 * - `wallSeconds` — wall-clock seconds the run spent spinning/inline,
 *   measured around the action.
 *
 * SugarCraft extension: charmbracelet/huh's spinner actions return
 * `error` and bubble the result through the model; this VO is the PHP
 * fork-semantics answer (upstream has no fork boundary to cross).
 */
final class SpinnerResult
{
    /**
     * @param int|null   $exitCode   child exit status, null if not exited normally
     * @param int|null   $signal     terminating signal number, null if not signal death
     * @param bool       $timedOut   reserved for the never-throwing hosts; run() throws on expiry
     * @param float      $wallSeconds wall-clock seconds of the run
     */
    private function __construct(
        public readonly ?int $exitCode,
        public readonly ?int $signal,
        public readonly bool $timedOut,
        public readonly float $wallSeconds,
    ) {
    }

    public static function new(?int $exitCode, ?int $signal, bool $timedOut, float $wallSeconds): self
    {
        return new self($exitCode, $signal, $timedOut, $wallSeconds);
    }

    /** Clean success: exited normally with status 0, not signal-killed, not timed out. */
    public function ok(): bool
    {
        return $this->exitCode === 0 && $this->signal === null && $this->timedOut === false;
    }

    /**
     * Opt into the old throw-on-failure contract (SF-35 copy intact):
     * throws the localized `spinner.action_failed` row for a non-zero
     * exit. A signal-killed child (exitCode null, signal set) and the
     * timed-out case are NOT re-thrown here — the first never had a
     * throw contract (it fell through as success pre-SF-46), the second
     * throws from {@see ActionSpinner::run()} itself.
     */
    public function throwIfFailed(): void
    {
        if ($this->exitCode !== null && $this->exitCode !== 0) {
            throw new \RuntimeException(Lang::t('spinner.action_failed', [
                'code' => $this->exitCode,
            ]));
        }
    }
}

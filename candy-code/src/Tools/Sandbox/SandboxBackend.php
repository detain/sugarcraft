<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

/**
 * The jail a sandboxed command runs inside.
 *
 * One seam, two backends: {@see Bubblewrap} on Linux, {@see Seatbelt} on
 * macOS, chosen by {@see SandboxFactory}. Everything a caller needs to know
 * about a jail — how to spawn through it, whether it starts on this host, and
 * what to tell the model when it does not — is in this contract, so Bash and
 * PtyRun carry a `?SandboxBackend` and never name an implementation.
 *
 * The vocabulary constants live here rather than on either backend because
 * the SETTING is backend-independent: `bashSandbox` carries one of these
 * values whichever jail the host answers it with, and a reader (the settings
 * schema, the doctor row) never has to know which OS it is on to spell a mode.
 *
 * A $chdir different from the writable root, and $readOnlyBinds, exist on the
 * contract day one (C-TOOL-4.d/4.e wire them); a backend that cannot honour
 * one must fail loudly rather than silently run the command differently.
 */
interface SandboxBackend
{
    /** The `bashSandbox` vocabulary, default first. */
    public const MODE_OFF = 'off';
    public const MODE_ON = 'on';
    public const MODE_NO_NETWORK = 'no-network';
    public const MODES = [self::MODE_OFF, self::MODE_ON, self::MODE_NO_NETWORK];

    /** What to call this backend in user-facing prose — lowercase, one word. */
    public function name(): string;

    /** The setting value this instance answers to (`on` or `no-network`). */
    public function mode(): string;

    /**
     * The binary to run, or '' when none is configured and none is found.
     * The doctor row reports through this, so it must name the binary THIS
     * backend would exec, not a hard-coded `bwrap`.
     */
    public function resolvedBinary(): string;

    /**
     * The spawn prefix up to (and for bwrap, including) the separator that
     * precedes the command.
     *
     * $interactive drops the jail's terminal detach where that is a choice
     * (bwrap's `--new-session`); $tmpDir '' skips the private-tmp arm;
     * $chdir null means the writable root; $readOnlyBinds names extra
     * paths under the root that must stay read-only.
     *
     * @return list<string>
     */
    public function argv(
        string $writableRoot,
        bool $interactive = false,
        ?string $tmpDir = null,
        ?string $chdir = null,
        array $readOnlyBinds = [],
    ): array;

    /**
     * $shellCommand (an already-escaped `bash -c …` string) run inside the
     * jail, as one shell string — the shape both capture paths take.
     */
    public function wrap(
        string $shellCommand,
        string $writableRoot,
        bool $interactive = false,
        ?string $tmpDir = null,
        ?string $chdir = null,
        array $readOnlyBinds = [],
    ): string;

    /**
     * Null when this sandbox starts on this host, else the reason it does not.
     *
     * $run spawns a shell string and returns its exit code and stderr — the
     * caller's own capture path, so the probe adds no spawn site of its own.
     * A backend unavailable on this OS must answer from that fact alone,
     * BEFORE touching any binary: the answer is host-level and deterministic,
     * and reaching for a macOS path on Linux (or vice versa) would make the
     * refusal depend on what happens to be installed.
     *
     * @param \Closure(string): array{exitCode: int, stderr: string} $run
     */
    public function probe(\Closure $run): ?string;

    /**
     * The remedy interpolated into the shell tools' refusal sentence after
     * "they can fix <name>" — the host fact that turns THIS backend's refusal
     * into a fix on the host it belongs to. Per backend, exactly like the
     * doctor row's remedy arms: bubblewrap's story is Ubuntu's AppArmor
     * userns restriction, Seatbelt's is the OS-shipped /usr/bin/sandbox-exec
     * that stopped running the profile. One sentence hard-coding either story
     * misadvises the other OS's user, which is worse than naming no story.
     */
    public function refusalRemedy(): string;

    /**
     * The sentence the tool's description gains while this sandbox is on, so
     * the model knows why a write outside the root fails instead of retrying
     * it. It must name the failure the backend actually produces — a jail
     * that denies by policy answers "Operation not permitted", not "Read-only
     * file system".
     */
    public function describe(string $writableRoot): string;
}

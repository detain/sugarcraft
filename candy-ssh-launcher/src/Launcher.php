<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use SugarCraft\Core\Util\Sanitize;

/**
 * Replaces the current PHP process with `ssh(1)` connecting to the
 * chosen {@see Endpoint}. Uses `pcntl_exec()` so file descriptors,
 * environment, controlling tty, and exit status all flow through
 * unchanged — the user sees a normal `ssh` session, including
 * host-key prompts, agent forwarding, and the standard MOTD.
 *
 * `dispatch()` never returns normally: on success the process image
 * is replaced, and on failure the default executor THROWS a
 * `RuntimeException` naming the binary and the recorded OS reason
 * (which propagates out of `dispatch()`). ext-pcntl is a hard
 * composer requirement, so there is no runtime feature probe here —
 * an absent extension fails at install time, not at dispatch time.
 *
 * Invariant (-081, pinned by ExecHandoffTest): at dispatch time no
 * open resource above fd 2 survives except the PHP main-script handle
 * (config bytes arrive through file_get_contents, closed at read). Do
 * not add long-lived streams to the boot path — whatever is open here
 * is inherited by the user's ssh session.
 *
 * For tests, `executor` is a callable receiving the argv list —
 * defaults to `pcntl_exec` but tests can swap it for a recorder.
 */
final class Launcher
{
    /**
     * Canonical default ssh binary. `pcntl_exec` does not search
     * `$PATH`, so the launcher takes an absolute path; `bin/candy-ssh-launcher`
     * and {@see dispatch()} share this constant instead of each
     * hard-coding the literal.
     */
    public const DEFAULT_SSH = '/usr/bin/ssh';

    /**
     * Resolution order when neither `--ssh` nor `SSH_LAUNCHER_SSH` names a
     * binary (-075: Linux, NixOS, Apple-Silicon Homebrew). No `$PATH`
     * search is performed — `pcntl_exec` must receive exactly the path the
     * CLI gate vetted, so a hostile PATH cannot swap the binary mid-lane.
     *
     * @var list<string>
     */
    public const SSH_CANDIDATES = [
        '/usr/bin/ssh',
        '/run/current-system/sw/bin/ssh',
        '/opt/homebrew/bin/ssh',
    ];

    /**
     * First candidate that is an existing executable file, or null when
     * none is — the caller then falls back to {@see DEFAULT_SSH} and the
     * honest exit-1 refusal names the default.
     *
     * @param list<string> $candidates
     */
    public static function firstExecutableSsh(array $candidates = self::SSH_CANDIDATES): ?string
    {
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /** @var callable(string,list<string>): void */
    private $executor;

    /**
     * -027: transport-drop notices ride here (STDERR unless a test swaps
     * in a capture handle — the house Config::setWarningStream pattern,
     * warnings must never need a call-site opt-in).
     * @var resource|null
     */
    private $warningStream = null;

    /**
     * @param resource $stream
     */
    public function setWarningStream($stream): void
    {
        $this->warningStream = $stream;
    }

    /**
     * @param (callable(string,list<string>): void)|null $executor
     */
    public function __construct(?callable $executor = null)
    {
        $this->executor = $executor ?? static function (string $bin, array $args): void {
            // pcntl_exec wants the binary path + arg list (without
            // argv[0]). On success it never returns.
            //
            // -080: PHP reports the OS reason (ENOENT/EACCES/ENOEXEC)
            // as an E_WARNING that used to vanish behind the generic
            // exec_failed throw. The @ idiom suppresses display while
            // error_get_last() still records it (a userland handler
            // would consume it unrecorded); the before/after compare
            // keeps an unrelated stale error from being mislabeled as
            // the exec reason. bin's stderr boundary sanitizes the
            // spliced text at the CLI edge.
            $before = error_get_last()['message'] ?? null;
            @\pcntl_exec($bin, $args);
            $last = error_get_last()['message'] ?? null;
            $reason = $last !== null && $last !== $before ? $last : null;
            // If we got here, exec failed.
            throw new \RuntimeException(
                Lang::t('launcher.exec_failed', ['bin' => $bin])
                . ($reason !== null ? ': ' . $reason : ''),
            );
        };
    }

    /**
     * Dispatch into the chosen endpoint. On success, the PHP
     * process is replaced and this method does not return; on
     * failure the executor throws.
     *
     * -027: an endpoint carrying `command` replaces the ssh binary for
     * itself — same exists-and-executable gate `--ssh` passes at the
     * CLI edge (a refusal throws by name instead of handing `pcntl_exec`
     * a path nobody vetted), and every ssh-only leg it would have to
     * drop (`-i`/`-J`/`-o`/remote command) gets one stderr line naming
     * the endpoint and the dropped fields, so a silently trimmed argv
     * never reaches the wire.
     */
    public function dispatch(Endpoint $e, string $sshBinary = self::DEFAULT_SSH): void
    {
        $command = $e->command !== null && $e->command !== '' ? $e->command : null;
        if ($command === null) {
            $argv = $e->toSshArgv($sshBinary);
            ($this->executor)($argv[0], array_slice($argv, 1));
            return;
        }
        if (!is_file($command) || !is_executable($command)) {
            throw new \RuntimeException(
                Lang::t('launcher.transport_not_executable', ['bin' => $command])
            );
        }
        $dropped = $e->transportIncompatibles();
        if ($dropped !== []) {
            fwrite(
                $this->warningStream ?? STDERR,
                Lang::t('launcher.transport_dropped', [
                    'endpoint' => Sanitize::untrustedForDisplay($e->name),
                    'legs'     => implode(', ', $dropped),
                ]) . "\n",
            );
        }
        $argv = $e->toTransportArgv($command);
        ($this->executor)($argv[0], array_slice($argv, 1));
    }
}

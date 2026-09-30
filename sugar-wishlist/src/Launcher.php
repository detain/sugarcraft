<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist;

/**
 * Replaces the current PHP process with `ssh(1)` connecting to the
 * chosen {@see Endpoint}. Uses `pcntl_exec()` so file descriptors,
 * environment, controlling tty, and exit status all flow through
 * unchanged — the user sees a normal `ssh` session, including
 * host-key prompts, agent forwarding, and the standard MOTD.
 *
 * `dispatch()` never returns normally: on success the process image
 * is replaced, and on failure the default executor THROWS a
 * `RuntimeException` naming the binary (which propagates out of
 * `dispatch()`). ext-pcntl is a hard composer requirement, so there
 * is no runtime feature probe here — an absent extension fails at
 * install time, not at dispatch time.
 *
 * For tests, `executor` is a callable receiving the argv list —
 * defaults to `pcntl_exec` but tests can swap it for a recorder.
 */
final class Launcher
{
    /**
     * Canonical default ssh binary. `pcntl_exec` does not search
     * `$PATH`, so the launcher takes an absolute path; `bin/wishlist`
     * and {@see dispatch()} share this constant instead of each
     * hard-coding the literal.
     */
    public const DEFAULT_SSH = '/usr/bin/ssh';

    /** @var callable(string,list<string>): void */
    private $executor;

    /**
     * @param callable(string,list<string>): void|null $executor
     */
    public function __construct(?callable $executor = null)
    {
        $this->executor = $executor ?? static function (string $bin, array $args): void {
            // pcntl_exec wants the binary path + arg list (without
            // argv[0]). On success it never returns.
            \pcntl_exec($bin, $args);
            // If we got here, exec failed.
            throw new \RuntimeException(Lang::t('launcher.exec_failed', ['bin' => $bin]));
        };
    }

    /**
     * Dispatch into the chosen endpoint. On success, the PHP
     * process is replaced and this method does not return; on
     * failure the executor throws.
     */
    public function dispatch(Endpoint $e, string $sshBinary = self::DEFAULT_SSH): void
    {
        $argv = $e->toSshArgv($sshBinary);
        ($this->executor)($argv[0], array_slice($argv, 1));
    }
}

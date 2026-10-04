<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

/**
 * The runtime prerequisites `sugarcrush serve` refuses to start without
 * (Appendix O §8.7, §9.4; o0-spikes (c)).
 *
 * - **pcntl.** Without `pcntl_fork()` a turn falls back to
 *   `EngineBackend::completeAsyncBlocking()`, which runs on the loop thread —
 *   on a server that stalls every session and every socket for the whole turn.
 * - **posix.** Process identity (the root refusal below) and the signals the
 *   forked turn children are driven with.
 * - **FFI.** A turn child closes the server's inherited sockets with libc
 *   `close(2)` ({@see \SugarCraft\Crush\Support\ForkedChild::closeInheritedServerFds()}).
 *   There is no FFI-less fallback, and without it the listener leaks into
 *   every turn: the port stays bound after the server stops and late clients
 *   hang in a backlog nobody reads. A server that cannot close it refuses to
 *   start rather than leak it silently.
 * - **not root**, unless `--allow-root`: the server runs arbitrary code for
 *   whoever holds its token.
 *
 * The facts are injected rather than probed inline so a test can state "no
 * FFI" on a box that has it; {@see detect()} is the production reading.
 */
final class Preflight
{
    private function __construct(
        public readonly bool $pcntl,
        public readonly bool $posix,
        public readonly bool $ffi,
        public readonly ?int $euid,
    ) {
    }

    /** This process, as it actually is. */
    public static function detect(): self
    {
        return new self(
            \function_exists('pcntl_fork') && \function_exists('pcntl_waitpid'),
            \function_exists('posix_geteuid') && \function_exists('posix_kill'),
            self::ffiUsable(),
            \function_exists('posix_geteuid') ? \posix_geteuid() : null,
        );
    }

    /** A stated environment, for tests and for the doctor's explanation. */
    public static function of(bool $pcntl, bool $posix, bool $ffi, ?int $euid): self
    {
        return new self($pcntl, $posix, $ffi, $euid);
    }

    /**
     * The missing extensions, one sentence each — what `doctor` warns about,
     * independent of any configuration.
     *
     * @return list<string>
     */
    public function environmentProblems(): array
    {
        $problems = [];
        if (!$this->pcntl) {
            $problems[] = 'ext-pcntl is missing: without fork every turn would run on the server loop and stall every session';
        }
        if (!$this->posix) {
            $problems[] = 'ext-posix is missing: the server cannot identify or signal its turn processes';
        }
        if (!$this->ffi) {
            $problems[] = 'ext-ffi is missing or disabled (ffi.enable): a turn could not close the server sockets it inherits, so the port would leak into every turn';
        }

        return $problems;
    }

    /**
     * Every reason $config may not start here: the environment's, the root
     * refusal, then the configuration's own.
     *
     * @return list<string>
     */
    public function problems(ServerConfig $config): array
    {
        $problems = $this->environmentProblems();
        if ($this->euid === 0 && !$config->allowRoot) {
            $problems[] = 'refusing to run as root: the server runs code for whoever holds its token — use an unprivileged user, or pass --allow-root';
        }

        return [...$problems, ...$config->problems()];
    }

    private static function ffiUsable(): bool
    {
        if (!\extension_loaded('ffi')) {
            return false;
        }

        try {
            \FFI::cdef('int close(int fd);');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

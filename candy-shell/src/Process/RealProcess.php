<?php

declare(strict_types=1);

namespace CandyCore\Shell\Process;

/**
 * Production {@see Process} backed by `proc_open`. The child's stdout
 * and stderr are inherited from the parent so the spinner overlays the
 * command's output naturally; redirect with shell pipes if you want
 * silent execution.
 */
final class RealProcess implements Process
{
    /** @var resource */
    private $handle;
    private ?int $cachedExit = null;
    private bool $closed = false;

    /**
     * @param list<string>|string $command
     */
    public static function spawn(array|string $command): self
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ];
        $pipes  = [];
        $handle = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($handle)) {
            throw new \RuntimeException('failed to spawn child process');
        }
        return new self($handle);
    }

    /** @param resource $handle */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function exitCode(): ?int
    {
        if ($this->cachedExit !== null) {
            return $this->cachedExit;
        }
        if ($this->closed) {
            return $this->cachedExit;
        }
        $status = proc_get_status($this->handle);
        if ($status['running']) {
            return null;
        }
        $this->cachedExit = (int) $status['exitcode'];
        return $this->cachedExit;
    }

    public function terminate(): void
    {
        if ($this->closed || $this->cachedExit !== null) {
            return;
        }
        @proc_terminate($this->handle);
    }

    /**
     * Reap the OS process handle. Always calls `proc_close()` exactly
     * once even after `exitCode()` has cached the status — without that,
     * long-running PHP processes accumulated zombie entries because the
     * proc_open handle was never explicitly released.
     */
    public function close(): int
    {
        if ($this->closed) {
            return $this->cachedExit ?? 0;
        }
        $code = @proc_close($this->handle);
        $this->closed = true;
        if ($this->cachedExit === null) {
            $this->cachedExit = is_int($code) && $code >= 0 ? $code : 0;
        }
        return $this->cachedExit;
    }
}

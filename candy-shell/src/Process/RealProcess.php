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
        $status = proc_get_status($this->handle);
        if ($status['running']) {
            return null;
        }
        $this->cachedExit = (int) $status['exitcode'];
        return $this->cachedExit;
    }

    public function terminate(): void
    {
        if ($this->cachedExit !== null) {
            return;
        }
        @proc_terminate($this->handle);
    }

    public function close(): int
    {
        if ($this->cachedExit !== null) {
            return $this->cachedExit;
        }
        $code = @proc_close($this->handle);
        $this->cachedExit = $code === false ? -1 : (int) $code;
        return $this->cachedExit;
    }
}

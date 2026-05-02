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
    /** @var array{0:?resource,1:?resource} pipes for captured stdout/stderr */
    private array $pipes = [null, null];
    private string $bufferedStdout = '';
    private string $bufferedStderr = '';

    /**
     * @param list<string>|string $command
     */
    public static function spawn(
        array|string $command,
        bool $captureStdout = false,
        bool $captureStderr = false,
    ): self {
        $descriptors = [0 => ['file', '/dev/null', 'r']];
        $descriptors[1] = $captureStdout ? ['pipe', 'w'] : STDOUT;
        $descriptors[2] = $captureStderr ? ['pipe', 'w'] : STDERR;
        $pipes  = [];
        $handle = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($handle)) {
            throw new \RuntimeException('failed to spawn child process');
        }
        $self = new self($handle);
        $self->pipes[0] = $captureStdout && isset($pipes[1]) && is_resource($pipes[1]) ? $pipes[1] : null;
        $self->pipes[1] = $captureStderr && isset($pipes[2]) && is_resource($pipes[2]) ? $pipes[2] : null;
        // Non-blocking so SpinModel's poll loop never stalls reading.
        if ($self->pipes[0] !== null) {
            stream_set_blocking($self->pipes[0], false);
        }
        if ($self->pipes[1] !== null) {
            stream_set_blocking($self->pipes[1], false);
        }
        return $self;
    }

    /** @param resource $handle */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function exitCode(): ?int
    {
        // Drain pending pipe data before checking — otherwise the child
        // can deadlock waiting for the parent to read stdout.
        $this->drain();
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
        // Final drain to capture anything written during exit.
        $this->drain();
        return $this->cachedExit;
    }

    private function drain(): void
    {
        if ($this->pipes[0] !== null && is_resource($this->pipes[0])) {
            $chunk = @stream_get_contents($this->pipes[0]);
            if (is_string($chunk)) {
                $this->bufferedStdout .= $chunk;
            }
        }
        if ($this->pipes[1] !== null && is_resource($this->pipes[1])) {
            $chunk = @stream_get_contents($this->pipes[1]);
            if (is_string($chunk)) {
                $this->bufferedStderr .= $chunk;
            }
        }
    }

    public function stdout(): string { return $this->bufferedStdout; }
    public function stderr(): string { return $this->bufferedStderr; }

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
        // Final drain + close pipes before reaping the handle.
        $this->drain();
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $code = @proc_close($this->handle);
        $this->closed = true;
        if ($this->cachedExit === null) {
            $this->cachedExit = is_int($code) && $code >= 0 ? $code : 0;
        }
        return $this->cachedExit;
    }
}

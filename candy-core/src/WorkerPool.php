<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Msg\WorkerResultMsg;

/**
 * Bounded-concurrency worker pool for CPU-bound tasks.
 *
 * Uses {@see proc_open()} subprocesses that communicate via serialized
 * callables written to stdin and results read from stdout. The pool
 * is non-blocking: I/O is driven by the supplied ReactPHP event loop.
 *
 * Callables are serialized using {@see \Closure::serialize()} and
 * reconstructed in the subprocess. Only stateless or explicitly captured
 * state is preserved; bound objects and internal class refs may not
 * survive cross-process serialization.
 *
 * Mirrors charmbracelet/bubbletea's worker pool for offloading heavy
 * computation off the UI thread.
 */
final class WorkerPool
{
    private const DEFAULT_CONCURRENCY = 4;

    /** @var array<int, WorkerState> */
    private array $workers = [];

    /** @var list<array{0: string, 1: callable|string}> */
    private array $queue = [];

    private int $concurrency;

    private bool $started = false;

    /** @var TimerInterface|null */
    private ?TimerInterface $tickTimer = null;

    /** @var array<string, Deferred<WorkerResultMsg>> map: jobId => Deferred */
    private array $pending = [];

    private int $jobCounter = 0;

    /** Monotonic worker-id counter — never reuses ids after a worker's death. */
    private int $workerCounter = 0;

    public function __construct(
        private readonly LoopInterface $loop,
        int $concurrency = self::DEFAULT_CONCURRENCY,
    ) {
        $this->concurrency = max(1, $concurrency);
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    /**
     * Enqueue a callable for execution in the worker pool.
     * Returns a promise that resolves with the worker's result when done.
     *
     * @param callable|non-empty-string $task Named function or callable.
     *                                         PHP closures cannot be serialized;
     *                                         use named functions for cross-process work.
     *                                         String tasks must be the name of a globally-
     *                                         callable function; the worker validates via
     *                                         function_exists() before invoking.
     * @return PromiseInterface<WorkerResultMsg>
     */
    public function dispatch(callable|string $task): PromiseInterface
    {
        if (!$this->started) {
            $this->start();
        }

        $jobId = (string) ++$this->jobCounter;
        $deferred = new Deferred();
        $this->pending[$jobId] = $deferred;

        if ($this->hasIdleWorker()) {
            $worker = $this->popIdleWorker();
            $this->sendToWorker($worker, $jobId, $task);
        } elseif (count($this->workers) < $this->concurrency) {
            $this->spawnWorker($jobId, $task);
        } else {
            $this->queue[] = [$jobId, $task];
        }

        return $deferred->promise();
    }

    /**
     * Stop all workers and drain the queue.
     */
    public function stop(): void
    {
        if ($this->tickTimer !== null) {
            $this->loop->cancelTimer($this->tickTimer);
            $this->tickTimer = null;
        }

        foreach ($this->workers as $worker) {
            $this->closeWorker($worker);
        }
        $this->workers = [];
        $this->queue = [];
        $this->pending = [];
    }

    private function start(): void
    {
        $this->started = true;
        $this->tickTimer = $this->loop->addPeriodicTimer(0.05, function (): void {
            $this->tick();
        });
    }

    private function tick(): void
    {
        foreach ($this->workers as $worker) {
            $this->pollWorker($worker);
        }
    }

    private function pollWorker(WorkerState $worker): void
    {
        if (feof($worker->stdout)) {
            $this->handleWorkerDeath($worker, 'Worker process died unexpectedly');
            return;
        }

        if ($worker->buffer !== '') {
            $newlinePos = strpos($worker->buffer, "\n");
            while ($newlinePos !== false) {
                $line = substr($worker->buffer, 0, $newlinePos);
                $worker->buffer = substr($worker->buffer, $newlinePos + 1);
                $this->handleWorkerLine($worker, $line);
                $newlinePos = strpos($worker->buffer, "\n");
            }
        }

        $chunk = fread($worker->stdout, 8192);
        if ($chunk !== false && $chunk !== '') {
            $worker->buffer .= $chunk;

            $newlinePos = strpos($worker->buffer, "\n");
            while ($newlinePos !== false) {
                $line = substr($worker->buffer, 0, $newlinePos);
                $worker->buffer = substr($worker->buffer, $newlinePos + 1);
                $this->handleWorkerLine($worker, $line);
                $newlinePos = strpos($worker->buffer, "\n");
            }
        }
    }

    private function handleWorkerLine(WorkerState $worker, string $line): void
    {
        if ($line === '' || $line === 'READY') {
            return;
        }

        // The peer is a local child process; allowed_classes is defense-in-depth
        // against pipe corruption. We allow Throwable and common subclasses to
        // support error round-trips from worker subprocesses.
        $data = @unserialize(base64_decode($line, true), [
            'allowed_classes' => [
                \Throwable::class,
                \Exception::class,
                \Error::class,
                \RuntimeException::class,
                \InvalidArgumentException::class,
            ],
        ]);
        if (!is_array($data)) {
            $result = new WorkerResultMsg(
                result: null,
                error: new \RuntimeException('Malformed worker response: ' . $line),
                workerId: $worker->id,
            );
            $this->resolveJob($worker, $result);
            return;
        }

        $result = new WorkerResultMsg(
            result: $data['result'] ?? null,
            error: $data['error'] ?? null,
            workerId: $worker->id,
        );
        $this->resolveJob($worker, $result);
    }

    private function resolveJob(WorkerState $worker, WorkerResultMsg $result): void
    {
        $jobId = $worker->currentJobId;
        $worker->currentJobId = null;

        if ($jobId !== null && isset($this->pending[$jobId])) {
            $deferred = $this->pending[$jobId];
            unset($this->pending[$jobId]);
            if ($result->error !== null) {
                $deferred->reject($result->error);
            } else {
                $deferred->resolve($result);
            }
        }

        if ($result->error === null && $this->hasQueuedJob()) {
            [$nextJobId, $nextTask] = array_shift($this->queue);
            $this->sendToWorker($worker, $nextJobId, $nextTask);
        } else {
            $worker->idle = true;
            $this->workers[$worker->id] = $worker;
        }
    }

    /**
     * @param callable|string $task
     */
    private function sendToWorker(WorkerState $worker, string $jobId, callable|string $task): void
    {
        $worker->idle = false;
        $worker->currentJobId = $jobId;

        try {
            if (is_string($task)) {
                $payload = ['type' => 'function', 'name' => $task];
            } else {
                $payload = ['type' => 'callable', 'callable' => $task];
            }
            $serialized = base64_encode(serialize($payload));
        } catch (\Error $e) {
            $this->handleWorkerDeath($worker, 'Closure serialization failed: ' . $e->getMessage(), $jobId);
            return;
        }

        $written = @fwrite($worker->stdin, $serialized . "\n");
        if ($written === false || $written === 0) {
            $this->handleWorkerDeath($worker, 'Failed to write to worker stdin', $jobId);
            return;
        }
        @fflush($worker->stdin);
    }

    private function handleWorkerDeath(WorkerState $worker, string $reason, ?string $jobIdOverride = null): void
    {
        $jobId = $jobIdOverride ?? $worker->currentJobId;

        $this->closeWorker($worker);
        unset($this->workers[$worker->id]);

        if ($jobId !== null && isset($this->pending[$jobId])) {
            $deferred = $this->pending[$jobId];
            unset($this->pending[$jobId]);
            $deferred->reject(new \RuntimeException($reason));
        }

        if ($this->hasQueuedJob()) {
            [$nextJobId, $nextTask] = array_shift($this->queue);
            if (count($this->workers) < $this->concurrency) {
                $this->spawnWorker($nextJobId, $nextTask);
            } else {
                $this->queue[] = [$nextJobId, $nextTask];
            }
        }
    }

    private function spawnWorker(string $currentJobId, callable|string $task): void
    {
        $workerId = $this->workerCounter++;

        $scriptPath = $this->createWorkerScript();
        if ($scriptPath === false) {
            $this->handleWorkerDeath(new WorkerState($workerId, null, null, null, null), 'Failed to create worker script', $currentJobId);
            return;
        }

        $env = ['HOME' => getenv('HOME') ?: '/tmp'];

        $process = proc_open(
            [PHP_BINARY, $scriptPath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env,
            ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            @unlink($scriptPath);
            $this->handleWorkerDeath(new WorkerState($workerId, null, null, null, null), 'Failed to start worker process', $currentJobId);
            return;
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        stream_set_blocking($pipes[0], true);

        $stdin = $pipes[0];
        $stdout = $pipes[1];
        $stderr = $pipes[2];

        $worker = new WorkerState(
            id: $workerId,
            process: $process,
            stdin: $stdin,
            stdout: $stdout,
            stderr: $stderr,
        );
        $worker->scriptPath = $scriptPath;

        $this->loop->addReadStream($stderr, function ($stream): void {
            $chunk = fread($stream, 4096);
            if (feof($stream)) {
                $this->loop->removeReadStream($stream);
            }
        });

        $this->workers[$workerId] = $worker;

        $this->sendToWorker($worker, $currentJobId, $task);
    }

    private function createWorkerScript(): string|false
    {
        $code = <<<'PHP'
<?php
$fd = fopen('php://fd/0', 'rb');
if ($fd === false) {
    exit(1);
}
stream_set_timeout($fd, -1);
while (!feof($fd)) {
    $line = fgets($fd);
    if ($line === false || $line === '') {
        break;
    }
    $line = rtrim($line, "\n");
    if ($line === '') {
        continue;
    }
    $decoded = base64_decode($line, true);
    if ($decoded === false) {
        $response = base64_encode(serialize(['result' => null, 'error' => new \RuntimeException('Invalid base64 input')]));
        echo $response . "\n";
        fflush(STDOUT);
        continue;
    }
    // Note: the peer is a local child process; allowed_classes is defense-in-depth
    // against pipe corruption rather than an untrusted network peer.
    $payload = unserialize($decoded);
    if (!is_array($payload)) {
        $response = base64_encode(serialize(['result' => null, 'error' => new \RuntimeException('Malformed payload')]));
        echo $response . "\n";
        fflush(STDOUT);
        continue;
    }
    try {
        if (($payload['type'] ?? '') === 'callable') {
            $callable = $payload['callable'] ?? null;
            if ($callable === null) {
                throw new \RuntimeException('Missing callable in payload');
            }
            $result = $callable();
        } else {
            // String form is a function name — validated in the worker
            // so the parent process does not need the function defined.
            $name = $payload['name'] ?? null;
            if (!is_string($name) || !function_exists($name)) {
                throw new \RuntimeException('Unknown worker function: ' . var_export($name, true));
            }
            $result = $name();
        }
        $response = base64_encode(serialize(['result' => $result, 'error' => null]));
    } catch (\Throwable $e) {
        $response = base64_encode(serialize(['result' => null, 'error' => $e]));
    }
    echo $response . "\n";
    fflush(STDOUT);
}
exit(0);
PHP;

        $path = tempnam(sys_get_temp_dir(), 'sc_worker_');
        if ($path === false) {
            return false;
        }
        $written = file_put_contents($path, $code);
        if ($written === false) {
            @unlink($path);
            return false;
        }
        chmod($path, 0700);
        return $path;
    }

    private function hasIdleWorker(): bool
    {
        foreach ($this->workers as $worker) {
            if ($worker->idle && $worker->currentJobId === null) {
                return true;
            }
        }
        return false;
    }

    private function popIdleWorker(): WorkerState
    {
        foreach ($this->workers as $worker) {
            if ($worker->idle && $worker->currentJobId === null) {
                $worker->idle = false;
                return $worker;
            }
        }
        throw new \RuntimeException('No idle worker available');
    }

    private function hasQueuedJob(): bool
    {
        return count($this->queue) > 0;
    }

    private function closeWorker(WorkerState $worker): void
    {
        $this->loop->removeReadStream($worker->stderr);

        if ($worker->stdin !== null && is_resource($worker->stdin)) {
            @fclose($worker->stdin);
        }
        if ($worker->stdout !== null && is_resource($worker->stdout)) {
            @fclose($worker->stdout);
        }
        if ($worker->stderr !== null && is_resource($worker->stderr)) {
            @fclose($worker->stderr);
        }
        if ($worker->process !== null && is_resource($worker->process)) {
            proc_close($worker->process);
        }

        // Unlink the worker's temp script exactly once.
        if ($worker->scriptPath !== null && is_file($worker->scriptPath)) {
            @unlink($worker->scriptPath);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}

/**
 * @internal
 */
final class WorkerState
{
    public bool $idle = true;

    public ?string $currentJobId = null;

    public string $buffer = '';

    /** Path to the worker's temp script, for cleanup on close. */
    public ?string $scriptPath = null;

    public function __construct(
        public readonly int $id,
        public $process,
        public $stdin,
        public $stdout,
        public $stderr,
    ) {
    }
}

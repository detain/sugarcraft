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
 * Task transport (sc-29, finding sugar-core-007): the ONLY task surface
 * is a serializable PHP callable — `[Foo::class, 'bar']`, an invokable
 * object, or similar. A `Closure` — directly or captured anywhere inside
 * the callable — cannot be serialized; such a dispatch() still returns a
 * promise, already REJECTED with a RuntimeException naming the
 * serialization failure, rather than throwing or hanging. The former
 * string-task channel (`dispatch('some_global')` → the child calls
 * `function_exists($name) && $name()`) is REMOVED: it handed every
 * globally-defined function to the child as an execution surface with no
 * relationship to the caller's code, and the autoloader leg below made
 * it redundant (class callables resolve in-child now). A string task
 * throws a named InvalidArgumentException at the door.
 *
 * The child bootstraps the library's own autoloader (path passed
 * explicitly via argv), merges the parent environment instead of
 * replacing it, and unserializes through a two-stage envelope so the
 * task's class allow-list travels WITH the task rather than being
 * guessed at.
 *
 * Pipe and timer hygiene (sc-30): stdin writes drain NON-BLOCKING against
 * a hard deadline instead of freezing the event loop once a payload
 * outruns the ~64 KiB kernel pipe; the 20 Hz stdout poll timer is armed
 * only while work is in flight and released when the pool drains, so a
 * created-and-forgotten pool costs nothing; and each worker keeps a
 * bounded stderr tail that failure rejections surface verbatim.
 *
 * Mirrors the worker-pool pattern of charmbracelet/bubbletea, the machinery for offloading heavy
 * computation off the UI thread.
 *
 * Child lifetime is owned by the pool: {@see stop()} — which the destructor
 * also runs — closes each worker's stdin, waits a bounded grace, escalates
 * SIGTERM→SIGKILL, and reaps, so a wedged task can delay shutdown by seconds
 * but never forever, and no worker outlives the pool.
 */
final class WorkerPool
{
    private const DEFAULT_CONCURRENCY = 4;

    /** Seconds a worker gets to notice its closed stdin and exit on its own. */
    private const REAP_GRACE_SECONDS = 1.5;

    /** Seconds between SIGTERM and escalation to SIGKILL. */
    private const REAP_TERM_SECONDS = 1.0;

    /** Window to confirm a SIGKILL landed (9 cannot be caught; a leash, not a hope). */
    private const REAP_KILL_SECONDS = 1.0;

    /** Poll granularity for the reap ladder, in microseconds. */
    private const REAP_TICK_MICROS = 10_000;

    /**
     * sc-30: ceiling on how long a stdin write may drain before the worker
     * is declared dead. Bounded on purpose — the old blocking fwrite froze
     * the WHOLE event loop forever once a >64 KiB payload outran the pipe
     * buffer (the kernel pipe capacity) with a child that was not reading.
     */
    private const WRITE_DEADLINE_SECONDS = 10.0;

    /** Retry granularity for the non-blocking stdin drain loop, in microseconds. */
    private const WRITE_POLL_MICROS = 2_000;

    /** sc-30: how much of a worker's stderr the pool keeps for failure reports. */
    private const STDERR_TAIL_BYTES = 4096;

    /** Literal signal numbers: sugar-core does not require ext-pcntl and proc_terminate() takes the number directly. */
    private const SIGTERM = 15;
    private const SIGKILL = 9;

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
     * @param callable|non-empty-string $task Serializable callable — a static
     *                                         method array `[Class::class, 'm']`
     *                                         or an invokable object. Strings
     *                                         (the removed global-function
     *                                         channel) throw a named
     *                                         InvalidArgumentException;
     *                                         Closures reject the promise.
     * @return PromiseInterface<WorkerResultMsg>
     */
    public function dispatch(callable|string $task): PromiseInterface
    {
        if (is_string($task)) {
            // sc-29: the string channel is removed. Fail at the door with
            // the replacement contract in the message — before any Deferred
            // is parked, so nothing can hang on the refusal.
            throw new \InvalidArgumentException(Lang::t('worker.string_task_removed', ['name' => $task]));
        }

        if (!$this->started) {
            $this->start();
        }

        // sc-30: a child that dies while parked is discovered HERE, at the
        // next use, not by a poll timer — the released tick deliberately
        // costs an idle pool nothing. Pruning the corpse first keeps both
        // promises the old eager poll made: the concurrency slot is freed
        // for a live replacement, and worker ids stay unique across death
        // (workerCounter never rewinds).
        $this->pruneDeadIdleWorkers();

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

        // sc-30: the poll tick exists to advance IN-FLIGHT work. Arm it here
        // (idempotently) instead of for-good in start(): a pool that is
        // created and left alone — every example program that never calls
        // stop() — no longer burns a 20 Hz wake forever.
        $this->armTick();

        return $deferred->promise();
    }

    /**
     * Stop all workers and drain the queue.
     *
     * Every job that has not settled yet — running or queued — is REJECTED
     * with a RuntimeException naming the pool stop. Silently dropping the
     * pending promises would leave dispatch() callers awaiting a result that
     * can never arrive — fail loud, never hang.
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

        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $jobId => $deferred) {
            $deferred->reject(new \RuntimeException("Worker pool stopped before job {$jobId} completed"));
        }

        // A later dispatch() must flow through start() again to re-arm the tick
        // timer. Leaving $started true resurrected workers whose stdout was
        // never polled — jobs ran, promises never settled, and the child was
        // reaped only whenever the next stop/destruct came along (audit 2026-09-30).
        $this->started = false;
    }

    private function start(): void
    {
        // sc-30: the tick timer is armed/released by dispatch() and the
        // completion paths (armTick/releaseTickIfIdle), not here — see the
        // idle-20 Hz leak this removed. start() now only records liveness.
        $this->started = true;
    }

    /** Idempotent: (re-)arm the 20 Hz stdout poll while work is in flight. */
    private function armTick(): void
    {
        if ($this->tickTimer === null) {
            $this->tickTimer = $this->loop->addPeriodicTimer(0.05, function (): void {
                $this->tick();
            });
        }
    }

    /**
     * sc-30: once nothing is pending or queued, no response can arrive that
     * needs polling — release the timer. Idle pooled children stay alive and
     * silent; their death while parked is discovered at the next dispatch
     * (a write failure or feof takes the same death path), which is cheaper
     * than paying a perpetual 20 Hz wake for a fact with no observers.
     */
    private function releaseTickIfIdle(): void
    {
        if ($this->pending === [] && $this->queue === [] && $this->tickTimer !== null) {
            $this->loop->cancelTimer($this->tickTimer);
            $this->tickTimer = null;
        }
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
        // against pipe corruption. sc-29 widened the list to the library's own
        // throwable surface (the concrete Exception/* classes, matched EXACTLY —
        // PHP allow-lists are by-name, not by-lineage) so a task failure that
        // crosses back as a ProgramException arrives as the real class instead
        // of a __PHP_Incomplete_Class shrug. The full result-envelope decision
        // (values-as-objects, JSON alternative) is sc-33's; this is the
        // error-shape floor, written at both ends.
        $decoded = base64_decode($line, true);
        // The child always writes strict-mode-valid base64; a frame that
        // fails even base64_decode is corruption and must travel the SAME
        // redacted malformed path, not TypeError out of unserialize(false).
        $data = $decoded === false ? false : @unserialize($decoded, [
            'allowed_classes' => [
                \Throwable::class,
                \Exception::class,
                \Error::class,
                \RuntimeException::class,
                \InvalidArgumentException::class,
                \SugarCraft\Core\Exception\ProgramException::class,
                \SugarCraft\Core\Exception\RenderException::class,
                \SugarCraft\Core\Exception\TerminalException::class,
                \SugarCraft\Core\Exception\SemaphoreClosedException::class,
            ],
        ]);
        if (!is_array($data)) {
            // Raw protocol bytes never ride into an exception message
            // (sc-29): a response line embeds serialized user task data,
            // and exceptions travel to logs/UIs that must not see it.
            // Length + crc32 digest identify the frame without leaking
            // its contents.
            $result = new WorkerResultMsg(
                result: null,
                error: new \RuntimeException(Lang::t('worker.malformed_response', [
                    'length' => strlen($line),
                    'digest' => sprintf('%08x', crc32($line)),
                ])),
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

        // Reconcile the queue on ANY completion — error or success. The worker
        // is free either way, and nothing else pairs idle workers with queued
        // jobs: tick() only polls stdout, so gating the hand-off on success
        // stalled everything queued behind a failing job forever (audit 2026-09-30).
        if ($this->hasQueuedJob()) {
            [$nextJobId, $nextTask] = array_shift($this->queue);
            $this->sendToWorker($worker, $nextJobId, $nextTask);
        } else {
            $worker->idle = true;
            $this->workers[$worker->id] = $worker;
        }

        // sc-30: this is the drain point — if the job we just settled (and
        // any hand-off above) left nothing in flight, the poll is no longer
        // doing anything.
        $this->releaseTickIfIdle();
    }

    /**
     * @param callable|string $task
     */
    private function sendToWorker(WorkerState $worker, string $jobId, callable|string $task): void
    {
        $worker->idle = false;
        $worker->currentJobId = $jobId;

        try {
            // Two-stage envelope (sc-29): the OUTER array is scalars only —
            // the child unserializes it with allowed_classes=false — and it
            // carries both the task blob and the EXACT class list the task
            // needs. An allow-list cannot be decided after the objects it
            // guards have already been instantiated, so the parent (which
            // holds the real callable) computes the list from that callable
            // instead of the child guessing.
            $payload = [
                'v'        => 1,
                'callable' => base64_encode(serialize($task)),
                'allowed'  => self::taskClasses($task),
            ];
            $serialized = base64_encode(serialize($payload));
        } catch (\Throwable $e) {
            // \Throwable, not \Error: serialize() of a Closure (directly, or
            // captured inside an object/array callable) throws \Exception
            // ("Serialization of 'Closure' is not allowed"). Catching only
            // \Error let that escape dispatch() synchronously with the job's
            // Deferred already parked in $pending — a promise nobody could
            // ever settle — fail loud, never hang.
            //
            // The WORKER is healthy — nothing reached its stdin — so this is
            // a job failure, not a worker death: reject through resolveJob(),
            // which also hands the free worker the next queued job.
            $this->resolveJob($worker, new WorkerResultMsg(
                result: null,
                error: new \RuntimeException('Task serialization failed: ' . $e->getMessage(), 0, $e),
                workerId: $worker->id,
            ));
            return;
        }

        // sc-30: the payload goes out through a NON-BLOCKING drain loop with
        // a hard deadline. The kernel pipe holds ~64 KiB; the old blocking
        // fwrite on a child that had not started reading (or a task whose
        // serialized envelope simply exceeded the pipe) stalled the entire
        // ReactPHP event loop — UI, timers, every other worker — until the
        // child drained it, i.e. indefinitely for a wedged boot. Now: write
        // what fits, retry on a 2 ms poll, and after WRITE_DEADLINE_SECONDS
        // declare the worker dead with a named error — bounded, diagnosable,
        // and the promise settles either way.
        $data = $serialized . "\n";
        $length = strlen($data);
        $offset = 0;
        $deadline = microtime(true) + self::WRITE_DEADLINE_SECONDS;
        while (true) {
            $written = @fwrite($worker->stdin, substr($data, $offset));
            if ($written === false) {
                $this->handleWorkerDeath($worker, 'Failed to write to worker stdin' . $this->stderrDetail($worker), $jobId);
                return;
            }
            $offset += $written;
            if ($offset >= $length) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->handleWorkerDeath($worker, Lang::t('worker.stdin_write_timeout', [
                    'seconds'   => self::WRITE_DEADLINE_SECONDS,
                    'written'   => $offset,
                    'total'     => $length,
                ]) . $this->stderrDetail($worker), $jobId);
                return;
            }
            usleep(self::WRITE_POLL_MICROS);
        }
        @fflush($worker->stdin);
    }

    /**
     * The classes an unserialize of this callable needs, exactly.
     *
     * `[Class::class, 'm']` arrays are pure strings — they instantiate
     * nothing (the method resolves via the autoloader at CALL time, not
     * at unserialize time). Only object-carrying callables (invokables,
     * `[$obj, 'm']`) materialise a class during unserialize, and then it
     * is exactly that one object's class. Computing this from the REAL
     * callable at the trusted parent end is what lets the child keep an
     * `allowed_classes` list without inventing one.
     *
     * @return list<string>
     */
    private static function taskClasses(callable $task): array
    {
        if (is_object($task)) {
            return [get_class($task)];
        }
        if (is_array($task) && isset($task[0]) && is_object($task[0])) {
            return [get_class($task[0])];
        }
        return [];
    }

    /**
     * Absolute path of this library's autoloader, handed to every worker
     * child as argv[1] (sc-29). The child's whole class-visibility surface
     * comes from here: without it, a task naming ANY library class died
     * in-child with a bare "Class not found". dirname(__DIR__) is the
     * library root because this file lives in src/ — cwd-independent.
     */
    private static function autoloadPath(): string
    {
        return dirname(__DIR__) . '/vendor/autoload.php';
    }

    private function handleWorkerDeath(WorkerState $worker, string $reason, ?string $jobIdOverride = null): void
    {
        $jobId = $jobIdOverride ?? $worker->currentJobId;

        // sc-30: the child's stderr used to be drained and thrown away, so a
        // fatal in the worker reached the caller as 'died unexpectedly' with
        // zero diagnosis. The bounded tail rides into the rejection now.
        // Drain synchronously BEFORE reading the tail: death is detected on
        // the stdout feof, and a last-second stderr write may still be
        // sitting unread in the pipe — the watcher and the poll race.
        if ($worker->stderr !== null && is_resource($worker->stderr)) {
            while (true) {
                $chunk = @fread($worker->stderr, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $worker->stderrTail .= $chunk;
                $cap = self::STDERR_TAIL_BYTES;
                if (strlen($worker->stderrTail) > $cap) {
                    $worker->stderrTail = substr($worker->stderrTail, -$cap);
                }
            }
        }
        $detail = $this->stderrDetail($worker);
        $this->closeWorker($worker);
        unset($this->workers[$worker->id]);

        if ($jobId !== null && isset($this->pending[$jobId])) {
            $deferred = $this->pending[$jobId];
            unset($this->pending[$jobId]);
            $deferred->reject(new \RuntimeException($reason . $detail));
        }

        if ($this->hasQueuedJob()) {
            [$nextJobId, $nextTask] = array_shift($this->queue);
            if (count($this->workers) < $this->concurrency) {
                $this->spawnWorker($nextJobId, $nextTask);
            } else {
                $this->queue[] = [$nextJobId, $nextTask];
            }
        }

        $this->releaseTickIfIdle();
    }

    /**
     * The kept stderr tail of one worker, formatted for a failure message
     * ('' when the child was silent). Bounded by STDERR_TAIL_BYTES at the
     * source, so this string has a ceiling.
     */
    private function stderrDetail(WorkerState $worker): string
    {
        $tail = trim($worker->stderrTail);
        return $tail === '' ? '' : ' (worker stderr: ' . $tail . ')';
    }

    private function spawnWorker(string $currentJobId, callable|string $task): void
    {
        $workerId = $this->workerCounter++;

        $scriptPath = $this->createWorkerScript();
        if ($scriptPath === false) {
            $this->handleWorkerDeath(new WorkerState($workerId, null, null, null, null), 'Failed to create worker script', $currentJobId);
            return;
        }

        // MERGE, not replace (sc-29): proc_open's env array is the child's
        // WHOLE environment, so the old ['HOME' => ...] literal stripped
        // PATH (and everything else) — any task that shelled out or
        // resolved a binary died in-child with no clue why. getenv() hands
        // us the parent's actual block; HOME keeps its old floor because
        // some harnesses run with it unset and PHP's own startup wants it.
        $env = getenv();
        if (($env['HOME'] ?? '') === '') {
            $env['HOME'] = '/tmp';
        }

        $process = proc_open(
            [PHP_BINARY, $scriptPath, self::autoloadPath()],
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

        // sc-30: every pipe stays NON-BLOCKING. The old re-block of stdin fed
        // the freeze fixed in sendToWorker(); stdout/stderr were already
        // non-blocking for the poll read side.
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

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

        // sc-30: the stderr drain kept the 64 KiB pipe from ever backing up
        // (a child that wrote to stderr would freeze on its own write, which
        // is how pool death used to look) but discarded everything it read.
        // Now the bytes land in a bounded keep-last tail on the state, and
        // handleWorkerDeath surfaces it — child fatal text is finally visible
        // to whoever owns the promise.
        $this->loop->addReadStream($stderr, function ($stream) use ($worker): void {
            $chunk = fread($stream, 4096);
            if ($chunk !== false && $chunk !== '') {
                $worker->stderrTail .= $chunk;
                $cap = self::STDERR_TAIL_BYTES;
                if (strlen($worker->stderrTail) > $cap) {
                    $worker->stderrTail = substr($worker->stderrTail, -$cap);
                }
            }
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
// Worker child for SugarCraft\Core\WorkerPool (sc-29 contract): one
// base64 envelope per stdin line, one base64 response per stdout line.
// argv[1] is the library autoloader the parent names — required FIRST,
// before anything else touches a class: a task naming any library class
// died in-child with a bare "Class not found" until this line existed.
$autoload = isset($argv[1]) && is_file($argv[1]) ? $argv[1] : null;
if ($autoload !== null) {
    require $autoload;
}
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
    // Outer envelope: scalars only, so allowed_classes=false is the
    // COMPLETE policy for this stage — nothing object-shaped may be
    // constructed before we have read (and honoured) the allow-list.
    $envelope = @unserialize($decoded, ['allowed_classes' => false]);
    if (!is_array($envelope) || ($envelope['v'] ?? null) !== 1
        || !isset($envelope['callable']) || !is_string($envelope['callable'])
        || !isset($envelope['allowed']) || !is_array($envelope['allowed'])) {
        $response = base64_encode(serialize(['result' => null, 'error' => new \RuntimeException('Malformed worker envelope')]));
        echo $response . "\n";
        fflush(STDOUT);
        continue;
    }
    try {
        $inner = base64_decode($envelope['callable'], true);
        if ($inner === false) {
            throw new \RuntimeException('Invalid task encoding');
        }
        // The task object materialises ONLY from the class list the
        // parent computed from the real callable — not from a guess,
        // and not unlimited. (The string-task/function_exists channel
        // that once lived here is removed; closures were never
        // serializable and array/object callables resolve through the
        // autoloader.)
        $callable = unserialize($inner, ['allowed_classes' => $envelope['allowed']]);
        if (!is_callable($callable)) {
            throw new \RuntimeException('Worker payload is not callable');
        }
        $result = $callable();
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

    /**
     * Drop parked workers whose child has died. One non-blocking read per
     * idle stdout is what sets PHP's EOF flag — feof() alone only reports a
     * previously-seen EOF — so the probe read doubles as the liveness test,
     * and any real bytes it catches are pushed back onto the worker buffer
     * untouched (idle-with-pending is protocol-wise impossible; if it ever
     * happens the bytes die with the corpse, which is the honest outcome).
     */
    private function pruneDeadIdleWorkers(): void
    {
        foreach ($this->workers as $id => $worker) {
            if (!$worker->idle || $worker->currentJobId !== null || $worker->stdout === null) {
                continue;
            }
            if (!is_resource($worker->stdout)) {
                $this->closeWorker($worker);
                unset($this->workers[$id]);
                continue;
            }
            $probe = @fread($worker->stdout, 4096);
            if ($probe !== false && $probe !== '') {
                $worker->buffer = $probe . $worker->buffer;
            }
            if (feof($worker->stdout)) {
                $this->closeWorker($worker);
                unset($this->workers[$id]);
            }
        }
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
        // Only a live stderr pipe was ever registered; a synthetic WorkerState
        // (spawn failed before any pipe existed) carries null. Stock React
        // loops cast that null to key 0 and unset unconditionally — but their
        // readStreams are keyed by PHP resource ID (≥1, never fd), so key 0
        // cannot hold any registration and the pre-guard cast was a silent
        // no-op. The guard is prophylaxis: it forecloses fd-keyed/custom
        // LoopInterface implementations and closed-resource-ID-reuse shapes
        // where the collision WOULD be live.
        if ($worker->stderr !== null && is_resource($worker->stderr)) {
            $this->loop->removeReadStream($worker->stderr);
        }

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
            // Closing stdin above is the polite "leave" for a healthy worker —
            // its read loop hits EOF and exits. A worker wedged INSIDE a task
            // never notices, so bound the wait and escalate; without this the
            // bare proc_close() handed the event loop (or PHP shutdown via
            // __destruct) to whoever the task was, forever.
            $this->reapBounded($worker->process);
            proc_close($worker->process);
        }

        // Unlink the worker's temp script exactly once.
        if ($worker->scriptPath !== null && is_file($worker->scriptPath)) {
            @unlink($worker->scriptPath);
        }
    }

    /**
     * Grace-poll, SIGTERM, poll, SIGKILL — the bounded reap ladder.
     *
     * A per-package copy of the tree's house pattern (candy-code
     * ProcessReaper, sugar-forms BoundedReaper — sugar-video and sugar-dashboard
     * folded their copies onto this package's {@see Util\Proc\BoundedShutdown}):
     * sugar-core is a foundation library
     * and may not depend on any of them for one helper. After this returns,
     * the child is exited on every path the ladder can reach, so the
     * caller's proc_close() reaps rather than waits.
     *
     * @param resource $process
     */
    private function reapBounded($process): void
    {
        if ($this->hasExited($process, self::REAP_GRACE_SECONDS)) {
            return;
        }

        // @: the kill can race the child's own exit (ESRCH); the poll below is
        // the authority on whether the rung took, not the warning.
        @proc_terminate($process, self::SIGTERM);
        if (!$this->hasExited($process, self::REAP_TERM_SECONDS)) {
            @proc_terminate($process, self::SIGKILL);
            $this->hasExited($process, self::REAP_KILL_SECONDS);
        }
    }

    /**
     * Poll the child up to $seconds for an exit, in ticks.
     *
     * proc_get_status() reaps internally once the child is gone, so a false
     * 'running' here means proc_close() cannot block afterwards.
     *
     * 'running' is read without a `??` fallback on purpose: every call site
     * hands in a resource that is closed only after the ladder finishes, and
     * proc_get_status() on a live resource always answers the fixed-shape
     * array — the race this poll lives through is exit-not-yet-observed
     * (running stays true), never a missing offset.
     *
     * @param resource $process
     */
    private function hasExited($process, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (!proc_get_status($process)['running']) {
                return true;
            }
            usleep(self::REAP_TICK_MICROS);
        } while (microtime(true) < $deadline);

        // Fresh snapshot under its own name, not a repeat of the loop's
        // expression: the tick budget expired and the child may have exited
        // inside exactly that window, so this answer must be re-read.
        $finalStatus = proc_get_status($process);

        return !$finalStatus['running'];
    }

    public function __destruct()
    {
        $this->stop();
    }
}

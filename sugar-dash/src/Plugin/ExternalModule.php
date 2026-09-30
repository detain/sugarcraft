<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plugin;

use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Dash\Module\LegacyModule;
use SugarCraft\Core\Util\Sanitize;

/**
 * Wraps an external binary as a Module.
 *
 * Spawns the binary via proc_open, communicates using line-delimited JSON
 * over stdin/stdout, and schedules periodic updates if Interval > 0.
 *
 * Mirrors the lattice ExternalModule pattern.
 *
 * Uses the legacy array-state update pattern kept for backwards compat.
 */
final class ExternalModule implements LegacyModule
{
    /** Maximum line length read from plugin stdout (1 MiB). Prevents a runaway plugin from exhausting memory. */
    private const MAX_LINE_BYTES = 1048576;

    /** Read timeout in seconds for each response (stdin/stdout round-trip). */
    private const READ_TIMEOUT_SECS = 5;

    /** Seconds a plugin is given to exit on its own after the pipes close, before the ladder signals. */
    private const SHUTDOWN_GRACE_SECONDS = 2.0;

    /**
     * stderr is drained-and-discarded while we wait on stdout: the child
     * blocks once its 64 KiB pipe buffer fills, and a blocked child never
     * reaches the response it owes us (the old shape timed out after
     * READ_TIMEOUT_SECS and silently disabled the plugin). One select round
     * consumes at most this many stderr bytes, so a continuous spammer
     * cannot starve the stdout half of the same loop.
     */
    private const STDERR_DRAIN_BYTES_PER_ROUND = 1048576;

    /** fread() granularity inside a {@see self::STDERR_DRAIN_BYTES_PER_ROUND} round. */
    private const STDERR_DRAIN_CHUNK_BYTES = 8192;

    private array $state = [];
    private int $interval = 0;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    /** @var resource|null */
    private $process = null;

    private bool $running = false;

    public function __construct(
        private readonly string $name,
        private readonly string $command,
        private readonly array $args = [],
    ) {}

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function init(): array
    {
        $this->startProcess();
        $this->sendRequest(Request::init());
        $response = $this->readResponse();

        if ($response->type !== 'init') {
            throw new \RuntimeException("Expected init response, got: {$response->type}");
        }

        $this->interval = self::clampInterval($response->data['interval'] ?? 0);

        return [
            'name' => $response->data['name'] ?? $this->name,
            'minSize' => self::clampMinSize($response->data['minSize'] ?? null),
            'interval' => $this->interval,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function update(array $state): array
    {
        if (!$this->running) {
            return $state;
        }

        $this->sendRequest(Request::update($state));
        $response = $this->readResponse();

        if ($response->type === 'update') {
            return $response->data['state'] ?? $state;
        }

        // Protocol error: unexpected response type or error from plugin.
        // Disable the plugin so a misbehaving subprocess cannot desync
        // the dashboard loop. Per Step 8, we surface the error rather
        // than silently falling back to prior state.
        $this->running = false;
        return $state;
    }

    /**
     * {@inheritdoc}
     */
    public function view(array $state, int $width, int $height): string
    {
        if (!$this->running) {
            return '';
        }

        $this->sendRequest(Request::view($width, $height, $state));
        $response = $this->readResponse();

        if ($response->type === 'view') {
            return Sanitize::untrusted($response->data['content'] ?? '');
        }

        // Protocol error — disable the plugin rather than returning
        // stale/empty state silently.
        $this->running = false;
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function minSize(): array
    {
        return [30, 4];
    }

    /**
     * Start the external process.
     */
    private function startProcess(): void
    {
        $cmd = array_merge([$this->command], $this->args);

        // Validate the binary up-front. proc_open() emits a PHP warning
        // before returning false when the command can't be found, which
        // trips PHPUnit's failOnWarning="true" even though we re-throw
        // a RuntimeException immediately after. Resolving the binary
        // here keeps proc_open() warning-free.
        if (self::resolveExecutable($this->command) === null) {
            throw new \RuntimeException("Failed to start process: {$this->command}");
        }

        $this->process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to start process: {$this->command}");
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Drain discipline (see STDERR_DRAIN_BYTES_PER_ROUND): stderr is read
        // opportunistically whenever the loop waits, and non-blocking is what
        // makes "opportunistic" cheap.
        stream_set_blocking($this->stderr, false);

        $this->running = true;
    }

    /**
     * Send a request to the plugin.
     */
    private function sendRequest(Request $request): void
    {
        if (!$this->running || $this->stdin === null) {
            return;
        }

        fwrite($this->stdin, $request->toJson() . "\n");
        fflush($this->stdin);
    }

    /**
     * Read a response from the plugin.
     */
    private function readResponse(): Response
    {
        if (!$this->running || $this->stdout === null) {
            return Response::error('Process not running');
        }

        // Set per-read timeout so a hung plugin cannot freeze the loop forever.
        // select() bounds the wait; this is the backstop for a partial line
        // (bytes arrive but no newline).
        stream_set_timeout($this->stdout, self::READ_TIMEOUT_SECS);

        $deadline = hrtime(true) / 1_000_000_000 + self::READ_TIMEOUT_SECS;

        // Multiplex stdout (the response we owe the caller) against stderr
        // (the pipe whose fullness would wedge the child). Draining stderr
        // here is the whole fix: previously nothing ever read it, so a plugin
        // logging more than the 64 KiB pipe buffer blocked mid-write and this
        // read timed out — disabling a plugin that was merely talkative.
        while (true) {
            $this->drainStderr();

            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining <= 0) {
                $this->running = false;
                return Response::error('read timeout');
            }

            $read = [$this->stdout];
            if ($this->stderr !== null && is_resource($this->stderr)) {
                $read[] = $this->stderr;
            }
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));

            if ($ready === false) {
                $this->running = false;
                return Response::error('read timeout');
            }

            if (!in_array($this->stdout, $read, true)) {
                continue; // only stderr moved this round
            }

            $line = fgets($this->stdout, self::MAX_LINE_BYTES);

            if ($line === false) {
                $meta = stream_get_meta_data($this->stdout);
                $this->running = false;
                if ($meta['timed_out'] ?? false) {
                    return Response::error('read timeout');
                }
                return Response::error('EOF from process');
            }

            // Enforce the line length cap. A line exceeding MAX_LINE_BYTES without
            // a newline is a protocol violation (runaway plugin); treat as error.
            if (strlen($line) >= self::MAX_LINE_BYTES && strpos($line, "\n") === false) {
                $this->running = false;
                return Response::error('protocol error: line exceeds maximum length');
            }

            return Response::fromJson(trim($line));
        }
    }

    /**
     * Consume everything currently pending on stderr, up to one bounded
     * round, and discard it. Non-blocking reads make this cheap when the
     * child is quiet; the per-round cap keeps a chatty one from starving
     * the stdout side of {@see readResponse()}.
     */
    private function drainStderr(): void
    {
        if ($this->stderr === null || !is_resource($this->stderr)) {
            return;
        }

        $consumed = 0;
        while ($consumed < self::STDERR_DRAIN_BYTES_PER_ROUND) {
            $chunk = fread($this->stderr, self::STDERR_DRAIN_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                return; // EAGAIN (non-blocking) or EOF
            }
            $consumed += strlen($chunk);
        }
    }

    /**
     * Destructor to clean up the process.
     */
    public function __destruct()
    {
        $this->closeStdout();
        $this->closeStderr();
        $this->closeStdin();

        if ($this->process !== null && is_resource($this->process)) {
            $this->running = false;
            // The ladder now lives once, upstream, in candy-core's
            // BoundedShutdown (f7a579e5b); terminateBounded also performs the
            // reap (proc_close), so do NOT close again here. The polite EOF
            // grace rung is caller-side by design there ("budgets stay with
            // the callers"), so the plugin's own 2 s head-start on exiting
            // is kept as the pre-rung poll this lib always granted.
            BoundedShutdown::hasExited($this->process, self::SHUTDOWN_GRACE_SECONDS);
            BoundedShutdown::terminateBounded($this->process);
            $this->process = null;
        }
    }

    private function closeStdin(): void
    {
        if ($this->stdin !== null && is_resource($this->stdin)) {
            fclose($this->stdin);
            $this->stdin = null;
        }
    }

    private function closeStdout(): void
    {
        if ($this->stdout !== null && is_resource($this->stdout)) {
            fclose($this->stdout);
            $this->stdout = null;
        }
    }

    /**
     * Clamp and sanitize a plugin-supplied interval to a safe range.
     * Guards against absurd values (negative, massive) from a plugin.
     */
    private static function clampInterval(mixed $interval): int
    {
        if (!is_int($interval)) {
            return 0;
        }
        return max(0, min($interval, 86400));
    }

    /**
     * Validate and clamp a plugin-supplied minSize to a safe default.
     * Must be a two-element array of positive integers; otherwise [30, 4].
     */
    private static function clampMinSize(mixed $minSize): array
    {
        if (
            is_array($minSize)
            && count($minSize) === 2
            && is_int($minSize[0] ?? null)
            && is_int($minSize[1] ?? null)
            && ($minSize[0] ?? 0) > 0
            && ($minSize[1] ?? 0) > 0
        ) {
            return [
                max(1, min((int) $minSize[0], 10000)),
                max(1, min((int) $minSize[1], 1000)),
            ];
        }
        return [30, 4];
    }

    private function closeStderr(): void
    {
        if ($this->stderr !== null && is_resource($this->stderr)) {
            fclose($this->stderr);
            $this->stderr = null;
        }
    }

    /**
     * Locate an executable by PATH search (or accept an absolute / relative
     * path as-is). Returns the resolved absolute path, or null if the
     * command isn't found. Used to pre-validate before proc_open() so a
     * missing binary throws a clean RuntimeException without emitting a
     * PHP warning.
     */
    private static function resolveExecutable(string $command): ?string
    {
        if ($command === '') {
            return null;
        }
        if (str_contains($command, DIRECTORY_SEPARATOR) || str_contains($command, '/')) {
            return (is_file($command) && is_executable($command)) ? $command : null;
        }
        $pathEnv = getenv('PATH');
        if (!is_string($pathEnv) || $pathEnv === '') {
            return null;
        }
        $sep = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
        foreach (explode($sep, $pathEnv) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $command;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}

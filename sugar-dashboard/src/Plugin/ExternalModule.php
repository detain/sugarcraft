<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plugin;

use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Dashboard\Module\LegacyModule;
use SugarCraft\Core\Util\Sanitize;

/**
 * Wraps an external binary as a Module.
 *
 * Spawns the binary via proc_open, communicates using line-delimited JSON
 * over stdin/stdout, and schedules periodic updates if Interval > 0.
 *
 * Trust boundary: the plugin is a third-party program. Every round-trip is
 * deadline-bounded (write and read), unparsable or out-of-order wire lines
 * disable the plugin for the session instead of throwing into the host
 * loop, disabled panels name themselves and say why, and the child gets a
 * small environment allow-list rather than the parent's whole env. Reply
 * state is additionally normalised at the store seam (invalid UTF-8 bytes
 * substituted) and any payload still unencodable at the send seam disables
 * the panel ('state not encodable') — on PHP ≥ 8.3 the JSON parser itself
 * refuses malformed wire bytes, but a stored state must never be able to
 * brick the host loop by throwing out of it (sugar-dashboard-008).
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

    /** Longest plugin-supplied label allowed into chrome (name header/panel title). */
    private const MAX_LABEL_BYTES = 64;

    /** Bound on a single stdin write: a plugin that never reads is disabled, not waited on forever. */
    private const WRITE_TIMEOUT_SECS = 5;

    private int $interval = 0;

    /** Why the plugin was disabled this session, if it was (surfaced through view()). */
    private ?string $lastError = null;

    /** Last rendered view plus the signature (state + dimensions) it was rendered for. */
    private ?string $viewCache = null;

    private ?string $viewSignature = null;

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
        return self::chromeLabel($this->name);
    }

    /**
     * {@inheritdoc}
     */
    public function init(): array
    {
        $this->startProcess();
        if (!$this->sendRequest(Request::init())) {
            return [
                'name' => $this->name(),
                'minSize' => $this->minSize(),
                'interval' => 0,
            ];
        }
        $response = $this->readResponse();

        if ($response->type !== 'init') {
            // A plugin that cannot answer its own handshake is disabled for
            // the session (its panel shows the reason) rather than throwing:
            // discovery auto-loads everything in the plugin directory, and
            // one broken binary must not brick the whole dashboard startup.
            $this->disable("init refused: {$response->type}");
            return [
                'name' => $this->name(),
                'minSize' => $this->minSize(),
                'interval' => 0,
            ];
        }

        $this->interval = self::clampInterval($response->data['interval'] ?? 0);

        return [
            'name' => self::chromeLabel((string) ($response->data['name'] ?? $this->name)),
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

        if (!$this->sendRequest(Request::update($state))) {
            return $state;
        }
        $response = $this->readResponse();

        if ($response->type === 'update') {
            $nextState = $response->data['state'] ?? $state;
            return is_array($nextState) ? self::sanitizeEncodedState($nextState) : $state;
        }

        // Protocol error: unexpected response type or error from plugin.
        // Disable the plugin so a misbehaving subprocess cannot desync
        // the dashboard loop; the reason is carried to view() rather than
        // silently falling back to prior state.
        $this->disable("unexpected response: {$response->type}");
        return $state;
    }

    /**
     * {@inheritdoc}
     */
    public function view(array $state, int $width, int $height): string
    {
        if (!$this->running) {
            // A plugin that started and then died or broke protocol must say
            // so in its panel — an empty string read as "nothing to show" hid
            // every failure. Never started (no round trip yet) stays blank.
            if ($this->lastError === null) {
                return '';
            }
            return Sanitize::untrusted(
                '[' . self::chromeLabel($this->name) . ' disabled: ' . self::chromeLabel($this->lastError) . ']'
            );
        }

        // Render round-trips are bounded to actual change: a full synchronous
        // plugin exchange inside view() ran on EVERY frame, stalling input
        // for a healthy-but-slow plugin even when nothing had updated. The
        // signature covers state and dimensions, so any real refresh (new
        // state from update(), or a resize) still costs exactly one trip.
        $signature = $width . 'x' . $height . '|' . self::stateKey($state);
        if ($this->viewCache !== null && $this->viewSignature === $signature) {
            return $this->viewCache;
        }

        if (!$this->sendRequest(Request::view($width, $height, $state))) {
            return $this->viewCache ?? '';
        }
        $response = $this->readResponse();

        if ($response->type === 'view') {
            $rendered = Sanitize::untrusted((string) ($response->data['content'] ?? ''));
            $this->viewCache = $rendered;
            $this->viewSignature = $signature;
            return $rendered;
        }

        // Protocol error — disable the plugin; the marker below replaces
        // the stale/empty render so the panel is never silently blank.
        $this->disable("unexpected response: {$response->type}");
        return Sanitize::untrusted(
            '[' . self::chromeLabel($this->name) . ' disabled: ' . self::chromeLabel((string) $this->lastError) . ']'
        );
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
            $pipes,
            null,
            self::pluginEnvironment()
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
     * Send a request to the plugin, bounded by {@see WRITE_TIMEOUT_SECS}.
     *
     * A plugin that stops reading its stdin (wedged before its read loop,
     * say) would otherwise block fwrite forever once the 64 KiB pipe buffer
     * fills. Returns false — and disables the plugin — when the payload
     * cannot be handed off within the deadline.
     */
    private function sendRequest(Request $request): bool
    {
        if (!$this->running || $this->stdin === null) {
            return false;
        }

        try {
            $payload = $request->toJson() . "\n";
        } catch (\JsonException $e) {
            // json_encode rejects invalid UTF-8 and inf/nan payloads. That
            // byte never reaches the child, and the panel says why instead
            // of the exception climbing into the host update loop — the
            // trust-boundary contract above.
            $this->disable('state not encodable: ' . $e->getMessage());
            return false;
        }
        $length = strlen($payload);
        $offset = 0;
        $deadline = hrtime(true) / 1_000_000_000 + self::WRITE_TIMEOUT_SECS;

        while ($offset < $length) {
            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining <= 0) {
                $this->disable('write timeout');
                return false;
            }

            $readable = null;
            $writable = [$this->stdin];
            $except = null;
            $ready = @stream_select($readable, $writable, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
            if ($ready === false || $ready === 0) {
                continue; // interrupted or merely not-ready yet: the deadline governs
            }

            $written = fwrite($this->stdin, substr($payload, $offset));
            if ($written === false || $written === 0) {
                // Zero-write on a writable-selection round means the pipe broke
                // (child gone); a partial write simply resumes at $offset.
                $this->disable('write failed: plugin stdin closed');
                return false;
            }
            $offset += $written;
        }

        fflush($this->stdin);
        return true;
    }

    /**
     * Read a response from the plugin.
     *
     * Every failure path funnels through {@see self::disable()}: the plugin
     * stops participating and the reason survives to view() instead of
     * vanishing into a discarded Response::error().
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
                $this->disable('read timeout');
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
                $this->disable('read failed');
                return Response::error('read failed');
            }

            if (!in_array($this->stdout, $read, true)) {
                continue; // only stderr moved this round
            }

            // Ask for one byte more than the cap: fgets delivers at most
            // length-1, so asking for exactly MAX_LINE_BYTES made the
            // overflow check below unreachable for the very lines it polices.
            $line = fgets($this->stdout, self::MAX_LINE_BYTES + 1);

            if ($line === false) {
                $meta = stream_get_meta_data($this->stdout);
                if ($meta['timed_out'] ?? false) {
                    $this->disable('read timeout');
                    return Response::error('read timeout');
                }
                $this->disable('EOF from process');
                return Response::error('EOF from process');
            }

            // Enforce the line length cap. A line exceeding MAX_LINE_BYTES without
            // a newline is a protocol violation (runaway plugin); treat as error.
            if (strlen($line) >= self::MAX_LINE_BYTES && strpos($line, "\n") === false) {
                $this->disable('protocol error: line exceeds maximum length');
                return Response::error('protocol error: line exceeds maximum length');
            }

            try {
                return Response::fromJson(trim($line));
            } catch (\InvalidArgumentException $e) {
                // Unparsable wire line: a protocol violation, not a host
                // crash. The typed refusal is already printable-clipped.
                $this->disable('protocol error: ' . $e->getMessage());
                return Response::error('protocol error: malformed plugin response');
            }
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
            // The ladder lives upstream, in sugar-core's BoundedShutdown;
            // terminateBounded also performs the reap (proc_close), so do NOT
            // close again here. The polite EOF grace rung is caller-side by
            // design there ("budgets stay with the callers"), so the plugin's
            // own 2 s head-start on exiting is kept as the pre-rung poll this
            // lib always granted.
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
     * Normalise a plugin reply state so the host can always send it back.
     *
     * json_decode happily hands back lone-surrogate bytes (\ud800 decodes
     * to raw \xED\xA0\x80), which no JSON_THROW_ON_ERROR encoder will
     * re-accept — an unguarded store here would make every later
     * round-trip throw inside the host loop. Substituting the bad bytes
     * once, at the trust boundary they enter through, keeps the state
     * permanently re-encodable; anything else that cannot be encoded
     * (inf/nan floats) is left for sendRequest()'s guard to disable on.
     *
     * @param array<array-key, mixed> $state
     * @return array<array-key, mixed>
     */
    private static function sanitizeEncodedState(array $state): array
    {
        try {
            json_encode($state, JSON_THROW_ON_ERROR);
            return $state;
        } catch (\JsonException) {
            // fall through to the substitute round-trip below
        }

        $substituted = json_decode(
            (string) json_encode($state, JSON_INVALID_UTF8_SUBSTITUTE),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return is_array($substituted) ? $substituted : [];
    }

    /**
     * Stop trusting this plugin for the session and remember why.
     */
    private function disable(string $reason): void
    {
        $this->running = false;
        $this->lastError ??= $reason;
    }

    /**
     * Make a plugin-supplied label safe for terminal chrome: control bytes,
     * CR reflow and zone sentinels stripped, invisible formatting marked,
     * length clipped. Plugin names and error texts are authored by another
     * process — they are untrusted input.
     */
    private static function chromeLabel(string $label): string
    {
        $clean = Sanitize::stripZoneSentinels(Sanitize::untrustedForDisplay($label));
        if (strlen($clean) > self::MAX_LABEL_BYTES) {
            $clean = substr($clean, 0, self::MAX_LABEL_BYTES) . '...';
        }
        return $clean;
    }

    /**
     * Stable short fingerprint of a state array (for the view cache key).
     *
     * @param array<string, mixed> $state
     */
    private static function stateKey(array $state): string
    {
        return md5(serialize($state));
    }

    /**
     * The environment a plugin child gets: a small, explicit allow-list.
     *
     * The parent dashboard may carry API keys, database passwords and other
     * session secrets in its environment; a plugin binary is third-party
     * code and must not be handed them by inheritance. Anything beyond this
     * set a plugin needs belongs in its own configuration.
     *
     * @return array<string, string>
     */
    private static function pluginEnvironment(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'USER', 'LANG', 'TERM', 'COLORTERM'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }
        return $env;
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

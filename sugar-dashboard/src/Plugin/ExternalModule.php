<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plugin;

use SugarCraft\Core\Util\Proc\WindowsPipes;
use SugarCraft\Core\Util\Executable\Locator;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Dashboard\Module\LegacyModule;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;

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
 * small environment allow-list rather than the parent's whole env. Round
 * trips live at the update seam only (the first frame of a fresh panel is
 * the one exception): view() renders the cached frame and a resize is
 * re-rendered at the next update(), so a slow plugin can never stall — or
 * be disabled — from the paint path (sugar-dashboard-092). When a send or
 * read fails, the plugin's own last stderr line rides into the disable
 * reason instead of vanishing down the unread pipe (sugar-dashboard-203).
 * Reply
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
     * stderr is drained (into a bounded tail) while we wait on stdout: the
     * child blocks once its 64 KiB pipe buffer fills, and a blocked child never
     * reaches the response it owes us (the old shape timed out after
     * READ_TIMEOUT_SECS and silently disabled the plugin). One select round
     * consumes at most this many stderr bytes, so a continuous spammer
     * cannot starve the stdout half of the same loop.
     */
    private const STDERR_DRAIN_BYTES_PER_ROUND = 1048576;

    /** fread() granularity inside a {@see self::STDERR_DRAIN_BYTES_PER_ROUND} round. */
    private const STDERR_DRAIN_CHUNK_BYTES = 8192;

    /**
     * How much of the plugin's stderr is kept, not just drained: the tail
     * survives so a failing send can quote the child's own last line in the
     * disable reason (sugar-dashboard-203). Everything older is dropped.
     */
    private const MAX_STDERR_TAIL_BYTES = 2048;

    /** Longest stderr line quoted into a disable reason before the tail clip. */
    private const STDERR_LINE_VISIBLE_BYTES = 96;

    /** Longest plugin-supplied label allowed into chrome, in display cells (name header/panel title). */
    private const MAX_LABEL_CELLS = 64;

    /** Bound on a single stdin write: a plugin that never reads is disabled, not waited on forever. */
    private const WRITE_TIMEOUT_SECS = 5;

    private int $interval = 0;

    /** Why the plugin was disabled this session, if it was (surfaced through view()). */
    private ?string $lastError = null;

    /**
     * Minimum grid the plugin advertised (sugar-dashboard-105). Before the
     * handshake lands — or if it never does — this is the placeholder budget
     * minSize() has always returned: advertise first, draw after, and the
     * validated reply REPLACES the placeholder instead of being dropped.
     *
     * @var array{int,int}
     */
    private array $advertisedMinSize = [30, 4];

    /**
     * Last rendered frame, plus what it was rendered for. The cache is keyed
     * by state AND dimensions: a resize alone must not force a round-trip
     * (sugar-dashboard-092), so the frame a plugin drew for the old size is
     * served as-is until the update seam can afford a fresh one.
     */
    private ?string $viewCache = null;

    private ?string $renderedDims = null;

    private ?string $renderedStateKey = null;

    /** Dimensions the host last painted at; a changed pair is picked up at the next update(). */
    private ?int $pendingWidth = null;

    private ?int $pendingHeight = null;

    /** Rolling tail of plugin stderr bytes, kept bounded for disable reasons (sugar-dashboard-203). */
    private string $stderrTail = '';

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    /** @var resource|null */
    private $process = null;

    private bool $running = false;

    /** Windows only: stdout bytes read past the last newline, kept for the next response. */
    private string $windowsReadBuffer = '';

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
        try {
            $this->startProcess();
        } catch (\RuntimeException $e) {
            // A binary that cannot be spawned disables its own panel with the
            // honest reason (the README's lifecycle promise) instead of
            // throwing into the host: discovery auto-loads everything in the
            // plugin directory, and one missing file must not brick dashboard
            // startup — the same rule the handshake refusals below already
            // honor (sugar-dashboard-088).
            $this->disable($e->getMessage());
            return [
                'name' => $this->name(),
                'minSize' => $this->minSize(),
                'interval' => 0,
            ];
        }
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
        $this->advertisedMinSize = self::clampMinSize($response->data['minSize'] ?? null);

        return [
            'name' => self::chromeLabel((string) ($response->data['name'] ?? $this->name)),
            'minSize' => $this->advertisedMinSize,
            'interval' => $this->interval,
        ];
    }

    /**
     * {@inheritdoc}
     *
     * The seam that owns round-trips also owns re-rendering: after the state
     * advances here, a frame that went stale (new state) or wrong-sized
     * (resized since the last paint) is refreshed at this seam — where the
     * deadline-bounded wait belongs to the update loop, never to the paint
     * path (sugar-dashboard-092).
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
            $nextState = is_array($nextState) ? self::sanitizeEncodedState($nextState) : $state;
            if ($this->pendingWidth !== null && $this->pendingHeight !== null
                && ($this->viewCache === null
                    || $this->renderedDims !== $this->pendingWidth . 'x' . $this->pendingHeight
                    || $this->renderedStateKey !== self::stateKey($nextState))) {
                $this->renderView($nextState, $this->pendingWidth, $this->pendingHeight);
            }
            return $nextState;
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
     *
     * Paint renders; it does not converse. The old shape ran a full
     * synchronous plugin exchange inside view() on every frame whose
     * state OR dimensions differed — so a live resize storm hammered the
     * child once per repaint, and a healthy-but-slow plugin could be
     * stalled (or deadline-disabled) from the paint path. view() now
     * records the requested size and serves the cached frame; the resized
     * (or state-advanced) frame is re-rendered at the next update() seam.
     * The one exception is the cold first frame: there is no cached bytes
     * to show yet, so the founding render still pays its bounded trip here.
     */
    public function view(array $state, int $width, int $height): string
    {
        if (!$this->running) {
            // A plugin that started and then died or broke protocol must say
            // so in its panel — an empty string read as "nothing to show" hid
            // every failure. Never started (no round trip yet) stays blank.
            return $this->disabledMarker();
        }

        $this->pendingWidth = $width;
        $this->pendingHeight = $height;

        if ($this->viewCache === null) {
            $this->renderView($state, $width, $height);
            if (!$this->running) {
                return $this->disabledMarker();
            }
        }

        return $this->viewCache ?? '';
    }

    /**
     * One bounded render round-trip, called only from seams that may spend
     * one (update(), or a cold panel's first frame). Success fills the cache
     * with the frame it was rendered for; failure disables the panel with
     * the reason, and any older cached frame simply stays on screen.
     */
    private function renderView(array $state, int $width, int $height): void
    {
        if (!$this->sendRequest(Request::view($width, $height, $state))) {
            return; // sendRequest() has already disabled with its reason
        }
        $response = $this->readResponse();

        if ($response->type === 'view') {
            $this->viewCache = Sanitize::untrusted((string) ($response->data['content'] ?? ''));
            $this->renderedDims = $width . 'x' . $height;
            $this->renderedStateKey = self::stateKey($state);
            return;
        }

        // Protocol error — disable the plugin; the marker replaces the
        // stale/empty render so the panel is never silently blank.
        $this->disable("unexpected response: {$response->type}");
    }

    /** The panel text for a plugin that is no longer trusted: named, with its reason. */
    private function disabledMarker(): string
    {
        if ($this->lastError === null) {
            return '';
        }

        return Sanitize::untrusted(
            '[' . self::chromeLabel($this->name) . ' disabled: ' . self::chromeLabel($this->lastError) . ']'
        );
    }

    /**
     * {@inheritdoc}
     *
     * The advertised budget, not a constant: the value the plugin validated
     * at handshake (sugar-dashboard-105), or the placeholder the panel
     * started with before there was a handshake to trust.
     */
    public function minSize(): array
    {
        return $this->advertisedMinSize;
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
            // The reason a host operator needs: the file is not there (or not
            // executable), so the panel says exactly that once init() routes
            // the refusal into disable() (sugar-dashboard-088).
            throw new \RuntimeException("binary not found: {$this->command}");
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
            // The file exists but the spawn itself failed (fork/exec error);
            // still a reason a disabled panel can print honestly.
            throw new \RuntimeException("failed to start: {$this->command}");
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

        if (WindowsPipes::runtimeNeedsIt()) {
            // Windows: select() calls a pipe writable whatever its state, and
            // a write over its 4096-byte buffer blocks until the plugin reads.
            // Feed pipe-buffer-sized pieces, draining stderr and checking the
            // deadline and the child between them.
            while ($offset < $length) {
                if (hrtime(true) / 1_000_000_000 >= $deadline) {
                    $this->disable('write timeout');
                    return false;
                }
                $this->drainStderr();
                $written = @fwrite($this->stdin, substr($payload, $offset, WindowsPipes::PIPE_BUFFER_BYTES));
                if ($written === false || ($written === 0 && !$this->childAlive())) {
                    $this->disable('write failed: plugin stdin closed');
                    return false;
                }
                $offset += (int) $written;
            }
            fflush($this->stdin);
            return true;
        }

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

        if (WindowsPipes::runtimeNeedsIt()) {
            return $this->readResponseWindows($deadline);
        }

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
     * readResponse() on Windows: a pipe there stays blocking and select()
     * calls it ready while empty, so fgets() after select parked until the
     * plugin wrote or exited. Gather what fstat reports waiting until a
     * newline arrives; the child's exit (sampled before the read) with
     * nothing waiting is EOF. The line rules are the same as the POSIX path.
     */
    private function readResponseWindows(float $deadline): Response
    {
        while (true) {
            $this->drainStderr();

            $newline = strpos($this->windowsReadBuffer, "\n");
            if ($newline !== false) {
                $line = substr($this->windowsReadBuffer, 0, $newline + 1);
                $this->windowsReadBuffer = (string) substr($this->windowsReadBuffer, $newline + 1);
                if (strlen($line) > self::MAX_LINE_BYTES) {
                    $this->disable('protocol error: line exceeds maximum length');
                    return Response::error('protocol error: line exceeds maximum length');
                }
                try {
                    return Response::fromJson(trim($line));
                } catch (\InvalidArgumentException $e) {
                    $this->disable('protocol error: ' . $e->getMessage());
                    return Response::error('protocol error: malformed plugin response');
                }
            }
            if (strlen($this->windowsReadBuffer) >= self::MAX_LINE_BYTES) {
                $this->disable('protocol error: line exceeds maximum length');
                return Response::error('protocol error: line exceeds maximum length');
            }
            if (hrtime(true) / 1_000_000_000 >= $deadline) {
                $this->disable('read timeout');
                return Response::error('read timeout');
            }

            $alive = $this->childAlive();
            $chunk = WindowsPipes::readAvailable($this->stdout, self::MAX_LINE_BYTES);
            if ($chunk === '') {
                if (!$alive) {
                    $this->disable('EOF from process');
                    return Response::error('EOF from process');
                }
                usleep(WindowsPipes::POLL_MICROSECONDS);
                continue;
            }
            $this->windowsReadBuffer .= $chunk;
        }
    }

    /** Whether the plugin process is still running (false once it is gone or unknown). */
    private function childAlive(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = @proc_get_status($this->process);

        return is_array($status) && $status['running'] === true;
    }

    /**
     * Consume everything currently pending on stderr, up to one bounded
     * round, keeping the last {@see self::MAX_STDERR_TAIL_BYTES} of it.
     * Non-blocking reads make this cheap when the child is quiet; the
     * per-round cap keeps a chatty one from starving the stdout side of
     * {@see readResponse()}.
     */
    private function drainStderr(): void
    {
        if ($this->stderr === null || !is_resource($this->stderr)) {
            return;
        }

        $consumed = 0;
        while ($consumed < self::STDERR_DRAIN_BYTES_PER_ROUND) {
            // Windows: never a blocking fread on a pipe (it parks until the
            // plugin writes stderr or exits); take only what is waiting.
            $chunk = WindowsPipes::runtimeNeedsIt()
                ? WindowsPipes::readAvailable($this->stderr, self::STDERR_DRAIN_CHUNK_BYTES)
                : fread($this->stderr, self::STDERR_DRAIN_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                return; // EAGAIN (non-blocking) or EOF
            }
            $consumed += strlen($chunk);
            $this->stderrTail .= $chunk;
            // Keep the window bounded on a LINE boundary where possible: a
            // partial pipe read ending mid-line must not orphan its first
            // bytes — the next round's newline would then make the
            // line-after-that the "last line" and lose the real complaint.
            $overflow = strlen($this->stderrTail) - self::MAX_STDERR_TAIL_BYTES;
            if ($overflow > 0) {
                $newline = strrpos($this->stderrTail, "\n", $overflow);
                $this->stderrTail = (string) substr(
                    $this->stderrTail,
                    $newline === false ? $overflow : $newline + 1
                );
            }
        }
    }

    /**
     * The plugin's last stderr line from the kept tail — the complaint it
     * was making while we were failing to talk to it. Bytes are untrusted:
     * the result rides into the disable reason and is chromeLabel()-clipped
     * on its way to the panel.
     */
    private static function lastStderrLine(string $tail): string
    {
        $tail = rtrim($tail, "\r\n");
        if ($tail === '') {
            return '';
        }
        $newline = strrpos($tail, "\n");
        $line = trim((string) substr($tail, $newline === false ? 0 : $newline + 1));
        if (strlen($line) > self::STDERR_LINE_VISIBLE_BYTES) {
            // mb_strcut: a byte budget that never splits a multi-byte
            // cluster (sugar-dashboard-105's rule for every display string).
            $line = mb_strcut($line, 0, self::STDERR_LINE_VISIBLE_BYTES) . '...';
        }

        return $line;
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
     *
     * The child is reaped HERE, at the seam that loses trust, not deferred to
     * the destructor: a disabled plugin keeps its pipes open otherwise, so a
     * child wedged writing to an unread stdout (the chatty fake in
     * sugar-dashboard-094's probe) stays alive with two file descriptors of
     * ours — one leak per disable while the host still holds the object.
     * Closing our read sides first lets a pipe-blocked child fall on EPIPE;
     * the bounded TERM→poll→KILL ladder then covers everything else and
     * performs the reap itself (`proc_close` lives inside it). __destruct
     * stays as the backstop for a never-disabled plugin, no longer the
     * primary reaper.
     */
    private function disable(string $reason): void
    {
        // One last bounded look at stderr before the pipes close: the child
        // writes its complaint before the stdout line that got us here
        // (program order, and pipe writes are visible in order), so the bytes
        // are pending even when the earlier drain round missed them. The
        // plugin's own last line turns 'read timeout' into a diagnosable
        // reason instead of a mystery (sugar-dashboard-203).
        $this->drainStderr();
        $stderrLine = self::lastStderrLine($this->stderrTail);
        if ($stderrLine !== '') {
            $reason .= '; plugin stderr: ' . $stderrLine;
        }

        $this->running = false;
        $this->lastError ??= $reason;

        $this->closeStdout();
        $this->closeStderr();
        $this->closeStdin();

        if ($this->process !== null && is_resource($this->process)) {
            BoundedShutdown::terminateBounded($this->process);
            $this->process = null;
        }
    }

    /**
     * Make a plugin-supplied label safe for terminal chrome: control bytes,
     * CR reflow and zone sentinels stripped, invisible formatting marked,
     * length clipped. Plugin names and error texts are authored by another
     * process — they are untrusted input.
     *
     * The clip counts DISPLAY CELLS, not bytes (sugar-dashboard-105): the
     * old substr() cut through multi-byte clusters mid-glyph and painted
     * the broken tail bytes into the terminal chrome. Width::truncate walks
     * grapheme clusters, so a clip inside a wide glyph either keeps the
     * whole cluster or drops it — never a fragment.
     */
    private static function chromeLabel(string $label): string
    {
        $clean = Sanitize::stripZoneSentinels(Sanitize::untrustedForDisplay($label));
        $clipped = Width::truncate($clean, self::MAX_LABEL_CELLS);

        return $clipped === $clean ? $clean : $clipped . '...';
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

        // sugar-core's Locator: PATH plus PATHEXT on Windows, where a bare
        // `my-plugin` is `my-plugin.exe`/`.cmd` on disk and PHP's own
        // is_executable() is false for a .cmd or .bat.
        return Locator::locate($command);
    }
}

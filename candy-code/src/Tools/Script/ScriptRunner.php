<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Script;

use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\ProcessReaper;
use SugarCraft\Code\Tools\Sandbox\SandboxBackend;

/**
 * The engine half of Code Mode: run one model-written PHP script in a child
 * whose ONLY channel to the outside world is a tool bridge on descriptor 3.
 *
 * ## Why a child at all
 *
 * One model call can then chain many tool calls — the script computes between
 * calls instead of round-tripping the conversation per call — while every
 * effect still passes, per call, through a closure the CALLER injected. This
 * class deliberately holds no policy: it knows no PermissionGate, no hook
 * chain, no session. The wiring step (`Script` as a catalog tool) hands in a
 * `$dispatch` that routes through the SAME gate the in-process tools use, and
 * a runner that validated anything of its own would be the second validator
 * this plan has repeatedly treated as the bug. What the engine owes is only
 * this: every well-formed `call` frame reaches dispatch exactly once, in
 * order, and nothing malformed or over-budget ever reaches it at all.
 *
 * ## The shape of the run
 *
 * The script is staged 0600 in a private, random-named scratch directory under
 * the system temp dir; the child is `PHP_BINARY` with `-n` (core PHP plus
 * json — see {@see ScriptPrelude}) execed through
 * {@see ProcessContainment::spawnSpec} with {@see ProcessContainment::scrubbedEnv()},
 * optionally behind a {@see SandboxBackend} the caller supplies (its argv
 * makes the scratch the only writable root and the project read-only; the
 * no-network promise is the CALLER's choice of backend, exactly like Bash's).
 * A unix socketpair carries the NDJSON bridge; the parent end is
 * {@see ProcessContainment::closeOnExec()}-ed so no descendant inherits it.
 * The loop is `stream_select` over stdout, stderr and the bridge in
 * {@see SLICE_SECONDS} slices, calling `$onWait` once per slice so an embedder
 * (the serve loop, a TUI) can pump its own machinery while the script runs.
 *
 * ## Bounds — process bounds, not LLM bounds, so E646 is untouched
 *
 * The wall clock counts SCRIPT time only: it pauses while a bridged call is
 * in flight, so a permission ask or a long inner Bash never kills the script.
 * On expiry the recorded group gets TERM, then KILL, via the standard ladder.
 * Frames are capped at {@see ScriptBridge::MAX_FRAME_BYTES}, calls at
 * {@see MAX_CALLS}, stdout and stderr tails at {@see MAX_OUTPUT_BYTES} each
 * and the return value at {@see MAX_RETURN_BYTES}. No cap cuts silently:
 * every breach is a named entry on {@see ScriptRun}, and the tail that
 * survives is the TAIL (dropped bytes counted from the front).
 *
 * ## Teardown
 *
 * Every path out of the spawn — normal end, expiry, dispatch blowup, a throw
 * from this method's own bookkeeping — runs the reaper on the child with the
 * group id recorded while it was alive, closes every descriptor, and deletes
 * the scratch directory, all in the one `finally` of {@see run()} (the spawn
 * deliberately lives in `run()` itself because that finally is what the
 * SHORT receipt row in tests/Support/DescriptorInheritanceGuardTest.php is
 * keyed on).
 */
final class ScriptRunner
{
    /** One `stream_select` slice; `$onWait` rides once per slice. */
    public const SLICE_SECONDS = 0.2;

    /** Bridged tool calls permitted per run; the next one is refused. */
    public const MAX_CALLS = 256;

    /** Bytes kept (the TAIL) of each of stdout and stderr. */
    public const MAX_OUTPUT_BYTES = 65_536;

    /** The cap on the script's JSON return frame, in bytes. */
    public const MAX_RETURN_BYTES = 65_536;

    /** Consecutive `stream_select()` failures ridden out before giving up. */
    private const MAX_SELECT_FAILURES = 50;

    /**
     * Run $code as $chdir against the injected dispatch/catalog pair.
     *
     * @param \Closure(string, array<array-key, mixed>): array{content?: mixed, isError?: bool} $dispatch
     *        invoked once per accepted `call` frame; its result shape is the
     *        bridge's `{content,isError}` contract. The caller owns everything
     *        it does — gate, hooks, ledger — and this runner calls it on no
     *        other path. A throw from dispatch is answered as an error frame
     *        for THAT call (the bridge must not die mid-run) and never
     *        replays.
     * @param \Closure(): list<array{name: string, signature?: string}> $catalog
     *        invoked per `list` frame; the budgeted one-line signatures
     *        Code Mode's `tools()` shows.
     * @param SandboxBackend|null $jail null runs unjailed — legal because
     *        the runner holds no policy and the caller decides what absence
     *        of a jail means; a named backend whose binary cannot resolve on
     *        this host refuses WITHOUT spawning (a deeper "does it start?"
     *        probe belongs to the caller's capture path, not a second spawn
     *        site here).
     * @param float $wallSeconds SCRIPT-time ceiling; paused across dispatch,
     *        never a request timeout (E646).
     * @param (\Closure(): void)|null $onWait called once per select slice.
     */
    public static function run(
        string $code,
        string $chdir,
        \Closure $dispatch,
        \Closure $catalog,
        ?SandboxBackend $jail,
        float $wallSeconds,
        ?\Closure $onWait = null,
    ): ScriptRun {
        if (!is_dir($chdir)) {
            return ScriptRun::refused('the working directory ' . $chdir . ' does not exist');
        }

        if ($jail !== null && $jail->resolvedBinary() === '') {
            return ScriptRun::refused(
                'the ' . $jail->name() . ' jail binary was not found on this host, so the script '
                    . 'would run OUTSIDE its jail; refusing rather than promising a fence that is not there',
            );
        }

        $scratch = sys_get_temp_dir() . '/candy-code-script-' . bin2hex(random_bytes(8));
        $staged = $scratch . '/script.php';
        try {
            PrivateDir::ensure($scratch, 'script scratch');
            $head = ltrim($code);
            $payload = $head !== '' && !str_starts_with($head, '<?php') ? "<?php\n" . $code : $code;
            if (@file_put_contents($staged, $payload, LOCK_EX) === false) {
                return ScriptRun::refused('the scratch directory rejected the staged script');
            }
            @chmod($staged, 0o600);
        } catch (\Throwable $t) {
            self::discardScratch($scratch);

            return ScriptRun::refused('script staging failed: ' . get_class($t) . ': ' . $t->getMessage());
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::discardScratch($scratch);

            return ScriptRun::refused('the bridge socketpair could not be created on this host');
        }
        [$parentEnd, $childEnd] = $pair;
        // The parent's own end must not ride into any descendant — a held
        // write end is the exact shape that keeps the reader from ever
        // seeing EOF (the lesson ProcessContainment's closeOnExec doc-block
        // tells).
        ProcessContainment::closeOnExec($parentEnd);

        $argv = [
            ...($jail?->argv($scratch, false, null, $chdir) ?? []),
            \PHP_BINARY,
            '-n',
            '-d',
            'display_errors=stderr',
            '-d',
            'memory_limit=256M',
            '-r',
            ScriptPrelude::source(),
            '--',
            $staged,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
            3 => $childEnd,
        ];
        $pipes = [];
        $process = @\proc_open(
            ProcessContainment::spawnSpec($argv),
            $descriptors,
            $pipes,
            $chdir,
            ProcessContainment::scrubbedEnv(),
        );

        $exitCode = -1;
        // The group id is measured AT SIGNAL TIME in the finally, never here:
        // MEASURED (GitCommandHandlers.php's own comment records it), a
        // groupId() taken immediately after the spawn answers null because
        // the setsid wrapper has not reached setsid(2) yet, and the kill then
        // reaches the script leader alone while its children survive. The
        // finally's reap covers every path, spawn failures included, and a
        // null answer there degrades to proc_terminate — the correct answer
        // for a child that never led a group.
        $stdout = '';
        $stderr = '';
        $stdoutDropped = 0;
        $stderrDropped = 0;
        $calls = [];
        $capBreaches = [];
        $notes = [];
        $return = null;
        $timedOut = false;
        $spawnFailed = false;

        try {
            if (!\is_resource($process)) {
                $spawnFailed = true;
            } else {
                // The script reads nothing on stdin (the design closes it at
                // once), and the PARENT's copy of the child's socket end must
                // go now or no bridge read will ever see EOF.
                fclose($pipes[0]);
                fclose($childEnd);

                $bridge = new ScriptBridge();
                $readable = ['bridge' => $parentEnd, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
                foreach ($readable as $stream) {
                    stream_set_blocking($stream, false);
                }
                $eof = ['bridge' => false, 'stdout' => false, 'stderr' => false];
                $startedAt = microtime(true);
                $pausedSeconds = 0.0;
                $callCount = 0;
                $selectFailures = 0;

                while (true) {
                    $elapsed = (microtime(true) - $startedAt) - $pausedSeconds;
                    if ($elapsed >= $wallSeconds) {
                        $timedOut = true;
                        break;
                    }

                    if ($onWait !== null) {
                        $onWait();
                    }

                    $slice = min(self::SLICE_SECONDS, max($wallSeconds - $elapsed, 0.001));
                    $watch = [];
                    foreach ($readable as $name => $stream) {
                        if (!$eof[$name]) {
                            $watch[] = $stream;
                        }
                    }
                    if ($watch === []) {
                        break;
                    }

                    $write = null;
                    $except = null;
                    $seconds = (int) $slice;
                    $micros = (int) (($slice - $seconds) * 1_000_000);
                    $ready = @stream_select($watch, $write, $except, $seconds, $micros);
                    if ($ready === false) {
                        if (++$selectFailures > self::MAX_SELECT_FAILURES) {
                            $timedOut = true;
                            $notes[] = 'stream_select failed repeatedly; the run was abandoned as timed out';
                            break;
                        }
                        continue;
                    }
                    $selectFailures = 0;

                    foreach ($readable as $name => $stream) {
                        if (!in_array($stream, $watch, true)) {
                            continue;
                        }
                        $chunk = @fread($stream, 65_536);
                        if ($chunk === false || $chunk === '') {
                            $eof[$name] = feof($stream);
                            continue;
                        }

                        if ($name === 'bridge') {
                            $bridge->feed($chunk);
                            while (($frame = $bridge->next()) !== null) {
                                $frameBytes = $bridge->lastFrameBytes();
                                $pauseStart = microtime(true);
                                $return = self::handleFrame(
                                    $frame,
                                    $frameBytes,
                                    $dispatch,
                                    $catalog,
                                    $callCount,
                                    $parentEnd,
                                    $calls,
                                    $capBreaches,
                                    $notes,
                                    $return,
                                );
                                $pausedSeconds += microtime(true) - $pauseStart;
                            }
                            if ($bridge->discardingOversize()) {
                                // A child streaming past a cap without
                                // terminating a frame: the breach is NAMED
                                // the moment it is real (the byte count
                                // accrues when the line closes), and the
                                // clock — which is running — will end it.
                                $capBreaches['frame'] ??= 0;
                            }
                            $over = $bridge->takeOversizeBytes();
                            if ($over > 0) {
                                $capBreaches['frame'] = ($capBreaches['frame'] ?? 0) + $over;
                            }
                            continue;
                        }

                        if ($name === 'stdout') {
                            [$stdout, $stdoutDropped] = self::appendTail($stdout, $chunk, $stdoutDropped);
                            continue;
                        }
                        [$stderr, $stderrDropped] = self::appendTail($stderr, $chunk, $stderrDropped);
                    }

                    if (array_filter($eof) === $eof) {
                        break;
                    }
                }
            }
        } finally {
            if (\is_resource($childEnd)) {
                // Open only when the spawn failed — the success path closed
                // it at once, and bridge EOF depends on it being closed.
                fclose($childEnd);
            }
            foreach ([$parentEnd, $pipes[0] ?? null, $pipes[1] ?? null, $pipes[2] ?? null] as $stream) {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
            // THE reap, on every path, called UNCONDITIONALLY at the
            // finally's own level: terminateAndClose is idempotent by
            // contract (a false handle answers null), and the depth-keyed
            // ChildLifetimeScanner reads an if-wrapped closer as conditional.
            // The group id is measured HERE, at signal time, where the
            // kernel's answer is current: at expiry the child is alive by
            // definition (the loop saw it running), and after its exit the
            // zombie's pgid still reads until proc_close reaps it — so a
            // self-exited script's surviving children ride the group kill
            // too. The id rides the standard ladder (TERM, bounded wait,
            // KILL, bounded wait), then proc_close.
            $exitCode = ProcessReaper::terminateAndClose($process, ProcessContainment::groupId($process)) ?? -1;
            self::discardScratch($scratch);
        }

        if ($spawnFailed) {
            return ScriptRun::refused('the script child could not be spawned');
        }

        if ($stdoutDropped > 0) {
            $capBreaches['stdout'] = ($capBreaches['stdout'] ?? 0) + $stdoutDropped;
        }
        if ($stderrDropped > 0) {
            $capBreaches['stderr'] = ($capBreaches['stderr'] ?? 0) + $stderrDropped;
        }
        if ($timedOut) {
            $notes[] = 'the script wall clock expired; the process group was terminated';
        }

        return new ScriptRun(
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $exitCode,
            return: $return,
            calls: $calls,
            timedOut: $timedOut,
            refusal: null,
            capBreaches: $capBreaches,
            notes: $notes,
        );
    }

    /**
     * Act on one decoded frame; returns the possibly-updated return value.
     *
     * Every refusal path here — over-budget call, unknown method, malformed
     * frame the bridge synthesised — answers the child and NEVER touches
     * dispatch: the dispatcher runs exactly once per accepted `call` frame,
     * which is the property the pairing test pins.
     *
     * @param array<string, mixed> $frame
     * @param array<string, int>   $capBreaches in-out: named breaches
     * @param list<array<string, mixed>> $calls in-out: the ledger
     * @param list<string>         $notes in-out
     */
    private static function handleFrame(
        array $frame,
        int $frameBytes,
        \Closure $dispatch,
        \Closure $catalog,
        int &$callCount,
        $parentEnd,
        array &$calls,
        array &$capBreaches,
        array &$notes,
        mixed $return,
    ): mixed {
        $id = isset($frame['id']) && is_int($frame['id']) ? $frame['id'] : null;

        if (array_key_exists('error', $frame) && !array_key_exists('method', $frame)) {
            // A bridge-synthesised refusal (malformed or oversized line). The
            // id, if the bytes named one, gets the echo; an uncorrelatable
            // one answers id null and the prelude surfaces it to the script.
            self::writeFrame($parentEnd, ScriptBridge::encode(ScriptBridge::errorFrame($id, (string) $frame['error'])));

            return $return;
        }

        switch ($frame['method'] ?? null) {
            case 'call':
                $name = (string) ($frame['params']['name'] ?? '');
                if ($callCount >= self::MAX_CALLS) {
                    $capBreaches['calls'] = ($capBreaches['calls'] ?? 0) + 1;
                    $line = ScriptBridge::encode(ScriptBridge::errorFrame(
                        $id,
                        'script-runner: call budget of ' . self::MAX_CALLS . ' exceeded',
                    ));
                    self::writeFrame($parentEnd, $line);
                    $calls[] = ['name' => $name, 'outcome' => ScriptRun::OUTCOME_DENIED, 'bytes' => $frameBytes + strlen($line)];

                    return $return;
                }
                $callCount++;

                $arguments = is_array($frame['params']['arguments'] ?? null) ? $frame['params']['arguments'] : [];
                try {
                    $result = $dispatch($name, $arguments);
                } catch (\Throwable $t) {
                    $result = ['content' => 'script-runner: dispatch raised ' . get_class($t), 'isError' => true];
                    $notes[] = 'dispatch raised ' . get_class($t) . ' for tool ' . $name;
                }
                if (!is_array($result)) {
                    $result = ['content' => 'script-runner: dispatch returned no result', 'isError' => true];
                }

                $content = is_string($result['content'] ?? null) ? $result['content'] : '';
                $isError = (bool) ($result['isError'] ?? false);
                [$line, $dropped] = self::boundedResponseLine((int) ($id ?? 0), $content, $isError);
                if ($dropped > 0) {
                    $capBreaches['response'] = ($capBreaches['response'] ?? 0) + $dropped;
                }
                self::writeFrame($parentEnd, $line);
                $calls[] = [
                    'name' => $name,
                    'outcome' => $isError ? ScriptRun::OUTCOME_ERROR : ScriptRun::OUTCOME_OK,
                    'bytes' => $frameBytes + strlen($line),
                ];

                return $return;

            case 'list':
                try {
                    $rows = $catalog();
                } catch (\Throwable $t) {
                    self::writeFrame($parentEnd, ScriptBridge::encode(ScriptBridge::errorFrame(
                        $id,
                        'script-runner: catalog raised ' . get_class($t),
                    )));

                    return $return;
                }
                $tools = [];
                $listDropped = 0;
                foreach (is_array($rows) ? $rows : [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $tools[] = ['name' => (string) ($row['name'] ?? ''), 'signature' => (string) ($row['signature'] ?? '')];
                }
                // The list rides the same frame cap as everything else: rows
                // past the cap are dropped from the END (the catalog is
                // ordered by the caller) and the drop is named.
                $line = ScriptBridge::encode(['id' => $id, 'result' => ['tools' => $tools]]);
                while (strlen($line) > ScriptBridge::MAX_FRAME_BYTES && $tools !== []) {
                    $droppedRow = array_pop($tools);
                    $listDropped += strlen(ScriptBridge::encode(['name' => $droppedRow['name'], 'signature' => $droppedRow['signature']]));
                    $line = ScriptBridge::encode(['id' => $id, 'result' => ['tools' => $tools]]);
                }
                if ($listDropped > 0) {
                    $capBreaches['response'] = ($capBreaches['response'] ?? 0) + $listDropped;
                }
                self::writeFrame($parentEnd, $line);

                return $return;

            case 'return':
                if ($frameBytes > self::MAX_RETURN_BYTES) {
                    $capBreaches['return'] = ($capBreaches['return'] ?? 0) + ($frameBytes - self::MAX_RETURN_BYTES);
                    $notes[] = 'the script return frame exceeded the ' . self::MAX_RETURN_BYTES . ' byte cap and was dropped';

                    return null;
                }
                if (array_key_exists('error', $frame)) {
                    // The prelude's not-JSON-encodable variant: the value is
                    // absent because it COULD not be carried, and the reason
                    // is the frame's own sentence.
                    $notes[] = 'script return: ' . (string) $frame['error'];

                    return null;
                }

                return $frame['value'] ?? null;

            default:
                self::writeFrame($parentEnd, ScriptBridge::encode(ScriptBridge::errorFrame(
                    $id,
                    'script-runner: unknown method ' . json_encode($frame['method'] ?? null),
                )));

                return $return;
        }
    }

    /**
     * Best-effort write of one encoded frame. A child that has closed the
     * bridge fails the write quietly; the loop notices EOF on its own, and a
     * script still waiting on the parent dies with the group in the finally —
     * no second teardown channel is worth its drift risk.
     */
    private static function writeFrame($parentEnd, string $line): void
    {
        @fwrite($parentEnd, $line);
    }

    /**
     * The `{content,isError}` response line, clipped to one frame with the
     * dropped byte count; content clips from the END (the head of a tool
     * result is the useful half) and the clip is named in the content itself.
     *
     * A clip may land inside a UTF-8 sequence, in which case json refuses and
     * {@see ScriptBridge::encode()}'s fallback answers with the named error
     * instead — honest loss beats a corrupted byte slice, and the breach is
     * already counted by then.
     *
     * @return array{0: string, 1: int}
     */
    private static function boundedResponseLine(int $id, string $content, bool $isError): array
    {
        $line = ScriptBridge::encode(['id' => $id, 'result' => ['content' => $content, 'isError' => $isError]]);
        if (strlen($line) <= ScriptBridge::MAX_FRAME_BYTES) {
            return [$line, 0];
        }

        $marker = '… [content truncated by the bridge frame cap]';
        $original = strlen($content);
        $over = strlen($line) - ScriptBridge::MAX_FRAME_BYTES;
        $keep = max(0, $original - $over - strlen($marker));
        $content = substr($content, 0, $keep) . $marker;
        $line = ScriptBridge::encode(['id' => $id, 'result' => ['content' => $content, 'isError' => $isError]]);

        return [$line, max(0, $original - $keep)];
    }

    /**
     * Append $chunk to a capped TAIL buffer, returning [buffer, dropped].
     *
     * @return array{0: string, 1: int}
     */
    private static function appendTail(string $buffer, string $chunk, int $dropped): array
    {
        $combined = $buffer . $chunk;
        $limit = self::MAX_OUTPUT_BYTES;
        if (strlen($combined) <= $limit) {
            return [$combined, $dropped];
        }

        return [substr($combined, -$limit), $dropped + strlen($combined) - $limit];
    }

    /**
     * Delete the scratch: the staged file is flat by design, but a script (or
     * a jailed one writing into its only writable root) can leave siblings;
     * the enumeration unlinks NAMES and never reads content, and a directory
     * that refuses to empty survives silently rather than throwing inside a
     * finally.
     */
    private static function discardScratch(string $scratch): void
    {
        foreach (@scandir($scratch) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $scratch . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                foreach (@scandir($path) ?: [] as $leaf) {
                    if ($leaf !== '.' && $leaf !== '..') {
                        @unlink($path . '/' . $leaf);
                    }
                }
                @rmdir($path);
            }
            @unlink($path);
        }
        @rmdir($scratch);
    }
}

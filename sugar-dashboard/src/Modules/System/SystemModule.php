<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\System;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Msg\TickMsg;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Dashboard\Module\BoundedCommand;
use SugarCraft\Dashboard\Module\ProcAvailability;
use SugarCraft\Dashboard\Module\RunLaunch;

/**
 * System module that displays CPU, memory, GPU, and uptime statistics.
 *
 * Uses Cmd::tick() for periodic refresh; sampling happens ONLY on the
 * shared TickMsg addressed to this module, so an unrelated message
 * (every keystroke) returns the module untouched — a Model update must
 * not do /proc I/O or spawn children per event.
 *
 * The state array is the single source of truth for everything view()
 * renders; the only direct properties are the internal sampler memos
 * (CPU differential baseline, GPU spawn-failure latch) which are
 * measurement plumbing, deliberately NOT public state.
 */
final class SystemModule extends BaseModule
{
    private const HISTORY_SIZE = 30;
    private const TICK_INTERVAL = 2.0;

    /**
     * Differential baseline for CPU load (jiffies at the previous sample).
     * Percentages need two samples; until the second one arrives the load
     * carries ProcAvailability::UNMEASURED so view() renders the visible
     * 'n/a' sentinel instead of a fabricated 0% bar.
     */
    private ?int $cpuIdleBase = null;
    private ?int $cpuTotalBase = null;

    /**
     * Latch for "nvidia-smi could not be started here at all" — a missing
     * binary is deterministic, so stop attempting the spawn. Transient
     * failures (timeout, unparsable output) do NOT latch: GPU presence is
     * re-checked on the next tick.
     */
    private bool $gpuSpawnFailed = false;

    /**
     * True while an nvidia-smi sample is on the loop. A hung driver answers
     * only at its deadline, so a tick that finds one in flight does not stack
     * a second child behind it.
     */
    private bool $gpuInFlight = false;

    /**
     * Counts every GPU sample started. The newest value names the sample the
     * latch belongs to: a result from an older, still-hung sample must not clear
     * the latch a newer sample holds.
     */
    private int $gpuSampleSeq = 0;

    /**
     * Monotonic second the GPU latch was taken, by the tick that asked for the
     * sample; the latch counts from the child's launch once it has one (see
     * {@see gpuLatched()}).
     */
    private ?float $gpuInFlightSince = null;

    /** When the in-flight sample's child was actually launched; see {@see gpuLatched()}. */
    private ?RunLaunch $gpuInFlightLaunch = null;

    /**
     * @param float $refreshSeconds sampling cadence; the constructor knob
     *     exists so hosts (and tests) can retune what used to be a frozen
     *     class constant.
     * @param ?LoopInterface $loop  The loop the host runs. Pass the Program's loop
     *     when it is not the global one, or nvidia-smi's pipes and timers sit on
     *     a loop nobody runs and the GPU row never updates.
     */
    public function __construct(
        private readonly float $refreshSeconds = self::TICK_INTERVAL,
        private readonly ?string $name = null,
        private readonly ?LoopInterface $loop = null,
    ) {
    }

    public function name(): string
    {
        return $this->name ?? 'system';
    }

    public function init(): ?\Closure
    {
        return $this->selfTickCmd();
    }

    public function update(Msg $msg): array
    {
        // Only ticks addressed to this module sample. Everything else — keys,
        // focus changes, resizes — passes through with zero I/O.
        if ($msg instanceof GpuSampleMsg && $msg->source === $this->name()) {
            return [$this->withGpuSample($msg), null];
        }

        if (!$msg instanceof TickMsg || !$msg->isFor($this->name())) {
            return [$this, null];
        }

        $next = $this->withSystemState();
        $tick = $next->selfTickCmd();
        if ($next->gpuLatched() || $next->gpuSpawnFailed) {
            return [$next, $tick];
        }

        // nvidia-smi runs on the loop; its answer returns as a GpuSampleMsg. The
        // tick itself never waits on the child.
        $next = $next->withGpuInFlight();

        return [$next, $tick === null ? $next->gpuSampleCmd() : Cmd::batch($tick, $next->gpuSampleCmd())];
    }

    private function withGpuInFlight(): static
    {
        $clone = clone $this;
        $clone->gpuInFlight = true;
        $clone->gpuInFlightSince = hrtime(true) / 1_000_000_000;
        $clone->gpuInFlightLaunch = new RunLaunch();
        $clone->gpuSampleSeq = $this->gpuSampleSeq + 1;

        return $clone;
    }

    private function withGpuSample(GpuSampleMsg $sample): static
    {
        // Only the sample the latch belongs to may settle it. An older sample that
        // was still hung past the ceiling settles later and is stale; it is dropped.
        if (!$this->gpuInFlight || $sample->sample !== $this->gpuSampleSeq) {
            return $this;
        }

        $clone = $this->withState([
            'gpuLoad' => $sample->load,
        ]);
        $clone->gpuSpawnFailed = $sample->spawnFailed;
        $clone->gpuInFlight = false;
        $clone->gpuInFlightSince = null;
        $clone->gpuInFlightLaunch = null;

        return $clone;
    }

    /**
     * A GPU latch holds only while its sample can still settle. The Cmd belongs
     * to the host, and a host that drops it never delivers a GpuSampleMsg, so
     * past the settle ceiling the next tick samples again rather than freezing
     * the GPU row for the rest of the session. The ceiling counts from the
     * child's launch once the host has run the Cmd, so a late-run sample is never
     * stacked over while its nvidia-smi is still alive.
     */
    private function gpuLatched(): bool
    {
        if (!$this->gpuInFlight) {
            return false;
        }

        $since = $this->gpuInFlightLaunch?->latchedSince((float) $this->gpuInFlightSince)
            ?? (float) $this->gpuInFlightSince;
        $elapsed = hrtime(true) / 1_000_000_000 - $since;

        return $elapsed < BoundedCommand::SETTLE_CEILING_SECONDS;
    }

    protected function tickIntervalSeconds(): ?float
    {
        return $this->refreshSeconds;
    }

    public function view(): string
    {
        $state = $this->state();
        $cpu = (float) ($state['cpuLoad'] ?? ProcAvailability::UNMEASURED);
        $mem = (float) ($state['memLoad'] ?? ProcAvailability::UNMEASURED);
        $gpu = (float) ($state['gpuLoad'] ?? ProcAvailability::UNMEASURED);
        $uptime = (string) ($state['uptime'] ?? 'unknown');

        $lines = $this->renderLoadRow('CPU', $cpu) . "\n" . $this->renderLoadRow('MEM', $mem);

        // GPU keeps the row-hiding stance this class invented: an absent GPU
        // (negative) removes the row entirely; CPU/MEM render the visible
        // 'n/a' sentinel instead.
        if ($gpu >= 0.0) {
            $lines .= "\n" . $this->renderLoadRow('GPU', $gpu);
        }

        $lines .= sprintf("\nUPTIME %s", $uptime);

        return $lines;
    }

    public function minSize(): array
    {
        return [30, 5];
    }

    /**
     * Produce a clone whose state array carries freshly sampled values and
     * accumulated history; $this is never mutated. The internal sampler
     * memos are carried onto the clone at this single controlled boundary
     * (they are plumbing, not public state — see the property docblocks).
     */
    private function withSystemState(): static
    {
        [$cpuLoad, $idle, $total] = $this->sampleCpu();
        $memLoad = $this->readMemLoad();
        $uptime = $this->readUptime();

        // History must not accumulate unmeasured samples — a sentinel entry
        // would poison trend data with fake idle.
        $cpuState = $this->state();
        $cpuHistory = (array) ($cpuState['cpuHistory'] ?? []);
        $memHistory = (array) ($cpuState['memHistory'] ?? []);
        if ($cpuLoad >= 0.0) {
            $cpuHistory[] = (int) $cpuLoad;
        }
        if ($memLoad >= 0.0) {
            $memHistory[] = (int) $memLoad;
        }
        if (count($cpuHistory) > self::HISTORY_SIZE) {
            array_shift($cpuHistory);
        }
        if (count($memHistory) > self::HISTORY_SIZE) {
            array_shift($memHistory);
        }

        // The GPU row is not sampled here: its value arrives on its own message
        // (see withGpuSample), so the previous reading carries over untouched.
        $clone = $this->withState([
            'cpuLoad' => $cpuLoad,
            'memLoad' => $memLoad,
            'uptime' => $uptime,
            'cpuHistory' => $cpuHistory,
            'memHistory' => $memHistory,
        ]);

        $clone->cpuIdleBase = $idle;
        $clone->cpuTotalBase = $total;

        return $clone;
    }

    /**
     * CPU busy percentage from /proc/stat deltas.
     *
     * @return array{0: float, 1: ?int, 2: ?int} load plus the new
     *               (idle, total) baseline pair for the next sample
     */
    private function sampleCpu(): array
    {
        // The door is probed once per process via ProcAvailability and the
        // answer memoized; absence carries the UNMEASURED sentinel through
        // the model so view() renders 'n/a' instead of a fake 0% bar. A
        // transient probe→read failure degrades the same way WITHOUT
        // latching and self-heals on the next tick.
        if (!ProcAvailability::has('/proc/stat')) {
            return [ProcAvailability::UNMEASURED, $this->cpuIdleBase, $this->cpuTotalBase];
        }

        $stat = @file_get_contents('/proc/stat');
        if ($stat === false) {
            return [ProcAvailability::UNMEASURED, $this->cpuIdleBase, $this->cpuTotalBase];
        }

        if (preg_match('/^cpu\s+(.*)$/m', $stat, $matches) !== 1) {
            // Present-but-malformed content is unmeasured, not zero — a
            // fabricated 0.0 here would render a confidently wrong idle bar.
            return [ProcAvailability::UNMEASURED, $this->cpuIdleBase, $this->cpuTotalBase];
        }

        $fields = preg_split('/\s+/', trim($matches[1]));
        $values = array_map('intval', (array) $fields);

        $user = $values[0] ?? 0;
        $nice = $values[1] ?? 0;
        $system = $values[2] ?? 0;
        $idle = $values[3] ?? 0;
        $iowait = $values[4] ?? 0;
        $irq = $values[5] ?? 0;
        $softirq = $values[6] ?? 0;

        $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq;
        $idleTime = $idle + $iowait;
        $lastIdle = $this->cpuIdleBase;
        $lastTotal = $this->cpuTotalBase;

        if ($lastIdle === null || $lastTotal === null) {
            // First sample: baseline seeded, load not yet computable.
            return [ProcAvailability::UNMEASURED, $idleTime, $total];
        }

        $totalDiff = $total - $lastTotal;
        $idleDiff = $idleTime - $lastIdle;

        if ($totalDiff === 0) {
            // Window stood completely still — a legitimately idle machine.
            return [0.0, $idleTime, $total];
        }

        return [min(100.0, ($totalDiff - $idleDiff) / $totalDiff * 100.0), $idleTime, $total];
    }

    private function readMemLoad(): float
    {
        if (!ProcAvailability::has('/proc/meminfo')) {
            return ProcAvailability::UNMEASURED;
        }

        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return ProcAvailability::UNMEASURED;
        }

        preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $totalMatches);
        preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $availMatches);

        $total = (int) ($totalMatches[1] ?? 0);
        $available = (int) ($availMatches[1] ?? 0);

        if ($total === 0) {
            // Present-but-malformed content is unmeasured, not zero.
            return ProcAvailability::UNMEASURED;
        }

        return ($total - $available) / $total * 100.0;
    }

    /**
     * The GPU sample as a Cmd: nvidia-smi on the loop (no shell, deadline, output
     * cap, guaranteed reap), settling as a GpuSampleMsg. Only ever built when no
     * sample is in flight and the spawn latch is clear.
     */
    private function gpuSampleCmd(): \Closure
    {
        $source = $this->name();
        $sample = $this->gpuSampleSeq;
        $loop = $this->loop ?? Loop::get();
        $launch = $this->gpuInFlightLaunch;

        return Cmd::promise(
            static function () use ($loop, $source, $sample, $launch): PromiseInterface {
                // Stamped when the host actually runs this Cmd, so the latch counts from the launch.
                $launch?->markLaunched();

                return BoundedCommand::runAsync(
                    $loop,
                    ['nvidia-smi', '--query-gpu=utilization.gpu', '--format=csv,noheader,nounits'],
                )->then(
                    static function (array $result) use ($source, $sample): Msg {
                        [$load, $spawnFailed] = self::gpuReading($result);

                        return new GpuSampleMsg($source, $load, $spawnFailed, $sample);
                    },
                    // Unexpected rejection: a transient unmeasured tick, never a stuck in-flight latch.
                    static fn (\Throwable $e): Msg => new GpuSampleMsg($source, -1.0, false, $sample),
                );
            },
        );
    }

    /**
     * The pure reading of one nvidia-smi outcome: the GPU percentage (or -1.0
     * when there is none) plus the spawn-failure latch.
     *
     * @param array{output: string, failure: ?string} $result
     * @return array{0: float, 1: bool}
     */
    private static function gpuReading(array $result): array
    {
        if ($result['failure'] === 'Command could not be started') {
            // Deterministic absence (no binary here): stop attempting spawns.
            return [-1.0, true];
        }

        if ($result['failure'] !== null) {
            // Transient (timeout/interrupted): unmeasured THIS tick, retried next.
            return [-1.0, false];
        }

        $value = trim($result['output']);
        if (is_numeric($value)) {
            return [(float) $value, false];
        }

        // Started fine but answered garbage — treat as transient, not absent.
        return [-1.0, false];
    }

    private function readUptime(): string
    {
        // The degraded answer is the shared visible 'n/a' sentinel. The
        // pre-first-sample view default 'unknown' stays distinct: "not yet
        // sampled" and "never measurable here" are different facts.
        if (!ProcAvailability::has('/proc/uptime')) {
            return ProcAvailability::UNAVAILABLE_SENTINEL;
        }

        $uptimeData = @file_get_contents('/proc/uptime');
        if ($uptimeData === false) {
            return ProcAvailability::UNAVAILABLE_SENTINEL;
        }

        $seconds = (float) trim(explode(' ', $uptimeData)[0]);
        return $this->formatUptime($seconds);
    }

    private function formatUptime(float $seconds): string
    {
        // Cast once: uptime granularity is whole seconds, and PHP 8.3+
        // deprecates the implicit float->int conversion the `%` operator
        // does. intdiv() makes the integer math explicit.
        $secs = (int) $seconds;
        $days = intdiv($secs, 86400);
        $hours = intdiv($secs % 86400, 3600);
        $minutes = intdiv($secs % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        }
        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }
        return "{$minutes}m";
    }

    private function renderBar(float $percent, int $width): string
    {
        if ($width < 1) {
            return '';
        }

        $filled = (int) ($percent / 100 * $width);
        $empty = $width - $filled;

        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }

    /**
     * The pure render decision for one percentage row: a measured value
     * renders "<LABEL> <pct>% <bar>"; the UNMEASURED sentinel renders
     * "<LABEL> n/a" — visible, honest, no fake zero-length bar. Same inputs
     * always produce the same row (no I/O).
     */
    private function renderLoadRow(string $label, float $percent): string
    {
        if ($percent < 0.0) {
            return $label . ' ' . ProcAvailability::UNAVAILABLE_SENTINEL;
        }

        return sprintf('%s %3.0f%% %s', $label, $percent, $this->renderBar($percent, 70));
    }
}

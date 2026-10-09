<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\Gpu\Settled;
use SugarCraft\Top\Collect\Process\AsyncCommand;
use SugarCraft\Top\Collect\Process\CommandOutcome;
use SugarCraft\Top\Collect\Process\CommandResult;

use function React\Promise\resolve;

/**
 * The live BMC reader: `ipmitool` through the in-band `open` interface
 * (/dev/ipmi0 — Linux ipmi_devintf, FreeBSD ipmi(4); the same argv on
 * both), run on the loop by {@see AsyncCommand::launch()} so a
 * multi-second BMC walk never stalls input or rendering.
 *
 * A round runs its commands one after another — never two ipmitool
 * children at once — and {@see poll()} refuses to start a round while one
 * is in flight:
 *  1. once per session: `mc info`, `fru print 0`, `lan print N` for
 *     N = 1..8 until a channel answers with an address (HP iLO 4 answers
 *     on 2, ASUS/AMI on 1), and `sdr dump <cache>` — a private copy of
 *     the BMC's sensor repository;
 *  2. every round: `dcmi power reading` (~0.05 s) — dropped for the
 *     session after {@see DCMI_STRIKES} refusals (a BMC without DCMI);
 *  3. every `$slowMs`: `chassis status`, `sel info` (+ `sel elist last 1`
 *     only when the SEL changed) and `-S <cache> sdr elist` for the
 *     values;
 *  4. once, in the round after the first values landed: `sensor list`
 *     for the six thresholds of every sensor.
 *
 * Why the cache: measured on two BMCs, `sensor list` (values AND
 * thresholds) took 4 s on HP iLO 4 and 14-29 s on a loaded ASUS/AMI
 * board, `sdr elist` without a cache 8.8 s, but `-S <cache> sdr elist`
 * 0.4-0.6 s on both — the slow part is re-reading the repository, not the
 * readings. Thresholds never change at runtime, so the slow walk runs
 * once. Without a writable cache the reader falls back to a plain
 * `sdr elist`.
 *
 * Availability is checked before any spawn, cheaply: an ipmitool binary,
 * a device node, and read+write access to it (root-only by default —
 * "Could not open device" otherwise). A missing piece, a BMC that fails a
 * required command or one that hangs past its timeout (SIGKILLed) puts the
 * reader in exponential backoff; until the retry {@see poll()} answers the
 * cached failure without spawning anything.
 *
 * Mutable on purpose, like {@see \SugarCraft\Top\Panel\Gpu\GpuFeed}: it
 * owns an in-flight child, a cache file and the session's static facts,
 * which the panel's immutable copies must share.
 */
final class Ipmi implements IpmiReader
{
    /** The device nodes ipmitool's open interface tries, in its own order. */
    public const DEVICES = ['/dev/ipmi0', '/dev/ipmi/0', '/dev/ipmidev/0'];

    /** Where to look for ipmitool besides PATH (a non-root PATH often lacks sbin). */
    public const SEARCH = ['/usr/sbin', '/usr/bin', '/sbin', '/bin', '/usr/local/sbin', '/usr/local/bin'];

    /** Seconds for a single quick command (mc, fru, lan, dcmi, chassis, sel info, cached elist). */
    public const QUICK_TIMEOUT = 5.0;

    /** Seconds for a repository walk (`sdr dump`, an uncached `sdr elist`, `sel elist`). */
    public const SDR_TIMEOUT = 30.0;

    /** Seconds for the one threshold walk (`sensor list`: 29 s measured on a loaded AMI BMC). */
    public const THRESHOLD_TIMEOUT = 90.0;

    /** Seconds before a failed threshold walk is tried again. */
    public const THRESHOLD_RETRY = 600.0;

    /** Highest LAN channel probed for the BMC address. */
    public const MAX_CHANNEL = 8;

    /** DCMI refusals before the power reading is dropped for the session. */
    public const DCMI_STRIKES = 3;

    /** First retry delay (seconds) after a failed round; doubles per failure. */
    public const BACKOFF_MIN = 15.0;

    /** First retry delay (seconds) when the BMC is unreachable (no tool / device / access). */
    public const UNAVAILABLE_RETRY = 60.0;

    /** Longest retry delay (seconds). */
    public const BACKOFF_MAX = 600.0;

    private bool $busy = false;

    private bool $binaryResolved = false;

    private ?IpmiInfo $info = null;

    /** The SDR cache file once `sdr dump` wrote it; '' = none (plain elist). */
    private string $cache = '';

    private bool $cacheTried = false;

    private ?SdrCache $store = null;

    /** @var ?list<IpmiSensor> the threshold walk's rows */
    private ?array $thresholds = null;

    private float $thresholdsAt = 0.0;

    /** @var ?list<IpmiSensor> the last `sdr elist` rows, unmerged */
    private ?array $values = null;

    private int $dcmiStrikes = 0;

    private float $nextSlowAt = 0.0;

    private float $retryAt = 0.0;

    private int $failures = 0;

    private int $round = 0;

    private string $selKey = '';

    private string $selLatest = '';

    private IpmiSnapshot $last;

    /**
     * @param \Closure(list<string>, float): PromiseInterface<CommandResult> $runner
     * @param \Closure(): float $clock monotonic seconds
     * @param list<string> $devices
     * @param \Closure(string): bool $access
     */
    private function __construct(
        private readonly \Closure $runner,
        private readonly \Closure $clock,
        private ?string $binary,
        private readonly array $devices,
        private readonly \Closure $access,
        private readonly ?string $cacheDir,
        private readonly \Closure $stuck,
    ) {
        $this->binaryResolved = $binary !== null;
        $this->last = IpmiSnapshot::starting();
    }

    /**
     * @param ?\Closure(list<string>, float): PromiseInterface<CommandResult> $runner default {@see AsyncCommand::launch()}
     * @param ?\Closure(): float $clock monotonic seconds (default hrtime)
     * @param ?string $binary the ipmitool path (default: searched once on PATH + {@see SEARCH})
     * @param ?list<string> $devices device nodes to check (default {@see DEVICES})
     * @param ?\Closure(string): bool $access whether this process may open a device node (default readable and writable)
     * @param ?\Closure(string): bool $stuck whether an earlier child of this binary is still alive
     *        (killed but unreapable: uninterruptible sleep in the IPMI driver); default
     *        {@see AsyncCommand::liveFor()}
     * @param ?string $cacheDir the base the private SDR cache dir goes under (default
     *                         $XDG_RUNTIME_DIR, else the system temp dir); '' = never cache
     */
    public static function new(
        ?\Closure $runner = null,
        ?\Closure $clock = null,
        ?string $binary = null,
        ?array $devices = null,
        ?\Closure $access = null,
        ?string $cacheDir = null,
        ?\Closure $stuck = null,
    ): self {
        return new self(
            $runner ?? static fn (array $argv, float $timeout): PromiseInterface => AsyncCommand::launch($argv, null, $timeout),
            $clock ?? static fn (): float => hrtime(true) / 1e9,
            $binary,
            $devices ?? self::DEVICES,
            $access ?? static fn (string $path): bool => is_readable($path) && is_writable($path),
            $cacheDir,
            $stuck ?? static fn (string $binary): bool => AsyncCommand::liveFor($binary) > 0,
        );
    }

    /** A reader that blocks on each command ({@see AsyncCommand::run()}): probes and scripts outside the loop. */
    public static function blocking(?string $binary = null): self
    {
        return self::new(static fn (array $argv, float $timeout): PromiseInterface => resolve(AsyncCommand::run($argv, $timeout)), null, $binary);
    }

    public function __destruct()
    {
        $this->dropCache();
    }

    public function poll(int $slowMs): ?PromiseInterface
    {
        if ($this->busy) {
            return null;
        }
        $now = ($this->clock)();
        if ($now < $this->retryAt) {
            return resolve($this->last);
        }
        $gate = $this->gate();
        if ($gate !== null) {
            $this->fail($now, $gate, true);

            return resolve($this->last);
        }
        // A child killed at its deadline that has not died yet sits in
        // uninterruptible sleep inside the IPMI driver: another ipmitool
        // would only queue behind it. Back off until it is reaped.
        if (($this->stuck)((string) $this->binary())) {
            $this->fail($now, IpmiSnapshot::failed(IpmiState::Stuck, '', $this->round), false);

            return resolve($this->last);
        }
        $this->busy = true;
        $round = ++$this->round;
        $slow = $now >= $this->nextSlowAt;
        $thresholds = $this->thresholds === null && $this->values !== null && $now >= $this->thresholdsAt;
        $acc = new IpmiRound();

        $chain = resolve(null);
        if ($this->info === null) {
            $chain = $chain->then(fn () => $this->loadInfo($acc));
        }
        if (!$this->cacheTried) {
            $chain = $chain->then(fn () => $acc->failed() ? null : $this->dumpCache());
        }
        if ($this->dcmiStrikes < self::DCMI_STRIKES) {
            $chain = $chain->then(fn () => $acc->failed() ? null : $this->readPower($acc));
        }
        if ($slow) {
            $chain = $chain
                ->then(fn () => $acc->failed() ? null : $this->quick(['chassis', 'status'])->then(static function (CommandResult $r) use ($acc): void {
                    $acc->note($r);
                    $acc->chassis = $r->ok() ? IpmiParser::chassis($r->stdout) : null;
                }))
                ->then(fn () => $acc->failed() ? null : $this->readSel($acc))
                ->then(fn () => $acc->failed() ? null : $this->readValues($acc));
        }
        if ($thresholds) {
            $chain = $chain->then(fn () => $acc->failed() ? null : $this->readThresholds($acc));
        }

        return $chain
            ->then(
                fn (): IpmiSnapshot => $this->finish($acc, $round, $slow, $slowMs),
                function (\Throwable $e) use ($acc, $round): IpmiSnapshot {
                    $acc->fail ??= IpmiState::NoResponse;

                    return $this->finish($acc, $round, false, 0);
                },
            );
    }

    /**
     * Blocking form for the {@see \SugarCraft\Top\Source\Source} contract:
     * one round, settled synchronously. With the default loop runner the
     * round is still pending and this throws.
     *
     * @throws \LogicException while a loop-driven round is pending
     */
    public function sample(): array
    {
        $promise = $this->poll(0);

        return [$promise === null ? $this->last : Settled::value($promise), $this];
    }

    /** The last round's snapshot (a failure snapshot while backing off). */
    public function last(): IpmiSnapshot
    {
        return $this->last;
    }

    /** True while a round's ipmitool child is running. */
    public function busy(): bool
    {
        return $this->busy;
    }

    /** Monotonic time of the next allowed round (0 = none scheduled). */
    public function retryAt(): float
    {
        return $this->retryAt;
    }

    /** The SDR cache file in use ('' = none). */
    public function cache(): string
    {
        return $this->cache;
    }

    /** The resolved ipmitool path, or null when there is none. */
    public function binary(): ?string
    {
        if (!$this->binaryResolved) {
            $this->binaryResolved = true;
            $this->binary = self::find('ipmitool');
        }

        return $this->binary;
    }

    /** First executable `$name` on PATH, then in {@see SEARCH}. */
    public static function find(string $name): ?string
    {
        $dirs = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')), static fn (string $d): bool => $d !== '');
        foreach (array_unique([...$dirs, ...self::SEARCH]) as $dir) {
            $path = rtrim($dir, '/') . '/' . $name;
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** No tool / no device / no access: the failure snapshot, else null. */
    private function gate(): ?IpmiSnapshot
    {
        if ($this->binary() === null) {
            return IpmiSnapshot::failed(IpmiState::NoTool, '', $this->round);
        }
        $device = null;
        foreach ($this->devices as $path) {
            if (file_exists($path)) {
                $device = $path;
                break;
            }
        }
        if ($device === null) {
            return IpmiSnapshot::failed(IpmiState::NoDevice, $this->devices[0] ?? '', $this->round);
        }
        if (!($this->access)($device)) {
            return IpmiSnapshot::failed(IpmiState::NoAccess, $device, $this->round);
        }

        return null;
    }

    /** @return PromiseInterface<mixed> */
    private function loadInfo(IpmiRound $acc): PromiseInterface
    {
        $mc = '';
        $fru = '';

        return $this->quick(['mc', 'info'])
            ->then(function (CommandResult $r) use ($acc, &$mc, &$fru) {
                if (!$r->ok()) {
                    $acc->note($r, true);

                    return null;
                }
                $mc = $r->stdout;

                return $this->quick(['fru', 'print', '0'])->then(function (CommandResult $f) use ($acc, &$fru) {
                    $acc->note($f);
                    $fru = $f->stdout;

                    return $acc->failed() ? null : $this->probeLan(1, null);
                });
            })
            ->then(function (?array $lan) use ($acc, &$mc, &$fru): void {
                if ($acc->failed()) {
                    return;
                }
                [$text, $channel] = $lan ?? ['', null];
                $this->info = IpmiParser::info($mc, $fru, $text, $channel);
            });
    }

    /**
     * `lan print N` for N = `$channel`..8: the first channel with an
     * address wins, else the first that answered at all.
     *
     * @param ?array{0: string, 1: int} $answered
     * @return PromiseInterface<?array{0: string, 1: int}>
     */
    private function probeLan(int $channel, ?array $answered): PromiseInterface
    {
        if ($channel > self::MAX_CHANNEL) {
            return resolve($answered);
        }

        return $this->quick(['lan', 'print', (string) $channel])->then(function (CommandResult $r) use ($channel, $answered): PromiseInterface {
            if ($r->outcome === CommandOutcome::Timeout) {
                return resolve($answered); // a slow LAN stack: stop probing, keep the header without an address
            }
            if ($r->ok() && IpmiParser::lanAddress($r->stdout) !== '') {
                return resolve([$r->stdout, $channel]);
            }

            return $this->probeLan($channel + 1, $answered ?? ($r->ok() ? [$r->stdout, $channel] : null));
        });
    }

    /**
     * `sdr dump` into a private file, once ({@see SdrCache}: a 0700 per-user
     * dir, the file reserved 0600 and its removal registered BEFORE the
     * spawn, stale caches of dead candy-tops swept first). Any failure
     * (no private dir, a BMC that refuses) just means the plain, slower
     * `sdr elist`.
     *
     * @return PromiseInterface<mixed>
     */
    private function dumpCache(): PromiseInterface
    {
        $this->cacheTried = true;
        $store = $this->cacheDir === '' ? null : SdrCache::open($this->cacheDir);
        if ($store === null) {
            return resolve(null);
        }
        $store->sweep();
        $file = $store->reserve();
        if ($file === null) {
            return resolve(null);
        }
        $this->store = $store;

        return $this->run(['sdr', 'dump', $file], self::SDR_TIMEOUT)->then(function (CommandResult $r) use ($store, $file): void {
            clearstatcache(true, $file);
            if ($r->ok() && is_file($file) && filesize($file) > 0) {
                $this->cache = $file;
            } else {
                $store->release($file);
            }
        });
    }

    private function dropCache(): void
    {
        if ($this->cache !== '') {
            $this->store?->release($this->cache);
        }
        $this->cache = '';
    }

    /** @return PromiseInterface<mixed> */
    private function readValues(IpmiRound $acc): PromiseInterface
    {
        $cached = $this->cache !== '' && is_file($this->cache);
        $args = $cached ? ['-S', $this->cache, 'sdr', 'elist'] : ['sdr', 'elist'];

        return $this->run($args, $cached ? self::QUICK_TIMEOUT : self::SDR_TIMEOUT)->then(function (CommandResult $r) use ($acc): void {
            $sensors = $r->outcome === CommandOutcome::Timeout ? [] : IpmiParser::sdrElist($r->stdout);
            if ($sensors === []) {
                $acc->note($r, true);
                if (!$acc->failed()) {
                    $acc->fail = IpmiState::NoResponse; // exit 0 with nothing parsable is still no data
                }

                return;
            }
            $this->values = $sensors;
            $acc->sensors = IpmiParser::merge($sensors, $this->thresholds ?? []);
        });
    }

    /**
     * The one `sensor list` walk, for thresholds only: its timeout or
     * refusal never fails the round (the values are already in), it is
     * just retried after {@see THRESHOLD_RETRY}.
     *
     * @return PromiseInterface<mixed>
     */
    private function readThresholds(IpmiRound $acc): PromiseInterface
    {
        $args = $this->cache !== '' && is_file($this->cache) ? ['-S', $this->cache, 'sensor', 'list'] : ['sensor', 'list'];

        return $this->run($args, self::THRESHOLD_TIMEOUT)->then(function (CommandResult $r) use ($acc): void {
            $rows = $r->outcome === CommandOutcome::Timeout ? [] : IpmiParser::sensorList($r->stdout);
            if ($rows === []) {
                $this->thresholdsAt = ($this->clock)() + self::THRESHOLD_RETRY;

                return;
            }
            $this->thresholds = $rows;
            if ($this->values !== null) {
                $acc->sensors = IpmiParser::merge($this->values, $rows);
            }
        });
    }

    /** @return PromiseInterface<mixed> */
    private function readPower(IpmiRound $acc): PromiseInterface
    {
        return $this->quick(['dcmi', 'power', 'reading'])->then(function (CommandResult $r) use ($acc): void {
            if ($r->outcome === CommandOutcome::Timeout || $r->outcome === CommandOutcome::TooLarge) {
                $acc->note($r);

                return;
            }
            $power = $r->ok() ? IpmiParser::dcmi($r->stdout) : null;
            if ($power === null) {
                $this->dcmiStrikes++;

                return;
            }
            $this->dcmiStrikes = 0;
            $acc->power = $power;
        });
    }

    /** @return PromiseInterface<mixed> */
    private function readSel(IpmiRound $acc): PromiseInterface
    {
        return $this->quick(['sel', 'info'])->then(function (CommandResult $r) use ($acc) {
            $acc->note($r);
            $sel = $r->ok() ? IpmiParser::selInfo($r->stdout) : null;
            if ($sel === null) {
                return null;
            }
            if ($sel->key() === $this->selKey || $sel->entries === 0) {
                $acc->sel = $sel->withLatest($sel->entries === 0 ? '' : $this->selLatest);

                return null;
            }
            $cached = $this->cache !== '' && is_file($this->cache);
            $args = $cached ? ['-S', $this->cache, 'sel', 'elist', 'last', '1'] : ['sel', 'elist', 'last', '1'];

            return $this->run($args, self::SDR_TIMEOUT)->then(function (CommandResult $l) use ($acc, $sel): void {
                $acc->note($l);
                if ($l->ok()) {
                    $this->selKey = $sel->key();
                    $this->selLatest = IpmiParser::selLatest($l->stdout);
                }
                $acc->sel = $sel->withLatest($this->selLatest);
            });
        });
    }

    private function finish(IpmiRound $acc, int $round, bool $slow, int $slowMs): IpmiSnapshot
    {
        $this->busy = false;
        $now = ($this->clock)();
        if ($acc->fail !== null) {
            $this->fail($now, IpmiSnapshot::failed($acc->fail, '', $round), false);

            return $this->last;
        }
        $this->failures = 0;
        $this->retryAt = 0.0;
        if ($slow) {
            // From the END of the round: a slow BMC never runs its walks back to back.
            $this->nextSlowAt = $now + max(0, $slowMs) / 1000;
        }
        return $this->last = new IpmiSnapshot(
            IpmiState::Ready,
            $this->info,
            $acc->power,
            $acc->sensors,
            $acc->chassis,
            $acc->sel,
            '',
            $round,
            $this->thresholds === null && $this->values !== null && $now >= $this->thresholdsAt,
        );
    }

    private function fail(float $now, IpmiSnapshot $failure, bool $unavailable): void
    {
        $this->failures++;
        $base = $unavailable ? self::UNAVAILABLE_RETRY : self::BACKOFF_MIN;
        $this->retryAt = $now + min(self::BACKOFF_MAX, $base * 2 ** min(16, $this->failures - 1));
        $this->last = $failure;
    }

    /**
     * @param list<string> $args
     * @return PromiseInterface<CommandResult>
     */
    private function quick(array $args): PromiseInterface
    {
        return $this->run($args, self::QUICK_TIMEOUT);
    }

    /**
     * @param list<string> $args
     * @return PromiseInterface<CommandResult>
     */
    private function run(array $args, float $timeout): PromiseInterface
    {
        return ($this->runner)([(string) $this->binary(), '-I', 'open', ...$args], $timeout);
    }
}

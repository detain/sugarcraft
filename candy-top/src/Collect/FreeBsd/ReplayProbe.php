<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Makes the FreeBSD collectors non-blocking without rewriting them (S-P02).
 *
 * A collector's `sample()` forks 1-3 children one after another (sysctl,
 * then netstat, then ifconfig...) and waits for each: 8-11 sequential
 * fork+waits per tick on a full panel set, all on the loop thread, so
 * input and rendering stall for their sum. Instead of an async twin of
 * every collector, {@see pump()} REPLAYS the unchanged `sample()`:
 *
 *  1. run it with the probe in replay mode — reads whose answer is known
 *     are served, unknown ones are recorded and answered "nothing";
 *  2. when nothing was missing, that pass IS the real result; otherwise
 *     launch every missing child concurrently ({@see Probe::launchAsync()}),
 *     fold the answers in and replay again (a dependent read, e.g.
 *     ifconfig after netstat, simply surfaces one round later).
 *
 * Replay is sound because the collectors are immutable (a pass leaves no
 * trace but its return value) and every pass is synchronous, so passes of
 * concurrent samples never interleave on the shared instance. Outside a
 * pass the probe is the plain blocking probe, which is what startup
 * detection and every non-pumped caller get. Clock reads, file reads and
 * statvfs are not children and always pass straight through.
 */
final class ReplayProbe implements Probe
{
    /** Rounds of concurrent launches one sample may take: the deepest dependency chain plus slack. */
    private const int MAX_ROUNDS = 4;

    /** @var array<string, string|null>|null the active pass's answers, keyed by argv; null outside a pass */
    private ?array $answers = null;

    /** @var array<string, list<string>> what the active pass wanted and did not have */
    private array $missing = [];

    private function __construct(private readonly Probe $inner)
    {
    }

    public static function over(Probe $inner): self
    {
        return new self($inner);
    }

    /**
     * @param \Closure(): array{0: object, 1: object} $sample a collector's sample()
     *
     * @return PromiseInterface<array{0: object, 1: object}>
     */
    public static function pump(Probe $probe, \Closure $sample): PromiseInterface
    {
        return $probe instanceof self ? $probe->round($sample, [], 1) : resolve($sample());
    }

    public function sysctl(array $names): array
    {
        if ($this->answers === null) {
            return $this->inner->sysctl($names);
        }
        $text = $this->answer(LiveProbe::sysctlArgv($names), $found);

        return $found && $text !== null ? Sysctl::parse($text) : [];
    }

    public function run(array $argv): ?string
    {
        if ($this->answers === null) {
            return $this->inner->run($argv);
        }

        return $this->answer($argv, $found);
    }

    public function launchAsync(array $argv): PromiseInterface
    {
        return $this->inner->launchAsync($argv);
    }

    public function file(string $path): ?string
    {
        return $this->inner->file($path);
    }

    public function space(string $mountpoint): ?array
    {
        return $this->inner->space($mountpoint);
    }

    public function monotonic(): float
    {
        return $this->inner->monotonic();
    }

    public function epoch(): float
    {
        return $this->inner->epoch();
    }

    /** @param list<string> $argv */
    private function answer(array $argv, ?bool &$found): ?string
    {
        $key = implode("\0", $argv);
        $found = array_key_exists($key, $this->answers ?? []);
        if (!$found) {
            $this->missing[$key] = $argv;

            return null;
        }

        return $this->answers[$key];
    }

    /**
     * @param \Closure(): array{0: object, 1: object} $sample
     * @param array<string, string|null>              $answers
     *
     * @return PromiseInterface<array{0: object, 1: object}>
     */
    private function round(\Closure $sample, array $answers, int $round): PromiseInterface
    {
        $this->answers = $answers;
        $this->missing = [];
        try {
            $result = $sample();
            $missing = $this->missing;
        } finally {
            $this->answers = null;
            $this->missing = [];
        }
        if ($missing === [] || $round >= self::MAX_ROUNDS) {
            return resolve($result);
        }
        $launched = [];
        foreach ($missing as $key => $argv) {
            $launched[$key] = $this->inner->launchAsync($argv);
        }

        return all($launched)->then(fn (array $got): PromiseInterface => $this->round($sample, [...$answers, ...$got], $round + 1));
    }
}

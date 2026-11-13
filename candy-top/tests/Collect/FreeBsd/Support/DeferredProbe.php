<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd\Support;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\FreeBsd\Probe;
use SugarCraft\Top\Collect\FreeBsd\Sysctl;

/**
 * A slow-spawn FreeBSD host: every launchAsync() answers from the wrapped
 * FixtureProbe only after `$delay` seconds of LOOP time (a timer, not a
 * sleep), while the blocking run()/sysctl() count how often a collector
 * waited on a child directly. A non-blocking sample must leave `blocked`
 * at zero and let the loop keep turning meanwhile.
 */
final class DeferredProbe implements Probe
{
    public int $blocked = 0;

    /** @var list<list<string>> argv of every launchAsync() call, in order */
    public array $launched = [];

    public function __construct(private readonly FixtureProbe $inner, private readonly float $delay = 0.05)
    {
    }

    public function advance(): void
    {
        $this->inner->advance();
    }

    public function sysctl(array $names): array
    {
        ++$this->blocked;

        return $this->inner->sysctl($names);
    }

    public function run(array $argv): ?string
    {
        ++$this->blocked;

        return $this->inner->run($argv);
    }

    public function launchAsync(array $argv): PromiseInterface
    {
        $this->launched[] = $argv;
        $deferred = new Deferred();
        $answer = $argv[0] === 'sysctl' ? $this->sysctlText($argv) : $this->inner->run($argv);
        Loop::get()->addTimer($this->delay, static function () use ($deferred, $answer): void {
            $deferred->resolve($answer);
        });

        return $deferred->promise();
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

    /** sysctl -i -e <names>: the fixture's matching OIDs rendered back as name=value text. */
    private function sysctlText(array $argv): string
    {
        $out = '';
        foreach ($this->inner->sysctl(array_slice($argv, 3)) as $oid => $value) {
            $out .= "{$oid}={$value}\n";
        }

        return $out;
    }
}

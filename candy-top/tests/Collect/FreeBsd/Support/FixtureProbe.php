<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd\Support;

use SugarCraft\Top\Collect\FreeBsd\Probe;
use SugarCraft\Top\Collect\FreeBsd\Sysctl;

/**
 * A FreeBSD host made of captured text: tests never touch a real machine.
 *
 * Every answer may be a list of frames; {@see advance()} moves every list
 * to its next frame (the last frame repeats), so a delta collector sees a
 * second sample. `sysctl()` honours subtree queries ("dev.cpu" returns
 * every dev.cpu.* OID) and drops unknown OIDs, like `sysctl -i`.
 */
final class FixtureProbe implements Probe
{
    /** @var list<list<string>> argv of every run() call, in order */
    public array $runs = [];

    /** @var list<list<string>> names of every sysctl() call, in order */
    public array $sysctls = [];

    /** @var list<string> mount points of every space() call, in order */
    public array $spaceCalls = [];

    private int $frame = 0;

    /**
     * @param string|list<string>                         $sysctl   sysctl(8) text (either output form), per frame
     * @param array<string, string|null|list<string|null>> $commands argv[0] (or the full argv joined by spaces) => stdout per frame; null = failed run
     * @param float|list<float>                           $monotonic
     * @param array<string, array{0: int, 1: int}|null>   $spaces
     * @param array<string, string>                       $files
     */
    public function __construct(
        private readonly string|array $sysctl = '',
        private readonly array $commands = [],
        private readonly float|array $monotonic = 0.0,
        private readonly float $epoch = 0.0,
        private readonly array $spaces = [],
        private readonly array $files = [],
    ) {
    }

    public static function fixture(string $name): string
    {
        $text = file_get_contents(__DIR__ . '/../../../fixtures/freebsd/' . $name);
        if ($text === false) {
            throw new \RuntimeException("missing fixture {$name}");
        }

        return $text;
    }

    public function advance(): void
    {
        $this->frame++;
    }

    public function sysctl(array $names): array
    {
        $this->sysctls[] = $names;
        $all = Sysctl::parse((string) $this->pick($this->sysctl));
        $out = [];
        foreach ($all as $oid => $value) {
            foreach ($names as $name) {
                if ($oid === $name || str_starts_with($oid, $name . '.')) {
                    $out[$oid] = $value;
                    break;
                }
            }
        }

        return $out;
    }

    public function run(array $argv): ?string
    {
        $this->runs[] = $argv;
        $key = implode(' ', $argv);
        if (array_key_exists($key, $this->commands)) {
            return $this->pick($this->commands[$key]);
        }

        return array_key_exists($argv[0], $this->commands) ? $this->pick($this->commands[$argv[0]]) : null;
    }

    public function file(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function space(string $mountpoint): ?array
    {
        $this->spaceCalls[] = $mountpoint;

        return $this->spaces[$mountpoint] ?? null;
    }

    public function monotonic(): float
    {
        return (float) $this->pick($this->monotonic);
    }

    public function epoch(): float
    {
        return $this->epoch;
    }

    private function pick(mixed $frames): mixed
    {
        if (!is_array($frames)) {
            return $frames;
        }

        return $frames === [] ? null : $frames[min($this->frame, count($frames) - 1)];
    }
}

<?php
/**
 * Phase 0 benchmark — measure Player replay wall-time and peak memory.
 * Run with: php .benchmark/player-benchmark.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Core\{Program, ProgramOptions, Model, Msg, Cmd};

$casPath = __DIR__ . '/../candy-vcr/examples/cassettes/counter.cas';

final class BenchmarkModel implements Model
{
    public function __construct(public int $count = 0) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        $this->count++;
        $cmd = $this->count >= 1000 ? Cmd::quit() : null;
        return [$this, $cmd];
    }

    public function view(): string
    {
        return "tick: {$this->count}\n";
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

$format = new JsonlFormat();
$cassette = $format->read($casPath);

$input = fopen('php://memory', 'r+');
$output = fopen('php://memory', 'r+');

$loop = React\EventLoop\Loop::get();

$startWall = microtime(true);
$startMem = memory_get_peak_usage(true);

$player = Player::open($casPath);
$result = $player->play(
    programFactory: fn($in, $out, $loop) => new Program(
        new BenchmarkModel(),
        new ProgramOptions(input: $in, output: $out, loop: $loop, useAltScreen: false, catchInterrupts: false, hideCursor: false),
    ),
    speed: Player::SPEED_INSTANT,
);

$endWall = microtime(true);
$endMem = memory_get_peak_usage(true);

$wallMs = ($endWall - $startWall) * 1000;
$memMb = ($endMem - $startMem) / 1024 / 1024;

echo "=== Phase 0 Benchmark ===\n";
echo "Cassette: {$casPath}\n";
echo "Events: " . count($cassette->events) . "\n";
echo "Wall time: " . number_format($wallMs, 2) . " ms\n";
echo "Peak memory delta: " . number_format($memMb, 2) . " MB\n";
echo "Result: " . ($result->ok ? "OK" : "FAILED") . "\n";

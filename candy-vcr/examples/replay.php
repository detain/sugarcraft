<?php

declare(strict_types=1);

/**
 * Replay a cassette file back into a fresh Program and assert
 * cell-grid equality via ScreenAssertion.
 *
 * Usage:
 *   php examples/replay.php /tmp/session.cas
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Vcr\Assert\ScreenAssertion;
use SugarCraft\Vcr\Player;

$path = $argv[1] ?? __DIR__ . '/cassettes/counter.cas';
if (!file_exists($path)) {
    fwrite(STDERR, "cassette not found: {$path}\n");
    fwrite(STDERR, "run examples/record.php first, or pass a cassette path as argv[1].\n");
    exit(1);
}

final class CounterModel implements Model
{
    public function __construct(public readonly int $count = 0)
    {
    }
    public function init(): ?\Closure
    {
        return null;
    }
    public function update(Msg $msg): array
    {
        return [new self($this->count + 1), null];
    }
    public function view(): string
    {
        return "counter: {$this->count}\n  press any key, q to quit\n";
    }
    // F3 (round 90): required by candy-core's Model contract; see
    // examples/record.php for the same fix.
    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
}

$player = Player::open($path);
$result = $player->play(
    programFactory: static fn ($input, $output, $loop) => new Program(
        new CounterModel(),
        new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            framerate: 1000.0,
            input: $input,
            output: $output,
            loop: $loop,
        ),
    ),
    assertion: new ScreenAssertion(cols: 80, rows: 24),
    speed: Player::SPEED_INSTANT,
);

echo $result->diffSummary() . "\n";
exit($result->ok ? 0 : 1);

<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;

/**
 * F5 (round 90): Player::play built a socket pair before the fopen and
 * program-factory doors; a throw there released nothing, so every failed
 * replay of a batch leaked two fds plus a stream. The whole prologue now
 * lives inside try/finally.
 */
final class PlayerPrologueLeakTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('fd census requires /proc/self/fd (Linux)');
        }
    }

    public function testFactoryThrowReleasesTheSocketPairAndMemoryStream(): void
    {
        $before = $this->openDescriptorCount();
        try {
            $this->player()->play(
                programFactory: static function (): never {
                    throw new \RuntimeException('candy-vcr-test: factory door');
                },
            );
            $this->fail('expected the throwing factory to abort play()');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('factory door', $e->getMessage());
        } finally {
            $after = $this->openDescriptorCount();
        }

        $this->assertSame($before, $after, 'a prologue throw must not leak descriptors');
    }

    public function testCleanReplayReleasesItsDescriptorsToo(): void
    {
        $before = $this->openDescriptorCount();
        $result = $this->player()->play(
            programFactory: static fn ($input, $output, $loop) => new Program(
                new LeakSmokeModel(),
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
        );
        $after = $this->openDescriptorCount();

        $this->assertSame(1, $result->quitCount, 'the Quit event must have been dispatched');
        $this->assertSame($before, $after);
    }

    private function player(): Player
    {
        return new Player(new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-09-30T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [new Event(t: 0.0, kind: EventKind::Quit, payload: [])],
        ));
    }

    private function openDescriptorCount(): int
    {
        $fds = scandir('/proc/self/fd');

        return is_array($fds) ? count($fds) : -1;
    }
}

/**
 * Minimal candy-core Model for the clean-replay leg — mirrors the shape of
 * examples/replay.php's CounterModel.
 */
final class LeakSmokeModel implements Model
{
    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return 'leak-smoke';
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
}

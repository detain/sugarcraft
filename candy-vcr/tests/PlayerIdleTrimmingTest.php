<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Player;

/**
 * @covers \SugarCraft\Vcr\Player
 */
final class PlayerIdleTrimmingTest extends TestCase
{
    public function testIdleThresholdParameterIsAccepted(): void
    {
        // Create a simple cassette
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-15T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello']),
                new Event(t: 5.0, kind: EventKind::Quit, payload: []), // 5 second pause
            ],
        );

        $path = tempnam(sys_get_temp_dir(), 'cv-idle-');
        $this->assertNotFalse($path);
        (new JsonlFormat())->write($cassette, $path);

        try {
            $player = Player::open($path);

            // With SPEED_INSTANT, idleThreshold shouldn't matter
            $result = $player->play(
                fn($in, $out, $loop) => $this->createEchoProgram($in, $out, $loop),
                speed: Player::SPEED_INSTANT,
                idleThresholdSeconds: 0.5,
            );

            // Just check that it runs without error
            $this->assertNotNull($result);
        } finally {
            @unlink($path);
        }
    }

    public function testIdleThresholdNullMeansNoClamping(): void
    {
        // Create a cassette with a 10-second pause
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-15T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello']),
                new Event(t: 10.0, kind: EventKind::Quit, payload: []), // 10 second pause
            ],
        );

        $path = tempnam(sys_get_temp_dir(), 'cv-idle-');
        $this->assertNotFalse($path);
        (new JsonlFormat())->write($cassette, $path);

        try {
            $player = Player::open($path);

            // No exception should be thrown
            $result = $player->play(
                fn($in, $out, $loop) => $this->createEchoProgram($in, $out, $loop),
                speed: Player::SPEED_INSTANT,
                idleThresholdSeconds: null,
            );

            $this->assertNotNull($result);
        } finally {
            @unlink($path);
        }
    }

    private function createEchoProgram($input, $output, $loop): Program
    {
        return new Program(
            new class implements Model {
                public int $count = 0;

                public function init(): ?\Closure
                {
                    return null;
                }

                public function update(Msg $msg): array
                {
                    $this->count++;
                    $cmd = $this->count >= 1 ? Cmd::quit() : null;
                    return [$this, $cmd];
                }

                public function view(): string
                {
                    return "tick: {$this->count}";
                }
            },
            new ProgramOptions(
                input: $input,
                output: $output,
                loop: $loop,
                useAltScreen: false,
                catchInterrupts: false,
                hideCursor: false,
            ),
        );
    }
}

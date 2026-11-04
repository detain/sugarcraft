<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Vcr\Assert\ByteAssertion;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;

/**
 * mi9: an Input event whose payload reaches no message at the program is
 * no longer intake-silent — it is tallied as undecoded and surfaced on the
 * ReplayResult. Three shapes are refused: an envelope the serializer
 * registry does not accept, a payload with neither a msg envelope nor a
 * raw-byte string, and raw bytes that parse to zero messages.
 */
final class PlayerUndecodedIntakeTest extends TestCase
{
    public function testRefusedInputShapesAreTalliedAndQuitStaysClean(): void
    {
        $cassette = new Cassette(
            $this->intakeHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Input, payload: ['msg' => ['@type' => 'NoSuchSerializerType']]),
                new Event(t: 0.1, kind: EventKind::Input, payload: ['b' => 42]),
                new Event(t: 0.2, kind: EventKind::Input, payload: ['b' => '']),
                new Event(t: 0.3, kind: EventKind::Quit, payload: []),
            ],
        );

        $result = (new Player($cassette))->play(
            programFactory: $this->quietFactory(),
            assertion: new ByteAssertion(),
            speed: Player::SPEED_INSTANT,
            timeoutSeconds: 0.5,
        );

        $this->assertSame(3, $result->inputCount, 'every Input event is still an input event');
        $this->assertSame(3, $result->undecodedInputCount);
        $this->assertSame(4, $result->eventCount, 'undecoded is a subset of input, never a fifth kind');
        $this->assertTrue($result->programQuitCleanly, $result->diffSummary());
    }

    public function testDeliveredInputKeepsTheSkipNoteAbsent(): void
    {
        $cassette = new Cassette(
            $this->intakeHeader(),
            [
                new Event(t: 0.0, kind: EventKind::Input, payload: ['b' => 'x']),
                new Event(t: 0.1, kind: EventKind::Quit, payload: []),
            ],
        );

        $result = (new Player($cassette))->play(
            programFactory: $this->quietFactory(),
            assertion: new ByteAssertion(),
            speed: Player::SPEED_INSTANT,
            timeoutSeconds: 0.5,
        );

        $this->assertSame(1, $result->inputCount);
        $this->assertSame(0, $result->undecodedInputCount);
    }

    /**
     * @return \Closure(resource, resource, LoopInterface): Program
     */
    private function quietFactory(): \Closure
    {
        return static function ($input, $output, LoopInterface $loop): Program {
            if (!is_resource($input) || !is_resource($output)) {
                throw new \TypeError('program factory expects stream resources');
            }
            return new Program(
                new IntakeQuietModel(),
                new ProgramOptions(
                    useAltScreen: false,
                    catchInterrupts: false,
                    hideCursor: false,
                    framerate: 1000.0,
                    input: $input,
                    output: $output,
                    loop: $loop,
                ),
            );
        };
    }

    private function intakeHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-08T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'sugarcraft/candy-vcr@dev',
        );
    }
}

/**
 * Replay stand-in that never self-quits: the cassette's Quit event is the
 * only exit path, so the tally's quit arm is exercised exactly once.
 */
final class IntakeQuietModel implements Model
{
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{Model, ?\SugarCraft\Core\Cmd}
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
}

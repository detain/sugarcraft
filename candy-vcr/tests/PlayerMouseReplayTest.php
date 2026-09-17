<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;

/**
 * Integration coverage for the mode-explicit mouse replay path: recorded
 * OUTPUT must arm the DEC modes that recorded INPUT bytes were produced
 * under, and the Player must route each encoding to the right decoder
 * (legacy forms through MouseReplayDecoder, SGR through InputReader).
 */
final class PlayerMouseReplayTest extends TestCase
{
    public function testX10BytesBecomeMouseMsgWhenProgramEnabledMode1000(): void
    {
        $sink = new ArrayObject();
        $result = $this->replay($sink, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => "\x1b[?1000h"]),
            new Event(t: 0.1, kind: EventKind::Input, payload: ['b' => "\x1b[M\x20\x21\x22"]),
            new Event(t: 0.2, kind: EventKind::Quit, payload: []),
        ]);

        $this->assertTrue($result->programQuitCleanly, $result->diffSummary());
        $mouse = $this->mouseMsgs($sink);
        $this->assertCount(1, $mouse, 'X10 bytes with 1000 enabled must decode to one mouse event');
        $this->assertInstanceOf(MouseClickMsg::class, $mouse[0]);
        $this->assertSame(1, $mouse[0]->x);
        $this->assertSame(2, $mouse[0]->y);
        $this->assertSame(MouseButton::Left, $mouse[0]->button);
    }

    public function testX10BytesAreNotDecodedAsMouseWithoutEnabledModes(): void
    {
        $sink = new ArrayObject();
        $this->replay($sink, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'hello']),
            new Event(t: 0.1, kind: EventKind::Input, payload: ['b' => "\x1b[M\x20\x21\x22"]),
            new Event(t: 0.2, kind: EventKind::Quit, payload: []),
        ]);

        // The legacy decoder stays silent when no reporting mode is on;
        // InputReader (the pre-existing fallback) has no X10 path either,
        // so the bytes reach the program as whatever it always made of
        // them — never as a MouseMsg.
        $this->assertSame([], $this->mouseMsgs($sink));
    }

    public function testSgrBytesKeepFlowingThroughInputReaderUnchanged(): void
    {
        $sink = new ArrayObject();
        $this->replay($sink, [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => "\x1b[?1000;1006h"]),
            new Event(t: 0.1, kind: EventKind::Input, payload: ['b' => "\x1b[<0;5;6M"]),
            new Event(t: 0.2, kind: EventKind::Quit, payload: []),
        ]);

        $mouse = $this->mouseMsgs($sink);
        $this->assertCount(1, $mouse, 'SGR form is self-identifying and must keep using InputReader');
        $this->assertSame(5, $mouse[0]->x);
        $this->assertSame(6, $mouse[0]->y);
    }

    /**
     * @param ArrayObject<int, \SugarCraft\Core\Msg> $sink
     * @param list<Event> $events
     */
    private function replay(ArrayObject $sink, array $events): \SugarCraft\Vcr\ReplayResult
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-09-15T12:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'sugarcraft/candy-vcr@dev',
            ),
            $events,
        );

        $player = new Player($cassette);

        return $player->play(
            programFactory: static function ($input, $output, LoopInterface $loop) use ($sink): Program {
                return new Program(
                    new MouseSpyModel($sink),
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
            },
            speed: Player::SPEED_INSTANT,
        );
    }

    /**
     * @param ArrayObject<int, \SugarCraft\Core\Msg> $sink
     * @return list<MouseMsg>
     */
    private function mouseMsgs(ArrayObject $sink): array
    {
        $mouse = [];
        foreach ($sink as $msg) {
            if ($msg instanceof MouseMsg) {
                $mouse[] = $msg;
            }
        }

        return $mouse;
    }
}

/**
 * Test Model: appends every delivered Msg to a shared sink (survives the
 * Player's model cloning because ArrayObject is copied by reference) and
 * quits when asked, so assertions read the program's real inbox.
 */
final class MouseSpyModel implements Model
{
    /**
     * @param ArrayObject<int, \SugarCraft\Core\Msg> $sink
     */
    public function __construct(public readonly ArrayObject $sink)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{Model, Cmd|null}
     */
    public function update(Msg $msg): array
    {
        $this->sink->append($msg);

        return [$this, null];
    }

    public function view(): string
    {
        return 'spy';
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
}

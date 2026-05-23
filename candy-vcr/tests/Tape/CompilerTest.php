<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tape\Compiler;

final class CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    public function testTypeHelloProducesInputEvents(): void
    {
        $result = Compiler::parseSource('Type "hi"');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertGreaterThan(0, $cassette->eventCount());
        $this->assertSame(EventKind::Input, $cassette->events[0]->kind);
    }

    public function testEnterEmitsCarriageReturn(): void
    {
        $result = Compiler::parseSource('Enter');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame(EventKind::Input, $cassette->events[0]->kind);
        $this->assertSame("\r", $cassette->events[0]->payload['b']);
    }

    public function testTabEmitsTabByte(): void
    {
        $result = Compiler::parseSource('Tab');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\t", $cassette->events[0]->payload['b']);
    }

    public function testBackspaceEmitsDeleteByte(): void
    {
        $result = Compiler::parseSource('Backspace');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x7f", $cassette->events[0]->payload['b']);
    }

    public function testSpaceEmitsSpaceByte(): void
    {
        $result = Compiler::parseSource('Space');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame(' ', $cassette->events[0]->payload['b']);
    }

    public function testEscapeEmitsEscapeByte(): void
    {
        $result = Compiler::parseSource('Escape');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x1b", $cassette->events[0]->payload['b']);
    }

    public function testArrowUpEmitsCsiSequence(): void
    {
        $result = Compiler::parseSource('Up');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x1b[A", $cassette->events[0]->payload['b']);
    }

    public function testArrowDownEmitsCsiSequence(): void
    {
        $result = Compiler::parseSource('Down');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x1b[B", $cassette->events[0]->payload['b']);
    }

    public function testArrowLeftEmitsCsiSequence(): void
    {
        $result = Compiler::parseSource('Left');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x1b[D", $cassette->events[0]->payload['b']);
    }

    public function testArrowRightEmitsCsiSequence(): void
    {
        $result = Compiler::parseSource('Right');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x1b[C", $cassette->events[0]->payload['b']);
    }

    public function testCtrlAEmitsControlCharacter(): void
    {
        $result = Compiler::parseSource('Ctrl+A');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x01", $cassette->events[0]->payload['b']);
    }

    public function testCtrlCEmitsControlCharacter(): void
    {
        $result = Compiler::parseSource('Ctrl+C');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x03", $cassette->events[0]->payload['b']);
    }

    public function testCtrlZEmitsControlCharacter(): void
    {
        $result = Compiler::parseSource('Ctrl+Z');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(1, $cassette->eventCount());
        $this->assertSame("\x1a", $cassette->events[0]->payload['b']);
    }

    public function testSetWidthSetsCols(): void
    {
        $result = Compiler::parseSource('Set Width 800');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(800, $cassette->header->cols);
    }

    public function testSetHeightSetsRows(): void
    {
        $result = Compiler::parseSource('Set Height 600');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(600, $cassette->header->rows);
    }

    public function testSetThemeSetsTheme(): void
    {
        $result = Compiler::parseSource('Set Theme "Dracula"');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame('Dracula', $cassette->header->env['_theme'] ?? $cassette->header->env['theme'] ?? 'Dracula');
    }

    public function testSetWidthHeightDefault(): void
    {
        $result = Compiler::parseSource('');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(80, $cassette->header->cols);
        $this->assertSame(24, $cassette->header->rows);
    }

    public function testEnvSetsEnvironmentVariable(): void
    {
        $result = Compiler::parseSource('Env TERM "xterm-256color"');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame('xterm-256color', $cassette->header->env['TERM']);
    }

    public function testOutputIsAccepted(): void
    {
        $result = Compiler::parseSource('Output .vhs/demo.gif');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');
        $this->assertSame(0, $cassette->eventCount());
    }

    public function testSleepAdvancesClockWithoutEmitting(): void
    {
        $result = Compiler::parseSource("Type \"a\"\nSleep 1s\nType \"b\"");
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $events = $cassette->events;
        $this->assertGreaterThan(1, count($events));

        $firstCharTime = $events[0]->t;
        $secondCharTime = $events[count($events) - 1]->t;
        $this->assertGreaterThanOrEqual(1.0, $secondCharTime - $firstCharTime);
    }

    public function testTypingSpeedDefaultIs50ms(): void
    {
        $result = Compiler::parseSource('Type "abc"');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $events = $cassette->events;
        $this->assertGreaterThan(2, count($events));

        $firstTime = $events[0]->t;
        $secondTime = $events[1]->t;
        $thirdTime = $events[2]->t;

        $delta = $secondTime - $firstTime;
        $this->assertGreaterThanOrEqual(0.04, $delta);
    }

    public function testFullTapeFromCounter(): void
    {
        $source = <<<'TAPE'
Output .vhs/counter.gif
Set FontSize 16
Set Width 700
Set Height 220
Set TypingSpeed 60ms
Set Theme "TokyoNight"
Type "php examples/counter.php"
Enter
Sleep 500ms
Up
TAPE;
        $result = Compiler::parseSource($source);
        $this->assertEmpty($result['errors']);

        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame(700, $cassette->header->cols);
        $this->assertSame(220, $cassette->header->rows);
        $this->assertGreaterThan(3, $cassette->eventCount());
    }

    public function testParseErrorIsSkippedInCompilation(): void
    {
        $source = "Type \"a\"\nSet BadKey value\nEnter";
        $result = Compiler::parseSource($source);

        $this->assertCount(1, $result['errors']);
        $this->assertNotEmpty($result['errors'][0]->message);

        $cassette = $this->compiler->compile($result['ast'], '/test.tape');
        $this->assertSame(2, $cassette->eventCount());
    }

    public function testHideAndShowProduceNoEvents(): void
    {
        $result = Compiler::parseSource("Type \"a\"\nHide\nShow\nType \"b\"");
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $inputEvents = array_filter(
            $cassette->events,
            fn($e) => $e->kind === EventKind::Input,
        );
        $this->assertSame(2, count($inputEvents));
    }

    public function testCtrlD(): void
    {
        $result = Compiler::parseSource('Ctrl+D');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame("\x04", $cassette->events[0]->payload['b']);
    }

    public function testCtrlR(): void
    {
        $result = Compiler::parseSource('Ctrl+R');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame("\x12", $cassette->events[0]->payload['b']);
    }

    public function testCtrlW(): void
    {
        $result = Compiler::parseSource('Ctrl+W');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame("\x17", $cassette->events[0]->payload['b']);
    }

    public function testCtrlBracket(): void
    {
        $result = Compiler::parseSource('Ctrl+]');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame("\x1d", $cassette->events[0]->payload['b']);
    }

    public function testCtrlBackslash(): void
    {
        $result = Compiler::parseSource('Ctrl+\\');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame("\x1c", $cassette->events[0]->payload['b']);
    }

    public function testCtrlCircumflex(): void
    {
        $result = Compiler::parseSource('Ctrl+^');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame("\x1e", $cassette->events[0]->payload['b']);
    }

    public function testCtrlUnderscore(): void
    {
        $result = Compiler::parseSource('Ctrl+_');
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $this->assertSame("\x1f", $cassette->events[0]->payload['b']);
    }

    public function testEventTimesAreMonotonicallyIncreasing(): void
    {
        $result = Compiler::parseSource("Type \"abc\"\nEnter\nSleep 100ms\nType \"x\"");
        $cassette = $this->compiler->compile($result['ast'], '/test.tape');

        $times = array_column($cassette->events, 't');
        for ($i = 1; $i < count($times); $i++) {
            $this->assertGreaterThanOrEqual($times[$i - 1], $times[$i]);
        }
    }
}

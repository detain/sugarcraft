<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\ScriptUi\Runtime\InteractiveRuntime;

/**
 * scriptui-73 (finding 099): the DECSCUSR arm of `--cursor-mode` is a
 * live-PTY-only byte path, so the stream leg is proven through an
 * injected paint stream (the exact seam ProgramOptions carries) and the
 * command wiring is pinned by grep like the hideCursor legs in
 * {@see CommandSignalAndCursorWiringTest}.
 */
final class CursorStyleEmitTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("$name=$value");
            }
        }
        $this->savedEnv = [];
    }

    private function setEnv(string $name, string $value): void
    {
        $this->savedEnv[$name] = getenv($name);
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /** @return resource */
    private function paintStream()
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    private function drain($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function testBlinkProgramsTheBlinkingBlockStyle(): void
    {
        $this->setEnv('TERM', 'xterm-256color');
        $paint = $this->paintStream();

        self::assertTrue(InteractiveRuntime::emitCursorStyle('blink', new ProgramOptions(output: $paint)));
        self::assertSame("\e[1 q", $this->drain($paint));
    }

    public function testStaticProgramsTheSteadyBlockStyle(): void
    {
        $this->setEnv('TERM', 'xterm-256color');
        $paint = $this->paintStream();

        self::assertTrue(InteractiveRuntime::emitCursorStyle('static', new ProgramOptions(output: $paint)));
        self::assertSame("\e[2 q", $this->drain($paint));
    }

    public function testBlinkAndStaticAreNoLongerByteIdentical(): void
    {
        $this->setEnv('TERM', 'xterm-256color');
        $blinkPaint = $this->paintStream();
        $staticPaint = $this->paintStream();
        InteractiveRuntime::emitCursorStyle('blink', new ProgramOptions(output: $blinkPaint));
        InteractiveRuntime::emitCursorStyle('static', new ProgramOptions(output: $staticPaint));

        self::assertNotSame($this->drain($blinkPaint), $this->drain($staticPaint));
    }

    public function testHiddenArmPaintsNoStyleByte(): void
    {
        // The cursor hiding is ProgramOptions::$hideCursor's DEC-private
        // arm; the style channel must stay silent so the two never collide.
        $this->setEnv('TERM', 'xterm-256color');
        $paint = $this->paintStream();

        self::assertFalse(InteractiveRuntime::emitCursorStyle('hidden', new ProgramOptions(output: $paint)));
        self::assertSame('', $this->drain($paint));
    }

    public function testDumbTerminalSuppressesTheStyleByte(): void
    {
        // DECSCUSR is a CSI sequence a dumb terminal echoes rather than
        // obeys — same suppression posture as the DEC-private modes.
        $this->setEnv('TERM', 'dumb');
        $paint = $this->paintStream();

        self::assertFalse(InteractiveRuntime::emitCursorStyle('blink', new ProgramOptions(output: $paint)));
        self::assertSame('', $this->drain($paint));
    }

    public function testExitResetRestoresTheTerminalDefault(): void
    {
        $paint = $this->paintStream();
        InteractiveRuntime::emitCursorReset(new ProgramOptions(output: $paint));

        self::assertSame("\e[0 q", $this->drain($paint));
    }

    /**
     * The live-PTY legs (which stream, when each command) are pinned at
     * source level like the -53 wiring census: the pair must exist on
     * both commands and the reset must sit in a finally, so no exit path
     * — submit, Esc, Ctrl-C, timeout — strands a scripted style.
     */
    public function testBothTextCommandsWireTheStylePair(): void
    {
        foreach (['InputCommand', 'WriteCommand'] as $name) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Command/' . $name . '.php');
            self::assertMatchesRegularExpression(
                '/emitCursorStyle\(\$cursorMode, \$options\)/',
                $src,
                "$name must paint the --cursor-mode style before running the Program",
            );
            self::assertMatchesRegularExpression(
                '/finally\s*\{[^}]*\$paintedCursorStyle[^}]*emitCursorReset\(\$options\)/s',
                $src,
                "$name must restore the style on every exit path",
            );
        }
    }
}

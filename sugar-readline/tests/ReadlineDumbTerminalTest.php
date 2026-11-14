<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Input\Event;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\InputDriver;
use SugarCraft\Input\KeyModifier;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\TextPrompt;

/**
 * S-23 (findings 023, 093): the pump must read the room. On a TTY with
 * TERM=dumb (or an unrecognised class) no private-mode pushes and no
 * escape frames; on non-TTY sinks Ctrl+L clears below instead of wiping
 * the screen with escapes that corrupt captured streams.
 *
 * @see \SugarCraft\Readline\Readline::dumbTerminal()
 */
final class ReadlineDumbTerminalTest extends TestCase
{
    private function runPtsChild(string $termEnv): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('util-linux script(1) harness is Linux-shaped here');
        }
        $probe = trim((string) @shell_exec('command -v script 2>/dev/null'));
        if ($probe === '') {
            $this->markTestSkipped('script(1) unavailable');
        }

        $child = __DIR__ . '/Support/dumb_probe_child.php';
        self::assertFileExists($child);

        $termPart = $termEnv === ''
            ? '-u TERM '
            : 'TERM=' . $termEnv . ' ';
        $session = tempnam(sys_get_temp_dir(), 'srdumb');
        self::assertIsString($session);

        $inner = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child);
        $command = "printf 'hi\\r' | script -q -e -c "
            . escapeshellarg('env -u COLUMNS -u LINES ' . $termPart . $inner)
            . ' ' . escapeshellarg($session) . ' >/dev/null 2>&1';

        try {
            exec($command);
            $output = (string) file_get_contents($session);
        } finally {
            @unlink($session);
        }

        if (str_contains($output, 'NOTTY')) {
            $this->markTestSkipped('pts harness degraded on this box');
        }

        return $output;
    }

    /**
     * A scripted driver that ends the run on its own: the queue closes with
     * a submitting Enter, so run() returns before any idle null (an
     * exhausted injected driver never EOFs — S-13 hang law).
     *
     * @param list<Event> $events
     */
    private function scriptedDriver(array $events): InputDriver
    {
        return new class($events) implements InputDriver {
            /** @param list<Event> $events */
            public function __construct(private readonly array $events)
            {
            }

            private int $i = 0;

            public function read(): Event|null
            {
                return $this->events[$this->i++] ?? null;
            }
        };
    }

    // (a) classification: only a TTY sink can be dumb

    public function testNullOutputIsNeverDumb(): void
    {
        $method = new \ReflectionMethod(Readline::class, 'dumbTerminal');
        $this->assertNull($method->invoke(null, null));
    }

    public function testNonTtySinkIsNotClassifiedDumb(): void
    {
        // A socket pair is not a TTY: its frames are the artifact, whatever
        // TERM claims. The gate keys on stream_isatty, never on TERM alone.
        $previous = getenv('TERM');
        putenv('TERM=dumb');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        try {
            $method = new \ReflectionMethod(Readline::class, 'dumbTerminal');
            $this->assertNull($method->invoke(null, $pair[0]));
        } finally {
            if ($previous === false) {
                putenv('TERM');
            } else {
                putenv('TERM=' . $previous);
            }
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    // (b) the interactive truth, via real pts children

    public function testDumbTerminalGetsNoEscapeBytes(): void
    {
        $output = $this->runPtsChild('dumb');
        self::assertIsString($output);
        $this->assertStringContainsString('FINAL submitted=', $output, 'the run must complete');
        $this->assertStringNotContainsString("\x1b[", $output, 'TERM=dumb must see zero escape frames');
        $this->assertStringContainsString('> ', $output, 'the plain prompt still paints');
    }

    public function testUnknownTerminalClassAlsoGetsNoEscapeBytes(): void
    {
        // TERM unset: an unknown class is classified escape-unsafe (fail
        // quiet, not fail noisy).
        $output = $this->runPtsChild('');
        self::assertIsString($output);
        $this->assertStringContainsString('FINAL submitted=', $output);
        $this->assertStringNotContainsString("\x1b[", $output, 'unset TERM is an unknown class — no escapes');
    }

    public function testKnownTerminalStillGetsEscapeFrames(): void
    {
        // Control leg: the silence above is gating, not a dead pump.
        $output = $this->runPtsChild('xterm-256color');
        self::assertIsString($output);
        $this->assertStringContainsString('FINAL submitted=', $output);
        $this->assertStringContainsString("\x1b[", $output, 'a known terminal still gets repaint escapes');
    }

    // (c) finding 093: Ctrl+L on a sink clears below, never wipes

    public function testCtrlLOnNonTtySinkClearsBelowNotScreen(): void
    {
        $memory = fopen('php://memory', 'w+b');
        self::assertIsResource($memory);

        $driver = $this->scriptedDriver([
            new KeyEvent('l', KeyModifier::ctrl(), "\x0c"),
            new KeyEvent('Enter', KeyModifier::none(), "\r"),
        ]);

        (new Readline($driver))->run(new TextPrompt('> '), $memory);

        rewind($memory);
        $captured = (string) stream_get_contents($memory);
        fclose($memory);

        $this->assertStringNotContainsString("\x1b[2J", $captured, 'the scene clear must not reach a log sink');
        $this->assertStringContainsString("\x1b[0J", $captured, 'clear-below keeps frames flowing');
    }

    // (d) plain painter

    public function testPlainViewStripsEscapeSequences(): void
    {
        $method = new \ReflectionMethod(Readline::class, 'plainView');
        $styled = "\x1b[1;36mName:\x1b[0m \x1b[7m\x1b[0m";
        $this->assertSame('Name: ', $method->invoke(null, $styled));
    }
}

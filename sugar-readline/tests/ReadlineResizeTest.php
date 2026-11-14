<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Input\Event;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\ResizeEvent;
use SugarCraft\Input\InputDriver;
use SugarCraft\Input\KeyModifier;
use SugarCraft\Readline\Readline;
use SugarCraft\Readline\SelectionPrompt;
use SugarCraft\Readline\TextPrompt;

/**
 * S-02 (finding 024): a mid-session resize must re-fit the adopted bounds
 * and repaint the whole frame from a cleared display. Pins run through a
 * scripted driver — deterministic, no PTY, pipe-friendly.
 */
final class ReadlineResizeTest extends TestCase
{
    /** @param array<Event|null> $events */
    private function scriptedDriver(array $events): InputDriver
    {
        return new class ($events) implements InputDriver {
            private int $reads = 0;

            /** @param array<Event|null> $events */
            public function __construct(private readonly array $events) {}

            public function read(): Event|null
            {
                return $this->events[$this->reads++] ?? null;
            }
        };
    }

    private function enter(): KeyEvent
    {
        return new KeyEvent('Enter', KeyModifier::none(), "\r");
    }

    /** @return array{0:array<Event|null>} */
    private function runCapture(array $events, object $prompt, $sink, $cap): array
    {
        $final = (new Readline($this->scriptedDriver($events)))->run($prompt, $sink);

        stream_set_blocking($cap, false);
        $captured = '';
        while (false !== ($chunk = fread($cap, 65536))) {
            $captured .= $chunk;
            if ($chunk === '') {
                break;
            }
        }

        return [$final, $captured];
    }

    /**
     * @return array{0:resource,1:resource} socket pair [sink, capture]
     */
    private function sink(): array
    {
        return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    }

    public function testResizeAdoptsBoundsWhenNoneWereSet(): void
    {
        $prevC = getenv('COLUMNS');
        $prevL = getenv('LINES');
        putenv('COLUMNS');
        putenv('LINES');
        [$sink, $cap] = $this->sink();
        try {
            $prompt = new TextPrompt('> ');
            foreach (str_split(str_repeat('x', 30)) as $ch) {
                $prompt = $prompt->handleChar($ch);
            }
            [$final, $captured] = $this->runCapture(
                [new ResizeEvent(20, 6), $this->enter()],
                $prompt,
                $sink,
                $cap
            );
            $this->assertSame(20, $final->displayWidth(), 'unset bound follows the resize');
            // The post-resize frame must be a full repaint: clear + home,
            // then a climb-free erase (lastPaintedRows was reset).
            $this->assertMatchesRegularExpression('/\x1b\[2J\x1b\[H\r\x1b\[0J/', $captured, 'resize forces a cleared full repaint');
            // And the clipped tail proves the new bound reached view(), not
            // just the accessor: a 30-cell buffer inside 20 columns cannot
            // print in full.
            $this->assertStringContainsString('…', $captured);
            // Every repaint AFTER the resize marker must be clipped to the
            // new bound: no frame past the clear may print the full 30-run.
            $afterResize = substr($captured, strpos($captured, "\x1b[2J"));
            $this->assertStringNotContainsString(str_repeat('x', 30), $afterResize, 'post-resize frames must not run the over-long buffer unclipped');
        } finally {
            if ($prevC === false) {
                putenv('COLUMNS');
            } else {
                putenv('COLUMNS=' . $prevC);
            }
            if ($prevL === false) {
                putenv('LINES');
            } else {
                putenv('LINES=' . $prevL);
            }
            fclose($sink);
            fclose($cap);
        }
    }

    public function testExplicitWidthSurvivesResize(): void
    {
        $prevC = getenv('COLUMNS');
        putenv('COLUMNS');
        [$sink, $cap] = $this->sink();
        try {
            $prompt = (new TextPrompt('> '))->withDisplayWidth(80);
            [$final] = $this->runCapture([new ResizeEvent(20, 6), $this->enter()], $prompt, $sink, $cap);
            $this->assertSame(80, $final->displayWidth(), 'a caller-pinned width is not the pump to break');
        } finally {
            if ($prevC === false) {
                putenv('COLUMNS');
            } else {
                putenv('COLUMNS=' . $prevC);
            }
            fclose($sink);
            fclose($cap);
        }
    }

    public function testAdoptedColumnsFollowResizeAndHeightRefitsSelection(): void
    {
        $prevC = getenv('COLUMNS');
        $prevL = getenv('LINES');
        putenv('COLUMNS=40');
        putenv('LINES=10');
        [$sink, $cap] = $this->sink();
        try {
            $choices = [];
            for ($i = 1; $i <= 12; $i++) {
                $choices[] = 'choice-' . $i;
            }
            $prompt = SelectionPrompt::new('Pick: ', $choices);
            [$final, $captured] = $this->runCapture(
                [new ResizeEvent(20, 6), $this->enter()],
                $prompt,
                $sink,
                $cap
            );
            // Start adopted 40x10; the resize replaces the adopted values.
            $this->assertSame(20, $final->displayWidth());
            $this->assertSame(6, $final->viewportRows());
            // Page math followed the height: rows-3 chrome at 6 rows leaves
            // 3 per page -> ceil(12/3) = 4 pages, and the footer tells it.
            $this->assertStringContainsString('Page 1/4', $captured, 'reclamped page size after resize');
        } finally {
            if ($prevC === false) {
                putenv('COLUMNS');
            } else {
                putenv('COLUMNS=' . $prevC);
            }
            if ($prevL === false) {
                putenv('LINES');
            } else {
                putenv('LINES=' . $prevL);
            }
            fclose($sink);
            fclose($cap);
        }
    }

    public function testStreamInputDriverStillIgnoresNothingResizes(): void
    {
        // BC leg: a plain stream driver that never emits ResizeEvent keeps
        // the old one-shot behaviour byte-for-byte (no stray clear codes).
        $prevC = getenv('COLUMNS');
        putenv('COLUMNS=40');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        [$sink, $cap] = $this->sink();
        try {
            fwrite($pair[1], "hi\r");
            $driver = new StreamInputDriver($pair[0]);
            $final = (new Readline($driver))->run(new TextPrompt('> '), $sink);
            $this->assertSame(40, $final->displayWidth());
            stream_set_blocking($cap, false);
            $captured = (string) stream_get_contents($cap);
            $this->assertStringNotContainsString("\x1b[2J", $captured, 'no resize, no clear');
        } finally {
            if ($prevC === false) {
                putenv('COLUMNS');
            } else {
                putenv('COLUMNS=' . $prevC);
            }
            fclose($pair[1]);
            fclose($pair[0]);
            fclose($sink);
            fclose($cap);
        }
    }
}

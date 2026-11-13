<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * `render-tape` used to be silent from the first frame to the last. On a
 * multi-minute render that reads as a hang, so the command now announces
 * what it started and reports every hundredth frame while the frame stream
 * is being consumed — no wall-clock timer, just the frame counter it
 * already has.
 */
final class RenderTapeProgressTest extends TestCase
{
    /** @var string */
    private string $dir;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd required for raster rendering');
        }

        $this->dir = sys_get_temp_dir() . '/candy-vcr-progress-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach ((array) scandir($this->dir) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($this->dir . '/' . $entry)) {
                unlink($this->dir . '/' . $entry);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testSmallTapeAnnouncesTheStartWithoutFrameChatter(): void
    {
        $tape = $this->writeTape('short', [
            'Set FontSize 14',
            'Type "ab"',
        ]);

        $output = $this->render($tape);

        self::assertStringContainsString('Rendering short.tape', $output);
        self::assertStringContainsString('GIF written to', $output);
        self::assertStringNotContainsString(' frames rendered', $output, 'a sub-hundred-frame tape needs no progress rows');
    }

    public function testLongRenderReportsProgressWhileItWorks(): void
    {
        $lines = ['Set FontSize 14', 'Set Width 640', 'Set Height 224'];
        for ($i = 0; $i < 24; $i++) {
            // Unique text per row: identical steady-state frames are collapsed
            // by FrameDedup, and a tape that dedups to nothing proves nothing.
            $lines[] = 'Type "line' . $i . '"';
            $lines[] = 'Enter';
        }

        $output = $this->render($this->writeTape('long', $lines));

        self::assertStringContainsString(' frames rendered', $output);
        self::assertStringContainsString('GIF written to', $output);
    }

    /**
     * @param array<string> $lines
     */
    private function writeTape(string $name, array $lines): string
    {
        $path = $this->dir . '/' . $name . '.tape';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    private function render(string $tape): string
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');

        $exit = (new Application())->run(
            ['candy-vcr', 'render-tape', $tape, '-o', $this->dir . '/out.gif', '--encoder', 'php', '--backend', 'gd'],
            $stdout,
            $stderr,
        );
        self::assertSame(0, $exit);

        rewind($stdout);
        rewind($stderr);

        // S09's dual-stream plumbing sends human progress to stderr (the
        // ConsoleOutputInterface door the command already coded for but
        // Application::run never actually provided); this helper answers
        // with BOTH channels so the pins stay about the messages, not the
        // stream they ride.
        return ((string) stream_get_contents($stdout)) . ((string) stream_get_contents($stderr));
    }
}

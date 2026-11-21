<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\ChafaBinary;
use SugarCraft\Image\Renderer\ChafaRenderer;

/**
 * Rank 74: ChafaRenderer::renderAsync() runs the chafa child on the loop
 * (ChildRun::launch) instead of blocking the caller, and keeps the repaint memo.
 *
 * The fake chafa is armed through the explicit ChafaBinary override, the same
 * way ChafaRendererChildTest arms it. No real chafa is needed.
 *
 * @covers \SugarCraft\Image\Renderer\ChafaRenderer::renderAsync
 */
final class ChafaRendererAsyncTest extends TestCase
{
    /** 1x1 red GIF, base64 — same fixture the sibling chafa tests use. */
    private const GIF_B64 = 'R0lGODlhAQABAIAAAMLCwgAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==';

    private string $dir;
    private string $log;
    private ?string $overrideBackup = null;

    protected function setUp(): void
    {
        ChafaRenderer::reset();
        ChafaRenderer::resetScratch();

        $this->dir = sys_get_temp_dir() . '/p1-13b-fake-chafa-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0700);
        $this->log = $this->dir . '/starts.log';

        $script = <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then
    echo "chafa 99.9 (sugar-image async test fake)"
    exit 0
fi
echo started >> "$FAKE_CHAFA_LOG"
case "$FAKE_CHAFA_MODE" in
    slow)
        sleep 0.4
        printf 'MOCK-ANSI-BYTES'
        exit 0
        ;;
    fail)
        printf 'PARTIAL'
        echo 'boom' >&2
        exit 3
        ;;
    echo-input)
        for last; do :; done
        cat "$last"
        exit 0
        ;;
    *)
        printf 'MOCK-ANSI-BYTES'
        exit 0
        ;;
esac
SH;
        $fake = $this->dir . '/chafa';
        file_put_contents($fake, $script);
        chmod($fake, 0700);

        $armed = getenv(ChafaBinary::ENV_OVERRIDE);
        $this->overrideBackup = $armed === false ? null : $armed;
        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $fake);
        putenv('FAKE_CHAFA_LOG=' . $this->log);
        putenv('FAKE_CHAFA_MODE=ok');
    }

    protected function tearDown(): void
    {
        if ($this->overrideBackup !== null) {
            putenv(ChafaBinary::ENV_OVERRIDE . '=' . $this->overrideBackup);
        } else {
            putenv(ChafaBinary::ENV_OVERRIDE);
        }
        putenv('FAKE_CHAFA_LOG');
        putenv('FAKE_CHAFA_MODE');
        ChafaRenderer::reset();
        ChafaRenderer::resetScratch();
        foreach ((array) glob($this->dir . '/*') as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function source(): ImageSource
    {
        return ImageSource::fromString((string) base64_decode(self::GIF_B64));
    }

    /** Render spawns only: the --version banner probe is memoised and not counted. */
    private function starts(): int
    {
        return is_file($this->log) ? substr_count((string) file_get_contents($this->log), 'started') : 0;
    }

    /**
     * Run $loop until $promise settles (or a 20 s safety stop fires), and
     * return ['ok' => value] or ['err' => throwable].
     *
     * The loop runs only while the promise is still pending. A promise that
     * settled synchronously (an unavailable binary rejects before any spawn)
     * runs its callback, and so `$loop->stop()`, inside `then()`, before
     * `run()`; that stop is lost, and the loop sat out the whole 20 s guard.
     *
     * @return array{ok?: mixed, err?: \Throwable}|null
     */
    private function settle(StreamSelectLoop $loop, PromiseInterface $promise): ?array
    {
        $outcome = null;
        $promise->then(
            static function ($value) use (&$outcome, $loop): void {
                $outcome = ['ok' => $value];
                $loop->stop();
            },
            static function (\Throwable $e) use (&$outcome, $loop): void {
                $outcome = ['err' => $e];
                $loop->stop();
            },
        );
        if ($outcome === null) {
            $guard = $loop->addTimer(20.0, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($guard);
        }

        return $outcome;
    }

    public function testResolvesWithTheSameBytesAsTheBlockingRender(): void
    {
        $loop = new StreamSelectLoop();

        $outcome = $this->settle($loop, (new ChafaRenderer())->renderAsync($this->source(), 10, null, $loop));

        $this->assertSame(['ok' => 'MOCK-ANSI-BYTES'], $outcome);
        $this->assertSame('MOCK-ANSI-BYTES', (new ChafaRenderer())->render($this->source(), 10));
    }

    public function testTheLoopKeepsTurningWhileTheChildRuns(): void
    {
        // The child sleeps for 0.4 s. A 20 ms periodic timer must keep firing
        // during that window: that is what "off-loop" means. A blocking render
        // would show zero ticks here.
        putenv('FAKE_CHAFA_MODE=slow');
        $loop  = new StreamSelectLoop();
        $ticks = 0;
        $timer = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void {
            $ticks++;
        });

        $outcome = $this->settle($loop, (new ChafaRenderer())->renderAsync($this->source(), 10, null, $loop));
        $loop->cancelTimer($timer);

        $this->assertSame(['ok' => 'MOCK-ANSI-BYTES'], $outcome);
        $this->assertGreaterThanOrEqual(3, $ticks, 'other watchers ran while chafa encoded');
    }

    public function testAFailingChildRejectsWithTheExitCodeAndTheStderrTail(): void
    {
        putenv('FAKE_CHAFA_MODE=fail');
        $loop = new StreamSelectLoop();

        $outcome = $this->settle($loop, (new ChafaRenderer())->renderAsync($this->source(), 10, null, $loop));

        $this->assertArrayHasKey('err', $outcome ?? []);
        $this->assertInstanceOf(\RuntimeException::class, $outcome['err']);
        $this->assertStringContainsString('exit code 3', $outcome['err']->getMessage());
        $this->assertStringContainsString('boom', $outcome['err']->getMessage());
    }

    public function testAFailedRenderIsNotMemoised(): void
    {
        putenv('FAKE_CHAFA_MODE=fail');
        $loop = new StreamSelectLoop();
        $this->settle($loop, (new ChafaRenderer())->renderAsync($this->source(), 10, null, $loop));

        putenv('FAKE_CHAFA_MODE=ok');
        $outcome = $this->settle($loop, (new ChafaRenderer())->renderAsync($this->source(), 10, null, $loop));

        $this->assertSame(['ok' => 'MOCK-ANSI-BYTES'], $outcome, 'a second attempt is a fresh spawn, not a replayed failure');
        $this->assertSame(2, $this->starts());
    }

    public function testARepeatedFrameResolvesFromTheMemoWithoutSpawning(): void
    {
        $loop     = new StreamSelectLoop();
        $renderer = new ChafaRenderer();

        $first = $this->settle($loop, $renderer->renderAsync($this->source(), 10, null, $loop));
        $this->assertSame(['ok' => 'MOCK-ANSI-BYTES'], $first);
        $this->assertSame(1, $this->starts());

        // The memo answers at call time: the promise is already settled before
        // the loop is entered, and no child is started.
        $resolved = null;
        $renderer->renderAsync($this->source(), 10, null, $loop)
            ->then(static function (string $value) use (&$resolved): void {
                $resolved = $value;
            });

        $this->assertSame('MOCK-ANSI-BYTES', $resolved);
        $this->assertSame(1, $this->starts(), 'an unchanged repaint spawns nothing');
    }

    /** A 1x1 PNG of red $red, built with GD so two frames differ in their bytes. */
    private function frameOf(int $red): ImageSource
    {
        $img = imagecreatetruecolor(1, 1);
        imagefilledrectangle($img, 0, 0, 0, 0, imagecolorallocate($img, $red, 0, 0));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return ImageSource::fromString($bytes);
    }

    /**
     * Two different frames in flight in the same tick: each child must encode
     * its own frame, and a repeat of either must come back from the memo with
     * its own bytes. A shared input path let the second launch overwrite the
     * first before its child read it, so the first frame came back as the second.
     */
    public function testTwoDifferentFramesInFlightEachEncodeTheirOwnBytes(): void
    {
        putenv('FAKE_CHAFA_MODE=echo-input');
        $loop     = new StreamSelectLoop();
        $renderer = new ChafaRenderer();
        $first    = $this->frameOf(200);
        $second   = $this->frameOf(40);
        $this->assertNotSame($first->bytes, $second->bytes);

        $got  = [];
        $fail = null;
        $settle = static function (string $key) use (&$got, &$fail, $loop): array {
            return [
                static function (string $value) use (&$got, $key, $loop): void {
                    $got[$key] = $value;
                    if (count($got) === 2) {
                        $loop->stop();
                    }
                },
                static function (\Throwable $e) use (&$fail, $loop): void {
                    $fail = $e;
                    $loop->stop();
                },
            ];
        };

        [$onFirst, $onFirstFail]   = $settle('first');
        [$onSecond, $onSecondFail] = $settle('second');
        $renderer->renderAsync($first, 10, null, $loop)->then($onFirst, $onFirstFail);
        $renderer->renderAsync($second, 10, null, $loop)->then($onSecond, $onSecondFail);

        $guard = $loop->addTimer(20.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertNull($fail, $fail?->getMessage() ?? '');
        $this->assertSame($first->bytes, $got['first'] ?? null, 'the first child encoded the second frame');
        $this->assertSame($second->bytes, $got['second'] ?? null, 'the second child encoded the wrong frame');

        $repeat = null;
        $renderer->renderAsync($first, 10, null, $loop)
            ->then(static function (string $value) use (&$repeat): void {
                $repeat = $value;
            });
        $this->assertSame($first->bytes, $repeat, 'the memo holds the first frame under its own key');
    }

    public function testAnUnavailableBinaryRejectsWithoutSpawning(): void
    {
        putenv(ChafaBinary::ENV_OVERRIDE . '=' . $this->dir . '/no-such-chafa');
        $loop = new StreamSelectLoop();

        $outcome = $this->settle($loop, (new ChafaRenderer())->renderAsync($this->source(), 10, null, $loop));

        $this->assertArrayHasKey('err', $outcome ?? []);
        $this->assertSame(0, $this->starts());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Image\Capability;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\PacedAsyncRenderer;
use SugarCraft\Image\PacedRenderSession;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\IncrementalRenderer;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\Renderer\SixelRows;

/**
 * D-05 — the paced async pump: bytes must match the one-shot render exactly
 * for every protocol, slice counts must be pinned off row arithmetic (never
 * a wall clock), and the loop must actually be yielded between slices.
 */
final class PacedAsyncRendererTest extends TestCase
{
    private function awaitPromise(PromiseInterface $promise): mixed
    {
        $result = null;
        $rejected = null;

        $promise->then(
            function ($v) use (&$result) {
                $result = $v;
                Loop::stop();
            },
            function ($e) use (&$rejected) {
                $rejected = $e;
                Loop::stop();
            },
        );

        Loop::run();

        if ($rejected !== null) {
            throw $rejected;
        }

        return $result;
    }

    /** A mosaic over a bare renderer with the default box — render() forwards 1:1. */
    private function mosaicOver(\SugarCraft\Image\Renderer\Renderer $renderer): Mosaic
    {
        return new Mosaic($renderer, Capability::universal(), null, null, null);
    }

    /**
     * D-05: sixel parity floor — full slice pump over the band stream equals
     * Mosaic::render byte-for-byte, with the slice count pinned from row
     * arithmetic (head + 80 bands + terminator at rowsPerSlice 3).
     */
    public function testSixelPumpBytesMatchMosaicRender(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
        $expected = $this->mosaicOver(new SixelRenderer())->render($image, 30, 24);

        $paced = new PacedAsyncRenderer(new SixelRows(new SixelRenderer()), sliceMs: 1000, rowsPerSlice: 3);
        $session = $paced->session($image, 30, 24);

        $chunksSeen = [];
        while (!$session->isDone()) {
            $chunksSeen[] = $session->advanceSlice();
        }

        // Canvas is 300×480 px → ceil(480/6)=80 bands, +1 head +1 terminator.
        $this->assertSame($expected, $session->output());
        $this->assertSame(82, $session->rowsConsumed());
        $this->assertSame((int) ceil(82 / 3), $session->sliceCount());
        $this->assertCount((int) ceil(82 / 3), $chunksSeen);
        $this->assertSame(implode('', $chunksSeen), $expected, 'per-slice returns concatenate to the whole');
    }

    /**
     * D-05: kitty parity floor — frame-per-slice pump equals Mosaic::render;
     * the promise path (first slice inline, rest on futureTick) lands the
     * same bytes.
     */
    public function testKittyPumpBytesMatchMosaicRender(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
        $expected = $this->mosaicOver(new KittyRenderer())->render($image, 20, 16);

        $paced = new PacedAsyncRenderer(new KittyRenderer(), sliceMs: 1000, rowsPerSlice: 1);

        $this->assertSame($expected, $this->awaitPromise($paced->renderAsync($image, 20, 16)));

        $session = $paced->session($image, 20, 16);
        while (!$session->isDone()) {
            $session->advanceSlice();
        }
        $payloadLen = strlen(file_get_contents(__DIR__ . '/fixtures/500x400_noise.png'));
        $frames = 1 + (int) ceil($payloadLen / \SugarCraft\Image\KittyOptions::DEFAULT_CHUNK_BYTES);
        $this->assertSame($frames, $session->sliceCount(), 'rowsPerSlice 1 → one frame per slice');
        $this->assertSame($expected, $session->output());
    }

    /**
     * D-05: half-block parity floor — row pump equals Mosaic::render, and
     * ceil(rows / rowsPerSlice) slices is exact.
     */
    public function testHalfBlockPumpBytesMatchMosaicRender(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/checkerboard.png');
        $expected = $this->mosaicOver(new HalfBlockRenderer())->render($image, 16, 8);

        $paced = new PacedAsyncRenderer(new HalfBlockRenderer(), sliceMs: 1000, rowsPerSlice: 3);
        $session = $paced->session($image, 16, 8);
        while (!$session->isDone()) {
            $session->advanceSlice();
        }

        $this->assertSame($expected, $session->output());
        $this->assertSame(8, $session->rowsConsumed());
        $this->assertSame(3, $session->sliceCount(), 'ceil(8/3): resume boundaries must not double-count rows');
    }

    /**
     * D-05: other loop work runs BETWEEN slices — with rowsPerSlice 1 the
     * promise stays pending while queued ticks drain, so progress is
     * interleaved, not scheduled around one blocking encode.
     */
    public function testOtherTicksRunBetweenSlices(): void
    {
        $rows = ['r1', 'r2', 'r3', 'r4', 'r5'];
        $renderer = new class ($rows) implements IncrementalRenderer {
            /** @param list<string> $rows */
            public function __construct(private readonly array $rows) {}

            public function renderRows(ImageSource $image, int $width, ?int $height = null): \Generator
            {
                foreach ($this->rows as $row) {
                    yield $row;
                }
            }
        };

        $log = [];
        $mark = function () use (&$mark, &$log): void {
            $log[] = 'tick';
            // Hard cap: an eternally self-re-arming tick keeps the queue
            // non-empty and React's drain loop never exits — bounded so the
            // interleaving proof terminates deterministically.
            if (count($log) < 9) {
                Loop::futureTick($mark);
            }
        };
        Loop::futureTick($mark);

        $settled = false;
        $paced = new PacedAsyncRenderer($renderer, sliceMs: 1000, rowsPerSlice: 1);
        $promise = $paced->renderAsync(ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png'), 4, 2);
        $promise->then(function () use (&$log, &$settled): void {
            $log[] = 'resolve';
            $settled = true;
        });
        Loop::run();

        $this->assertTrue($settled, 'the pump resolved');
        // Slice 1 ran inline before the loop; slices 2-5 re-arm through the
        // queue, and the promise reaction here settles synchronously with the
        // last pump, so exactly four markers interleave BEFORE the resolution.
        $this->assertSame(['tick', 'tick', 'tick', 'tick', 'resolve'], array_slice($log, 0, 5),
            'queued ticks run between slices — progress interleaves, never wraps one blocking encode');
        $this->assertSame('r1r2r3r4r5', $this->awaitPromise($paced->renderAsync(ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png'), 4, 2)));
    }

    /**
     * D-05: a done session is inert — further advances are no-ops returning
     * '' (the same settle-once posture that makes a reject after resolve
     * unobservable from the promise side).
     */
    public function testAdvanceAfterDoneIsANoop(): void
    {
        $renderer = new class implements IncrementalRenderer {
            public function renderRows(ImageSource $image, int $width, ?int $height = null): \Generator
            {
                yield 'only';
            }
        };
        $session = (new PacedAsyncRenderer($renderer, sliceMs: 1000))->session(
            ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png'),
            4,
            2,
        );

        $this->assertSame('only', $session->advanceSlice());
        $this->assertTrue($session->isDone());
        $this->assertSame('', $session->advanceSlice());
        $this->assertSame('', $session->advanceSlice());
        $this->assertSame(1, $session->sliceCount(), 'inert advances do not count as slices');
        $this->assertSame('only', $session->output());
    }

    /**
     * D-05: a throwing row source rejects the promise; rows already encoded
     * are not mistaken for a resolution.
     */
    public function testThrowingGeneratorRejects(): void
    {
        $renderer = new class implements IncrementalRenderer {
            public function renderRows(ImageSource $image, int $width, ?int $height = null): \Generator
            {
                yield 'ok-';
                throw new \RuntimeException('encode blew up');
            }
        };
        $paced = new PacedAsyncRenderer($renderer, sliceMs: 1000, rowsPerSlice: 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('encode blew up');
        $this->awaitPromise($paced->renderAsync(ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png'), 4, 2));
    }

    /**
     * D-05: budgets are refused fail-fast, never silently defaulted.
     */
    public function testNonPositiveBudgetsAreRefused(): void
    {
        $renderer = new HalfBlockRenderer();

        try {
            new PacedAsyncRenderer($renderer, sliceMs: 0);
            $this->fail('sliceMs 0 must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('>= 1', $e->getMessage());
        }

        try {
            new PacedAsyncRenderer($renderer, rowsPerSlice: -2);
            $this->fail('rowsPerSlice -2 must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('>= 1', $e->getMessage());
        }
    }

    /**
     * D-05: the E646 sentence — slice budget, not request cap — must stay on
     * the record in both pump files, and neither may arm a timer.
     */
    public function testSliceBudgetSentenceSurvivesAndNoTimersAreArmed(): void
    {
        foreach ([
            __DIR__ . '/../src/PacedAsyncRenderer.php',
            __DIR__ . '/../src/PacedRenderSession.php',
        ] as $file) {
            // Docblocks wrap mid-phrase: judge the sentence on flattened text,
            // never on a raw multi-line byte window.
            $src = preg_replace('/\s+/', ' ', (string) file_get_contents($file));
            // A docblock line break leaves the ' * ' gutter as a literal in
            // flattened text — the pattern must bridge it, never relax away.
            $this->assertMatchesRegularExpression(
                '/bounds each\s*\*?\s*slice/i',
                $src,
                'the slice-not-request-cap sentence is load-bearing',
            );
            $this->assertStringNotContainsString('addTimer', $src, 'the pump queues ticks, it never arms clocks');
        }
    }

    /**
     * D-05: a session is a pure function of row count × cap — the same pump
     * re-run from fresh generators lands the identical slice plan (guards
     * against state smuggled between sessions).
     */
    public function testSlicePlanIsDeterministicAcrossRuns(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/500x400_noise.png');
        $plan = [];
        for ($run = 0; $run < 2; ++$run) {
            $paced = new PacedAsyncRenderer(new SixelRows(new SixelRenderer()), sliceMs: 1000, rowsPerSlice: 5);
            $session = $paced->session($image, 30, 24);
            $sizes = [];
            while (!$session->isDone()) {
                $sizes[] = strlen($session->advanceSlice());
            }
            $plan[] = [$sizes, $session->output()];
        }

        $this->assertSame($plan[0][0], $plan[1][0], 'identical per-slice byte sizes across runs');
        $this->assertSame($plan[0][1], $plan[1][1]);
    }
}

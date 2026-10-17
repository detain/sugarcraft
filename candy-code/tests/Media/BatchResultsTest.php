<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Media\BatchResults;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Media\Sd\Response;
use SugarCraft\Code\Media\Sd\SdTransportResult;
use SugarCraft\Code\Support\MediaStore;

/**
 * W4.5 batch fan-out pins: the 2×3 (n_iter × batch_size) response with the
 * grid prepended splits into six samples plus one grid, every sample keeps
 * ITS OWN infotext row under the shift law (infotexts[s + 1] with a grid,
 * unshifted without), and each artifact's seed token comes from the aligned
 * all_seeds list — never a guess. Missing or desynced companions fall back
 * to the base seed the request echoed, documented as an honest "what was
 * asked" value.
 */
final class BatchResultsTest extends TestCase
{
    private string $mediaRoot;

    protected function setUp(): void
    {
        $this->mediaRoot = sys_get_temp_dir() . '/br-media-' . uniqid('r', true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->mediaRoot);
    }

    public function testTheGridPrefixedBatchPairsEverySampleWithItsOwnRowAndSeed(): void
    {
        $decoded = $this->decode($this->envelope(
            ['GRID', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6'],
            ['grid row', 'row one', 'row two', 'row three', 'row four', 'row five', 'row six'],
            ['index_of_first_image' => 1, 'all_seeds' => [101, 102, 103, 104, 105, 106]],
        ));

        $results = BatchResults::fromResponse($decoded, MediaRequest::fromArray(['prompt' => 'duotone']));

        self::assertCount(6, $results->samples, 'images[0] was the grid — seven entries, six samples');
        self::assertNotNull($results->grid);

        $expectedRows = ['row one', 'row two', 'row three', 'row four', 'row five', 'row six'];
        foreach ($expectedRows as $slot => $row) {
            self::assertSame($row, $results->samples[$slot]['infotext'], "sample {$slot} must pair infotexts[" . ($slot + 1) . ']');
            self::assertSame(101 + $slot, $results->samples[$slot]['tokens']['seed'], 'i-th artifact <-> i-th all_seeds entry');
            self::assertSame(BatchResults::ROLE_SAMPLE, $results->samples[$slot]['role']);
            // The off-palette subseed companion rides Response, never the name.
            self::assertArrayNotHasKey('subseed', $results->samples[$slot]['tokens']);
        }
        self::assertSame('grid row', $results->grid['infotext']);
        self::assertSame(BatchResults::ROLE_GRID, $results->grid['role']);
        // The grid is not a sample: no aligned seed exists for it, and with
        // no request seed its token stays honestly unfilled.
        self::assertArrayNotHasKey('seed', $results->grid['tokens']);
    }

    public function testWithoutAGridTheInfotextRowsPairUnshifted(): void
    {
        $decoded = $this->decode($this->envelope(
            ['S1', 'S2'],
            ['first sample', 'second sample'],
            ['all_seeds' => [7, 8]],
        ));

        $results = BatchResults::fromResponse($decoded, MediaRequest::fromArray(['prompt' => 'p']));

        self::assertNull($results->grid);
        self::assertSame('first sample', $results->samples[0]['infotext']);
        self::assertSame('second sample', $results->samples[1]['infotext']);
        self::assertSame(7, $results->samples[0]['tokens']['seed']);
        self::assertSame(8, $results->samples[1]['tokens']['seed']);
    }

    public function testMissingAllSeedsFallsBackToTheBaseSeedTheRequestEchoed(): void
    {
        $decoded = $this->decode($this->envelope(
            ['GRID', 'S1', 'S2'],
            ['grid row', 'one', 'two'],
            ['index_of_first_image' => 1],
        ));

        $results = BatchResults::fromResponse($decoded, MediaRequest::fromArray(['prompt' => 'p', 'seed' => 5]));

        // Documented fallback (§2.3): without the server's per-sample list,
        // every file is named for the seed we SENT — an honest echo, never a
        // fabricated increment. The grid shares it.
        self::assertSame(5, $results->samples[0]['tokens']['seed']);
        self::assertSame(5, $results->samples[1]['tokens']['seed']);
        self::assertNotNull($results->grid);
        self::assertSame(5, $results->grid['tokens']['seed']);
    }

    public function testADesyncedSeedListIsNeverGuessedOntoTheArtifacts(): void
    {
        // three seeds for two samples: Response drops the companion bag, so
        // pairing here falls to the request echo — and with no request seed,
        // the token is absent, letting MediaStore report it as missing.
        $decoded = $this->decode($this->envelope(
            ['S1', 'S2'],
            ['one', 'two'],
            ['all_seeds' => [1, 2, 3]],
        ));

        $aligned = BatchResults::fromResponse($decoded, MediaRequest::fromArray(['prompt' => 'p', 'seed' => 5]));
        self::assertSame(5, $aligned->samples[0]['tokens']['seed'], 'desynced list must not smuggle 1/2 in');

        $silent = BatchResults::fromResponse($decoded, MediaRequest::fromArray(['prompt' => 'p']));
        self::assertArrayNotHasKey('seed', $silent->samples[0]['tokens']);
        self::assertArrayNotHasKey('seed', $silent->samples[1]['tokens']);
    }

    public function testTheAlignedSeedWinsOverTheRequestEchoAndOtherArtsRideTheEcho(): void
    {
        $decoded = $this->decode($this->envelope(
            ['S1'],
            ['one'],
            ['all_seeds' => [9]],
        ));

        $results = BatchResults::fromResponse($decoded, MediaRequest::fromArray([
            'prompt' => 'p', 'seed' => 5, 'steps' => 20, 'cfg_scale' => 7.5,
            'sampler_name' => 'Euler a', 'width' => 512, 'height' => 512,
        ]));

        $tokens = $results->samples[0]['tokens'];
        self::assertSame(9, $tokens['seed'], 'the server-computed seed beats the request echo');
        self::assertSame(20, $tokens['steps']);
        self::assertSame(7.5, $tokens['cfg']);
        self::assertSame('Euler a', $tokens['sampler']);
        self::assertSame(512, $tokens['width']);
        self::assertSame(512, $tokens['height']);
        // model_name has no v1 producer: sd_model is api-owned and refused
        // at the request door, so there is no model to echo.
        self::assertArrayNotHasKey('model_name', $tokens);
    }

    public function testAnEmptyImageListIsAnEmptyResult(): void
    {
        $decoded = $this->decode($this->envelope([], []));

        self::assertTrue(BatchResults::fromResponse($decoded, MediaRequest::fromArray(['prompt' => 'p']))->isEmpty());
    }

    public function testEveryArtifactOfAFlatBatchGetsItsOwnNameAndTheGridTakesTheSuffix(): void
    {
        // Six samples with an identical token map (the missing-all_seeds
        // fallback shape): the [number] tick is what keeps them apart, and
        // the grid — suffixed pattern, same directory — stays unique too.
        $decoded = $this->decode($this->envelope(
            ['GRID', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6'],
            array_fill(0, 7, 'same row'),
            ['index_of_first_image' => 1],
        ));
        $results = BatchResults::fromResponse($decoded, MediaRequest::fromArray(['prompt' => 'p', 'seed' => 7]));

        $store = MediaStore::forSession('flat', $this->mediaRoot);
        $gridStore = MediaStore::forSession('flat', $this->mediaRoot, BatchResults::gridPattern(null));

        $paths = [];
        foreach ($results->samples as $entry) {
            $paths[] = (string) $store->put((string) $entry['artifact']->bytes(), 'png', $entry['tokens'])->path();
        }
        self::assertNotNull($results->grid);
        $paths[] = (string) $gridStore->put((string) $results->grid['artifact']->bytes(), 'png', $results->grid['tokens'])->path();

        self::assertCount(7, array_unique($paths));
        $basenames = array_map('basename', $paths);
        self::assertSame(1, \count(array_filter($basenames, static fn (string $n): bool => str_ends_with($n, BatchResults::GRID_NAME_SUFFIX . '.png'))));
        self::assertSame(6, \count(array_filter($basenames, static fn (string $n): bool => !str_contains($n, 'grid'))));
        foreach ($paths as $path) {
            self::assertLessThanOrEqual(MediaStore::FILENAME_MAX_LENGTH, \strlen(basename($path)));
        }
    }

    public function testPatternOverflowStillYieldsUniqueSuffixedNamesUnderTheCap(): void
    {
        // Coercion edge (plan W4.5): a pattern whose literal + oversized
        // model_name arm overruns the 128-byte cap. Collapse trims the free
        // text, the [number] tick (and the tail append past the first
        // collision) keep a flat batch unique — and the -grid marker must
        // still land on the grid's name, not be truncated away whole.
        $pattern = '[model_name]-[seed]-[number]';
        $store = MediaStore::forSession('overflow', $this->mediaRoot, $pattern);
        $gridStore = MediaStore::forSession('overflow', $this->mediaRoot, BatchResults::gridPattern($pattern));

        $tokens = ['seed' => 7, 'model_name' => str_repeat('m', 200)];

        $names = [];
        for ($i = 0; $i < 3; $i++) {
            $names[] = basename((string) $store->put('bytes', 'png', $tokens)->path());
        }
        $gridName = basename((string) $gridStore->put('bytes', 'png', $tokens)->path());

        self::assertCount(4, array_unique([...$names, $gridName]));
        self::assertStringEndsWith(BatchResults::GRID_NAME_SUFFIX . '.png', $gridName);
        foreach ([...$names, $gridName] as $name) {
            self::assertLessThanOrEqual(MediaStore::FILENAME_MAX_LENGTH, \strlen($name), $name);
        }
    }

    // ---- fixtures ----------------------------------------------------------------

    /**
     * @param list<string>         $images   short stubs; base64-encoded here
     * @param list<string>         $infotexts
     * @param array<string, mixed> $extraInfo
     */
    private function envelope(array $images, array $infotexts, array $extraInfo = []): string
    {
        return json_encode([
            'images' => array_map(static fn (string $s): string => base64_encode($s), $images),
            'parameters' => ['prompt' => 'echo'],
            'info' => json_encode($extraInfo + ['infotexts' => array_values($infotexts)]),
        ]);
    }

    private function decode(string $body): Response
    {
        return Response::generation(SdTransportResult::new(200, $body, 'application/json'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                is_dir($path) ? $this->rrmdir($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

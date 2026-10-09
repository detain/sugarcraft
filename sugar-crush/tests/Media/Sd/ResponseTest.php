<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media\Sd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Infotext;
use SugarCraft\Crush\Media\MediaArtifact;
use SugarCraft\Crush\Media\Sd\Response;
use SugarCraft\Crush\Media\Sd\SdException;
use SugarCraft\Crush\Media\Sd\SdTransportResult;

/**
 * THE GRID-PREPEND LAW under fixture: index_of_first_image === 1 means
 * images[0] is the batch grid, not a sample — artifacts and grid must land in
 * separate slots; missing index means NO grid is assumed. The companions do
 * NOT share one alignment (R1 MAJOR-1): all_seeds / all_subseeds are
 * per-sample, but the server prepends the GRID's infotext at infotexts[0],
 * so sample s reads infotexts[s + 1] whenever a grid was extracted.
 */
final class ResponseTest extends TestCase
{
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const GRID_B64 = 'R1JJRC1TVFVC'; // "GRID-STUB"

    private function fixture(string $name): SdTransportResult
    {
        $body = file_get_contents(__DIR__ . '/../../fixtures/sd/' . $name);
        self::assertNotFalse($body);

        return SdTransportResult::new(200, $body, 'application/json');
    }

    public function testASingleSampleResponseCarriesOneArtifactAndNoGrid(): void
    {
        $parsed = Response::generation($this->fixture('txt2img-response-single.json'));

        self::assertNull($parsed->grid());
        self::assertCount(1, $parsed->artifacts());
        self::assertSame(0, $parsed->indexOfFirstImage);

        $bytes = base64_decode(self::PNG_B64, true);
        self::assertIsString($bytes);
        self::assertSame($bytes, $parsed->artifacts()[0]->bytes());
        self::assertStringStartsWith("\x89PNG", $bytes, 'the fixture stub really is a PNG');

        $infotext = $parsed->artifacts()[0]->infotext();
        self::assertIsArray($infotext);
        self::assertSame('a cat', $infotext[Infotext::PROMPT_KEY]);
        self::assertSame('12345', $infotext['Seed'], 'parse keys raw A1111 labels — lane a canonicalisation lives in applyTo/emit');

        self::assertSame(12345, $parsed->artifacts()[0]->tokenValues()['seed']);
        self::assertSame('a cat', $parsed->response()->parameters()['prompt']);
        self::assertIsString($parsed->response()->info());
    }

    public function testTheGridPrependedBatchSplitsSixSamplesFromOneGrid(): void
    {
        $parsed = Response::generation($this->fixture('txt2img-response-batch-grid.json'));

        self::assertCount(6, $parsed->artifacts(), 'images[0] was the grid — seven entries, six samples');
        $grid = $parsed->grid();
        self::assertInstanceOf(MediaArtifact::class, $grid);
        self::assertSame(base64_decode(self::GRID_B64, true), $grid->bytes());

        // THE INFOTEXT SHIFT: the fixture carries the shape upstream actually
        // emits — seven infotext rows for seven images, infotexts[0] being the
        // GRID's own text. Sample companions therefore live at infotexts[s+1]:
        // p0 is the FIRST SAMPLE's text (read from index 1), never the grid row.
        self::assertSame('p0', $parsed->artifacts()[0]->infotext()[Infotext::PROMPT_KEY]);
        self::assertSame('p5', $parsed->artifacts()[5]->infotext()[Infotext::PROMPT_KEY]);
        self::assertNull($parsed->grid()->infotext(), 'the parser never attaches infotext to the grid slot');
        // Seeds are per-sample lists — they do NOT shift with the infotexts.
        self::assertSame(101, $parsed->artifacts()[0]->tokenValues()['seed']);
        self::assertSame(106, $parsed->artifacts()[5]->tokenValues()['seed']);
        self::assertSame(1, $parsed->indexOfFirstImage);
    }

    public function testTheGridInfotextRowIsSkippedSoSamplesLandOneIndexHigher(): void
    {
        $parsed = Response::generation($this->envelope(
            [self::GRID_B64, self::PNG_B64],
            ['index_of_first_image' => 1, 'infotexts' => ["grid of one\nNegative prompt:\n\nSeed: -1", "p-a\nNegative prompt: n-a"]],
        ));

        self::assertNotNull($parsed->grid());
        self::assertCount(1, $parsed->artifacts());
        self::assertSame('p-a', $parsed->artifacts()[0]->infotext()[Infotext::PROMPT_KEY], 'sample 0 reads infotexts[1] — the grid row at [0] is skipped');
    }

    public function testAMissingIndexKeyAssumesNoGridEvenForSeveralImages(): void
    {
        $parsed = Response::generation($this->fixture('txt2img-response-no-index.json'));

        self::assertNull($parsed->grid());
        self::assertCount(3, $parsed->artifacts());
        self::assertNull($parsed->indexOfFirstImage);
        // Absent index → unshifted companions: sample 0 reads infotexts[0].
        self::assertSame('x', $parsed->artifacts()[0]->infotext()[Infotext::PROMPT_KEY]);
    }

    public function testIndexOneWithASingleEntryLeavesOnlyTheGrid(): void
    {
        $parsed = Response::generation($this->envelope([self::GRID_B64], ['index_of_first_image' => 1]));

        self::assertNotNull($parsed->grid());
        self::assertSame([], $parsed->artifacts());
    }

    public function testDesyncedSeedListsLeaveTokenCarriersUnsetRatherThanMislabeling(): void
    {
        $parsed = Response::generation($this->envelope(
            [self::PNG_B64, self::PNG_B64],
            ['index_of_first_image' => 0, 'all_seeds' => [1, 2, 3]],
        ));

        self::assertCount(2, $parsed->artifacts());
        self::assertArrayNotHasKey('seed', $parsed->artifacts()[0]->tokenValues());
    }

    public function testANonJsonEnvelopeIsAProtocolBreakNotRawThrow(): void
    {
        $this->expectException(SdException::class);
        $this->expectExceptionMessage('not a JSON object');
        Response::generation(SdTransportResult::new(200, '<html>nginx</html>', 'text/html'));
    }

    public function testABrokenImageEntryIsNamedByIndex(): void
    {
        $this->expectException(SdException::class);
        $this->expectExceptionMessage('images[1] failed strict base64');
        Response::generation($this->envelope([self::PNG_B64, '!!!not base64!!!'], []));
    }

    public function testTheExtrasSingleEnvelopeDecodes(): void
    {
        $result = SdTransportResult::new(200, json_encode([
            'image' => self::PNG_B64,
            'html_info' => '<p>upscaled</p>',
        ]), 'application/json');
        self::assertIsString($result->body);

        $parsed = Response::extrasSingle($result);
        self::assertCount(1, $parsed->artifacts());
        self::assertSame('<p>upscaled</p>', $parsed->response()->info());
    }

    public function testTheExtrasBatchEnvelopeKeepsServerNamesAsPaths(): void
    {
        $result = SdTransportResult::new(200, json_encode([
            'images' => [['data' => self::PNG_B64, 'name' => '00014-grid.png']],
        ]), 'application/json');

        $parsed = Response::extrasBatch($result);
        self::assertSame('00014-grid.png', $parsed->artifacts()[0]->path());
    }

    public function testThePngInfoAndInterrogateTextEnvelopesDecode(): void
    {
        $pngInfo = Response::pngInfo(SdTransportResult::new(200, json_encode([
            'info' => 'a cat', 'items' => [], 'parameters' => ['steps' => 9],
        ]), 'application/json'));
        self::assertSame('a cat', $pngInfo->response()->info());
        self::assertSame(['steps' => 9], $pngInfo->response()->parameters());
        self::assertTrue($pngInfo->response()->isEmpty());

        $caption = Response::interrogate(SdTransportResult::new(200, json_encode(['caption' => 'cat on mat']), 'application/json'));
        self::assertSame('cat on mat', $caption->response()->info());

        $this->expectException(SdException::class);
        Response::interrogate(SdTransportResult::new(200, '{}', 'application/json'));
    }

    /** @param list<string> $images @param array<string, mixed> $info */
    private function envelope(array $images, array $info): SdTransportResult
    {
        $body = json_encode(['images' => $images, 'parameters' => new \stdClass(), 'info' => json_encode($info)]);
        self::assertIsString($body);

        return SdTransportResult::new(200, $body, 'application/json');
    }
}

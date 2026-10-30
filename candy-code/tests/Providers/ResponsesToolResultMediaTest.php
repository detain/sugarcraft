<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\AttachmentEncoding;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-TOOL-2.g: the OpenAI Responses `function_call_output` row carries a
 * carried PDF as a native `input_file` part — the document sibling of
 * C-PRV-2.b's `input_image` leg ({@see OpenAiResponsesReasoningTest}), and
 * this file pins the leg's own ladder rungs: a caption plus a readable model
 * and budget turn the bytes into a part; an error row, an empty payload, a
 * forged header, a text-only model, or an oversized file keep the plain
 * caption STRING, byte-identical to the pre-parts spelling. Nothing is ever
 * truncated onto the wire and nothing is dropped silently.
 *
 * The capability law is the C-TOOL-2.e document-gate rule restated for this
 * arm: the provider block's `supportsVision` override buys images, it does
 * not teach a non-vision deployment to read PDFs — pinned as an image/doc
 * pair on the SAME blind-model provider, because it is exactly the asymmetry
 * the arm could have collapsed by consulting the wrong door.
 *
 * Media ORDER (caption, image, document) mirrors the Anthropic `tool_result`
 * blocks leg, and the one-spelling law is pinned at the shared encoder:
 * identical bytes leave both families as identical base64 behind their own
 * shape wrappers. The chat-completions `role: tool` string keeps its
 * recorded decline (AnthropicMessagesWire::toolResultImage docblock) — this
 * is the Responses wire's page, not that one's.
 */
final class ResponsesToolResultMediaTest extends TestCase
{
    use HomeSandboxTrait;

    /** Real PNG magic + filler — sniffImage reads headers only. Named
     *  file-unique: the byte-identical twin lives in
     *  OpenAiResponsesReasoningTest (PNG_BYTES), and the drift guard's
     *  duplication polity wants a human, not this lane, to roster it. */
    private const MEDIA_PNG_BYTES = "\x89PNG\r\n\x1a\n" . 'filler-bytes-not-a-valid-image';

    /** Real PDF head + filler — sniffDocument reads the head only. */
    private const PDF_HEAD = "%PDF-1.4\n";

    private const DOC_NAME = 'quarterly-report.pdf';

    /** The Responses wire's shared document ceiling, restated through the
     *  SAME source of truth the host answers with (one spelling only). */
    private const DOCUMENT_BUDGET = AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES;

    private string $mediaSandboxHome = '';

    /** @var list<array<string, mixed>> */
    private array $mediaWireHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaSandboxHome = sys_get_temp_dir() . '/responses_media_home_' . uniqid('', true);
        $this->useHomeSandbox($this->mediaSandboxHome);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->mediaSandboxHome);
        $this->mediaWireHistory = [];

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    //  Helpers (names file-unique per DuplicatedTestHelperDrift discipline)
    // -------------------------------------------------------------------------

    /** @param list<GuzzleResponse> $queued */
    private function mediaArmProvider(
        array $queued,
        string $model = 'gpt-4o-mini',
        ?bool $visionOverride = null,
    ): OpenAIProvider {
        $this->mediaWireHistory = [];
        $stack = HandlerStack::create(new MockHandler($queued));
        $stack->push(Middleware::history($this->mediaWireHistory));

        return new OpenAIProvider(
            $this->createMock(ClientContract::class),
            $model,
            [],
            modelsBaseUrl: 'https://gw.test/v1',
            modelsApiKey: 'sk-test',
            api: OpenAIProvider::API_RESPONSES,
            responsesClient: new GuzzleClient(['handler' => $stack]),
            supportsVision: $visionOverride,
        );
    }

    /** @param list<\SugarCraft\Code\Messages\Message> $messages */
    private function mediaArmRequest(array $messages, string $model = 'gpt-4o-mini'): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: $messages);
    }

    private static function mediaPdfBytes(int $totalBytes = 0): string
    {
        return $totalBytes <= strlen(self::PDF_HEAD)
            ? self::PDF_HEAD . str_repeat('a', 64)
            : self::PDF_HEAD . str_repeat('a', $totalBytes - strlen(self::PDF_HEAD));
    }

    /** @return array<string, mixed> */
    private function capturedMediaBody(): array
    {
        return json_decode((string) $this->mediaWireHistory[0]['request']->getBody(), true);
    }

    /** One canned non-stream Responses document, empty output — the shape
     *  the history capture stands in for (no live key here). */
    private static function mediaEmptyDoc(): array
    {
        return ['id' => 'resp_media', 'status' => 'completed', 'output' => []];
    }

    /** @param array<int|string, mixed> $row */
    private function completeRow(ToolResultMessage $row, string $model = 'gpt-4o-mini', ?bool $visionOverride = null): mixed
    {
        $this->mediaArmProvider(
            [new GuzzleResponse(200, [], json_encode(self::mediaEmptyDoc(), JSON_THROW_ON_ERROR))],
            model: $model,
            visionOverride: $visionOverride,
        )->complete($this->mediaArmRequest([$row], model: $model));

        return $this->capturedMediaBody()['input'][0]['output'];
    }

    // -------------------------------------------------------------------------
    //  The carried legs
    // -------------------------------------------------------------------------

    public function testDocumentToolRowCarriesCaptionAndInputFilePart(): void
    {
        $output = $this->completeRow(new ToolResultMessage(
            'call_doc',
            'Binary PDF document, 73 bytes',
            documentBytes: self::mediaPdfBytes(),
            documentName: self::DOC_NAME,
        ));

        $this->assertSame([
            ['type' => 'input_text', 'text' => 'Binary PDF document, 73 bytes'],
            ['type' => 'input_file', 'filename' => self::DOC_NAME, 'file_data' => AttachmentEncoding::dataUrl('application/pdf', self::mediaPdfBytes())],
        ], $output, 'the PDF rides its own tool row — the Responses wire never smuggles it into a follow-up turn');
    }

    public function testCaptionImageDocumentOrderMatchesTheAnthropicBlocksLeg(): void
    {
        $output = $this->completeRow(new ToolResultMessage(
            'call_both',
            'shot with a source doc',
            imageBytes: self::MEDIA_PNG_BYTES,
            documentBytes: self::mediaPdfBytes(),
            documentName: self::DOC_NAME,
        ));

        $this->assertSame(['input_text', 'input_image', 'input_file'], array_column($output, 'type'), 'caption, then image, then document — the Anthropic blocks() media order');
    }

    public function testDocumentOnlyRowShipsNoEmptyCaptionPart(): void
    {
        $output = $this->completeRow(new ToolResultMessage(
            'call_bare',
            '',
            documentBytes: self::mediaPdfBytes(),
            documentName: self::DOC_NAME,
        ));

        $this->assertSame([['type' => 'input_file', 'filename' => self::DOC_NAME, 'file_data' => AttachmentEncoding::dataUrl('application/pdf', self::mediaPdfBytes())]], $output);
    }

    // -------------------------------------------------------------------------
    //  Every refusal lands on the caption string
    // -------------------------------------------------------------------------

    public function testTextOnlyModelKeepsThePlainStringForBothMedia(): void
    {
        foreach (['image' => self::MEDIA_PNG_BYTES, 'document' => null] as $kind => $imageBytes) {
            $row = $kind === 'image'
                ? new ToolResultMessage('c_img', 'blind shot', imageBytes: self::MEDIA_PNG_BYTES)
                : new ToolResultMessage('c_doc', 'blind doc', documentBytes: self::mediaPdfBytes(), documentName: self::DOC_NAME);

            $output = $this->completeRow($row, model: 'o3-mini');

            $this->assertSame($row->content(), $output, $kind . ' on a table-blind model keeps the string spelling');
        }
    }

    public function testVisionOverrideBuysImagesNotDocuments(): void
    {
        // The C-TOOL-2.e asymmetry on this arm's own door: a provider block
        // that asserts vision on a text-only id carries the pixels (the
        // override is exactly what the image leg obeys) and STILL refuses the
        // PDF (the document leg obeys only the vision table).
        $imageRow = new ToolResultMessage('c_img', 'overridden shot', imageBytes: self::MEDIA_PNG_BYTES);
        $imageOutput = $this->completeRow($imageRow, model: 'o3-mini', visionOverride: true);
        $this->assertSame([
            ['type' => 'input_text', 'text' => 'overridden shot'],
            ['type' => 'input_image', 'image_url' => AttachmentEncoding::dataUrl('image/png', self::MEDIA_PNG_BYTES)],
        ], $imageOutput, 'the override rides the image leg, same as C-PRV-2.b');

        $docRow = new ToolResultMessage('c_doc', 'overridden doc', documentBytes: self::mediaPdfBytes(), documentName: self::DOC_NAME);
        $docOutput = $this->completeRow($docRow, model: 'o3-mini', visionOverride: true);
        $this->assertSame($docRow->content(), $docOutput, 'the override does not teach a blind model to read PDFs');
    }

    public function testErrorRowAndEmptyPayloadStayCaptionStrings(): void
    {
        $errorRow = new ToolResultMessage('c_err', 'failed doc', true, documentBytes: self::mediaPdfBytes(), documentName: self::DOC_NAME);
        $this->assertSame($errorRow->content(), $this->completeRow($errorRow), 'is_error rows are text by construction');

        $emptyRow = new ToolResultMessage('c_empty', 'nothing inside', documentBytes: '', documentName: self::DOC_NAME);
        $this->assertSame($emptyRow->content(), $this->completeRow($emptyRow), 'an empty payload is never a zero-byte file part');
    }

    public function testForgedHeaderStaysCaptionText(): void
    {
        $row = new ToolResultMessage('c_forged', 'claimed pdf', documentBytes: 'MZ-not-a-pdf-at-all', documentName: 'forged.pdf');

        $this->assertSame($row->content(), $this->completeRow($row), 'the wire re-sniffs; forged bytes present as caption, never a 400');
    }

    public function testBudgetBoundaryRidesAndPastItStaysCaption(): void
    {
        $atBudget = new ToolResultMessage('c_at', 'at the ceiling', documentBytes: self::mediaPdfBytes(self::DOCUMENT_BUDGET), documentName: self::DOC_NAME);
        $atOutput = $this->completeRow($atBudget);
        $this->assertSame('input_file', $atOutput[1]['type'], 'strlen == budget rides (the refusal is strict >, the Anthropic law)');
        $this->assertSame(self::DOCUMENT_BUDGET, strlen((string) $atBudget->documentBytes()));

        $pastBudget = new ToolResultMessage('c_over', 'one byte past', documentBytes: self::mediaPdfBytes(self::DOCUMENT_BUDGET + 1), documentName: self::DOC_NAME);
        $this->assertSame($pastBudget->content(), $this->completeRow($pastBudget), 'half a PDF is unparseable — the notice only, never truncated bytes');
    }

    // -------------------------------------------------------------------------
    //  Name hygiene and the one-spelling law
    // -------------------------------------------------------------------------

    public function testFilenameHygieneRidesTheSharedEncoder(): void
    {
        $dirty = new ToolResultMessage('c_dirty', 'ctl', documentBytes: self::mediaPdfBytes(), documentName: "bad\x00name\x07\t run.pdf");
        $this->assertSame('bad name run.pdf', $this->completeRow($dirty)[1]['filename'], 'control bytes collapse, dots and spaces survive');

        $nameless = new ToolResultMessage('c_name', 'blank', documentBytes: self::mediaPdfBytes());
        $this->assertSame('document.pdf', $this->completeRow($nameless)[1]['filename'], 'an empty name falls back, never an empty field');
    }

    public function testSharedDataUrlSpellingMatchesTheEncoderPair(): void
    {
        $bytes = self::mediaPdfBytes();

        // One format for pasted bytes no matter which wire emits them: the
        // Responses part and the Anthropic block stand on the same base64,
        // each under its family's own shape wrapper.
        $responses = AttachmentEncoding::responsesInputFile('application/pdf', $bytes, self::DOC_NAME);
        $anthropic = AttachmentEncoding::anthropicDocument('application/pdf', $bytes, self::DOC_NAME);

        $this->assertSame('data:application/pdf;base64,' . base64_encode($bytes), $responses['file_data']);
        $this->assertSame(base64_encode($bytes), $anthropic['source']['data']);
        $this->assertSame($responses['file_data'], AttachmentEncoding::dataUrl('application/pdf', $bytes), 'the part is spelled by the shared helper, not re-inlined');
    }
}

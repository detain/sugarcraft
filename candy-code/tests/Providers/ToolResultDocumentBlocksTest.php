<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\Attachment;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\AttachmentEncoding;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\VertexProvider;

/**
 * C-TOOL-2 (GAP-TL-20): a PDF Read CARRIED (no extractor on the host, file
 * inside the result budget) rides into the model's context as a `document`
 * block inside the Anthropic family's `tool_result` — the exact sibling of
 * the image leg {@see ToolResultImageBlocksTest}, and it earns that parity
 * through the same gate chain: document-capable vs plain wire
 * (`supportsDocuments()`), inside vs over the model budget
 * (`TOOL_RESULT_DOCUMENT_MAX_BYTES`, deliberately its OWN number, far below
 * the user @-mention cap), sniffable vs forged header (the bytes are
 * RE-sniffed at the wire), result vs error (`is_error` rows are text by
 * construction). Every refusal lands on the caption STRING — never on a
 * silently different wire shape, never on truncated bytes.
 *
 * The OpenAI-family chat-role decline (a `tool` message is a string there;
 * a follow-up user turn would forge user authorship — the decision recorded
 * in {@see AnthropicMessagesWire::toolResultDocument()}) is pinned from this
 * side by the plain-wire row: on a wire without document blocks the caption
 * alone is the deliberate answer, and byte-stability below proves that
 * choice never degrades the prompt prefix.
 *
 * Both users of the shared wire are exercised (native Messages endpoint and
 * Vertex `:rawPredict`), and the shared-encoder law is pinned directly: the
 * user @-attachment leg and this tool-result leg produce the IDENTICAL
 * block for the same bytes, media type, and title.
 */
final class ToolResultDocumentBlocksTest extends TestCase
{
    private const DOC_MODEL = 'claude-sonnet-4-6';
    private const PLAIN_MODEL = 'mistral-large-latest';
    private const VERTEX_MODEL = 'claude-3-sonnet@20240229';
    private const CAPTION = 'Binary PDF document, 73 bytes — caption';

    private static function pdf(): string
    {
        return "%PDF-1.4\n" . str_repeat('a', 64);
    }

    /** @param-out array{0: string, 1: array<string, mixed>} $captured */
    private function nativeProvider(string $model, array &$captured): AnthropicProvider
    {
        $captured = ['', []];
        $seam = static function (string $endpoint, array $body) use (&$captured): array {
            $captured = [$endpoint, $body];

            return ['content' => []];
        };

        return new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            $model,
            $seam,
            null,
            [],
            false,
        );
    }

    /** @param-out array{body: array<string, mixed>} $captured */
    private function vertexProvider(array &$captured): VertexProvider
    {
        $captured = ['body' => []];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: self::VERTEX_MODEL,
            predictor: static function (string $endpoint, string $method, array $body) use (&$captured): array {
                $captured = ['body' => $body];

                return ['content' => []];
            },
        );
    }

    private function request(string $model, ToolResultMessage $result): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: [$result]);
    }

    /** @param array{0: string, 1: array<string, mixed>} $captured @return array<string, mixed> */
    private function toolResultBlock(array $captured): array
    {
        /** @var array<int, array<string, mixed>> $messages */
        $messages = $captured[1]['messages'];
        $this->assertSame('user', $messages[0]['role'], 'tool results are user turns on this wire');
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = $messages[0]['content'];
        $this->assertSame('tool_result', $blocks[0]['type']);

        return $blocks[0];
    }

    private function carriedResult(string $content = self::CAPTION, ?string $bytes = null): ToolResultMessage
    {
        return new ToolResultMessage(
            toolCallId: 'tu_1',
            content: $content,
            imageBytes: null,
            imageProtocol: null,
            documentBytes: $bytes ?? self::pdf(),
            documentName: 'plan.pdf',
        );
    }

    // -------------------------------------------------------------------------
    // The positive pole: a document-capable wire gets the block
    // -------------------------------------------------------------------------

    public function testTheDocumentCapableWireCarriesTheCarriedPdfInsideTheToolResult(): void
    {
        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);

        $provider->complete($this->request(self::DOC_MODEL, $this->carriedResult()));

        $block = $this->toolResultBlock($captured);
        $this->assertSame('tu_1', $block['tool_use_id']);
        $this->assertIsArray($block['content'], 'a document-capable wire gets blocks, not a caption string');
        $this->assertCount(2, $block['content']);
        $this->assertSame(['type' => 'text', 'text' => self::CAPTION], $block['content'][0], 'the caption keeps its row beside the document');
        $this->assertSame(AttachmentEncoding::anthropicDocument('application/pdf', self::pdf(), 'plan.pdf'), $block['content'][1]);
    }

    public function testABlankCaptionSendsTheDocumentBlockAlone(): void
    {
        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);

        $provider->complete($this->request(self::DOC_MODEL, $this->carriedResult(content: '')));

        $block = $this->toolResultBlock($captured);
        $this->assertSame([AttachmentEncoding::anthropicDocument('application/pdf', self::pdf(), 'plan.pdf')], $block['content']);
    }

    public function testTheBlockShapeIsThePinnedAnthropicContract(): void
    {
        // Pinned as a literal, not only as a round-trip through the builder:
        // a "fix" to the shared shape cannot quietly move the contract. The
        // title leg is what the user-attachment leg already sends (CTX-2),
        // so both doors present identically.
        $this->assertSame([
            'type' => 'document',
            'title' => 'plan.pdf',
            'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => base64_encode(self::pdf()),
            ],
        ], AttachmentEncoding::anthropicDocument('application/pdf', self::pdf(), 'plan.pdf'));
    }

    public function testTheUserLegAndTheToolResultLegShareOneEncoder(): void
    {
        // The shared-encoder law: the @-mention door (CTX-2) and this
        // tool-result door (C-TOOL-2) call the SAME helper, so the same
        // bytes + media type + title produce the byte-identical block.
        $attached = (new UserMessage('see the spec'))->withAttachment(
            new Attachment('handouts/plan.pdf', AttachmentType::Document, self::pdf(), 'application/pdf'),
        );

        $userBlocks = AttachmentEncoding::anthropicBlocks($attached);

        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);
        $provider->complete($this->request(self::DOC_MODEL, $this->carriedResult(content: '')));

        $this->assertSame($userBlocks[count($userBlocks) - 1], $this->toolResultBlock($captured)['content'][0], 'a PDF entering through either door presents identically');
    }

    public function testTheVertexRawPredictArmLandsTheIdenticalBlock(): void
    {
        $captured = [];
        $provider = $this->vertexProvider($captured);

        $provider->complete($this->request(self::VERTEX_MODEL, new ToolResultMessage(
            toolCallId: 'tu_9',
            content: self::CAPTION,
            documentBytes: self::pdf(),
            documentName: 'plan.pdf',
        )));

        /** @var array<int, array<string, mixed>> $messages */
        $messages = $captured['body']['messages'];
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = $messages[0]['content'];
        $this->assertSame('tool_result', $blocks[0]['type']);
        $this->assertSame(AttachmentEncoding::anthropicDocument('application/pdf', self::pdf(), 'plan.pdf'), $blocks[0]['content'][1], 'one wire, both hosts — the block must not know which door it came in by');
    }

    // -------------------------------------------------------------------------
    // The refusal poles: caption string, never a broken or faked wire
    // -------------------------------------------------------------------------

    public function testAPlainModelKeepsThePlainCaptionString(): void
    {
        // supportsDocuments() is false for a non-Anthropic model on this
        // host — the bytes are simply not offered, and the wire keeps the
        // exact pre-C-TOOL-2 shape: the caption alone is the answer.
        $captured = [];
        $provider = $this->nativeProvider(self::PLAIN_MODEL, $captured);

        $provider->complete($this->request(self::PLAIN_MODEL, $this->carriedResult()));

        $this->assertSame(self::CAPTION, $this->toolResultBlock($captured)['content']);
    }

    public function testErrorResultsNeverGainADocumentLegEvenOnADocumentCapableWire(): void
    {
        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);

        $provider->complete($this->request(self::DOC_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: 'PDF failed to load.',
            isError: true,
            documentBytes: self::pdf(),
            documentName: 'plan.pdf',
        )));

        $block = $this->toolResultBlock($captured);
        $this->assertTrue($block['is_error']);
        $this->assertSame('PDF failed to load.', $block['content'], 'an error row is text by construction');
    }

    public function testBytesPastTheModelBudgetStayCaptionText(): void
    {
        // Inside the tool's carry decision (so Read produced the field),
        // past the tool-result budget: the wire answers text. The budget
        // refuses OVER it, exactly at it rides.
        $cap = AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES;
        $over = "%PDF-1.4\n" . str_repeat('a', $cap);
        $at = "%PDF-1.4\n" . str_repeat('a', $cap - 9);
        $this->assertSame($cap, strlen($at), 'the at-cap fixture must land exactly on the budget to isolate this gate');

        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);
        $provider->complete($this->request(self::DOC_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            documentBytes: $over,
            documentName: 'plan.pdf',
        )));
        $this->assertSame(self::CAPTION, $this->toolResultBlock($captured)['content']);

        $provider->complete($this->request(self::DOC_MODEL, new ToolResultMessage(
            toolCallId: 'tu_2',
            content: self::CAPTION,
            documentBytes: $at,
            documentName: 'plan.pdf',
        )));
        $this->assertIsArray($this->toolResultBlock($captured)['content'], 'the budget refuses past it, not at it');
    }

    public function testBytesThatNoLongerSniffAsADocumentStayCaptionText(): void
    {
        // The wire re-sniffs instead of trusting the carrier: forged or
        // rotted bytes answer the sniffer with null and stay text, rather
        // than becoming a document block the API 400s on.
        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);

        $provider->complete($this->request(self::DOC_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            documentBytes: 'not really a pdf at all',
            documentName: 'plan.pdf',
        )));

        $this->assertSame(self::CAPTION, $this->toolResultBlock($captured)['content']);
    }

    public function testEmptyDocumentBytesStayCaptionText(): void
    {
        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);

        $provider->complete($this->request(self::DOC_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            documentBytes: '',
            documentName: 'plan.pdf',
        )));

        $this->assertSame(self::CAPTION, $this->toolResultBlock($captured)['content']);
    }

    // -------------------------------------------------------------------------
    // The contract's provenance and its stability
    // -------------------------------------------------------------------------

    public function testTheToolResultDocumentBudgetIsItsOwnNumberFarBelowTheUserCap(): void
    {
        // NOT the 256KiB text-page re-arm the image budget is: a PDF repeats
        // base64-inflated on every call that follows it in the turn, so the
        // model leg sits an order of magnitude under the one-shot user
        // @-mention cap (which carries its own session budget). Pinned so a
        // change to either number must be a decision about BOTH.
        $this->assertSame(1024 * 1024, AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES);
        $this->assertNotSame(FileMentions::TEXT_MAX_BYTES, AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES);
        $this->assertLessThanOrEqual(intdiv(FileMentions::DOCUMENT_MAX_BYTES, 10), AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES, 'the tool-result budget must stay at least an order of magnitude below the user document cap');
    }

    public function testTheDocumentBearingRequestIsByteStableAcrossTurns(): void
    {
        // Prompt-cache discipline: base64 is deterministic and the block
        // order is fixed (caption, then document), so the same history
        // renders the same bytes on every send — the prefix cache keeps its
        // hit.
        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);
        $result = $this->carriedResult();

        $provider->complete($this->request(self::DOC_MODEL, $result));
        $first = $captured[1];

        $provider->complete($this->request(self::DOC_MODEL, $result));
        $this->assertSame($first, $captured[1]);
    }

    public function testAToolResultCarryingBothAnImageAndADocumentOrdersTextImageDocument(): void
    {
        // One read answers one door in practice (an image sniffs as image, a
        // PDF as document), but the codec lets both legs be set, so the
        // block order is pinned: media list is [image, document] after the
        // text row — stable spelling, stable prefix.
        $captured = [];
        $provider = $this->nativeProvider(self::DOC_MODEL, $captured);

        $provider->complete($this->request(self::DOC_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            imageBytes: "\x89PNG\r\n\x1a\n" . str_repeat('b', 16),
            imageProtocol: 'sixel',
            documentBytes: self::pdf(),
            documentName: 'plan.pdf',
        )));

        $block = $this->toolResultBlock($captured);
        $this->assertCount(3, $block['content']);
        $this->assertSame('text', $block['content'][0]['type']);
        $this->assertSame('image', $block['content'][1]['type']);
        $this->assertSame('document', $block['content'][2]['type']);
    }
}

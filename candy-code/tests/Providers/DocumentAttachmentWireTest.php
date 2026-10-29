<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachment;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\AttachmentType;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AttachmentEncoding;
use SugarCraft\Code\Providers\BedrockProvider;
use SugarCraft\Code\Providers\GeminiProvider;

/**
 * C-TOOL-2.e: a user PDF reaches Bedrock Converse, Gemini generateContent,
 * and the OpenAI chat wire as a native block - and every other door keeps
 * the named decline. Each native leg fails closed: bytes that are not a
 * well-formed PDF inside the inbound capture ceiling name themselves on the
 * text block instead of riding the wire or vanishing from it.
 */
final class DocumentAttachmentWireTest extends TestCase
{
    private const PDF = '%PDF-1.7' . "\n" . "\x00\x00\x00" . 'body' . "\n%%EOF\n";

    private static function pdf(string $path = 'handouts/spec.pdf', ?string $bytes = null, ?string $mime = 'application/pdf'): UserMessage
    {
        return (new UserMessage('read this?'))
            ->withAttachment(new Attachment($path, AttachmentType::Document, $bytes ?? self::PDF, $mime));
    }

    public function testBedrockCarriesAWellFormedPdfAsARawDocumentBlock(): void
    {
        $this->assertSame(
            [
                ['text' => 'read this?'],
                ['document' => ['format' => 'pdf', 'name' => 'spec_pdf', 'source' => ['bytes' => self::PDF]]],
            ],
            AttachmentEncoding::bedrockBlocks(self::pdf()),
            'C-TOOL-2.e: the Converse blob member takes RAW bytes, like the image leg',
        );
    }

    /**
     * @param array{0: string, 1: string} $pair
     */
    #[DataProvider('bedrockNameShapes')]
    public function testBedrockShavesTheDocumentNameIntoTheConverseCharset(string $given, string $expected): void
    {
        $blocks = AttachmentEncoding::bedrockBlocks(self::pdf($given));

        $this->assertSame($expected, $blocks[1]['document']['name']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bedrockNameShapes(): array
    {
        return [
            'dots become underscores' => ['spec.final.pdf', 'spec_final_pdf'],
            'leading punctuation is trimmed' => ['.hidden.pdf', 'hidden_pdf'],
            'spaces collapse to underscores' => ['quarter report.pdf', 'quarter_report_pdf'],
            'empty after shaving' => ['!!!', 'document'],
            'capped at sixty-four' => [str_repeat('a', 80) . '-tail', str_repeat('a', 64)],
        ];
    }

    public function testBedrockNamesAForgedOrOversizedPdfAndBuildsNoBlock(): void
    {
        $forged = AttachmentEncoding::bedrockBlocks(self::pdf('notes.pdf', 'not a pdf at all', 'application/pdf'));
        $oversized = AttachmentEncoding::bedrockBlocks(
            self::pdf('big.pdf', str_pad('%PDF-1.7', FileMentions::DOCUMENT_MAX_BYTES + 1, "\x00")),
        );

        foreach (['forged' => $forged, 'oversized' => $oversized] as $case => $blocks) {
            $this->assertCount(1, $blocks, $case . ': no document block is built from bytes that are not a carried PDF');
            $this->assertStringContainsString('[Document attachment ', $blocks[0]['text']);
            $this->assertStringContainsString('was not sent: the wire carries only well-formed PDFs up to ', $blocks[0]['text']);
        }

        $this->assertStringContainsString('notes.pdf', $forged[0]['text']);
        $this->assertStringContainsString('big.pdf', $oversized[0]['text']);
        $this->assertStringNotContainsString('%PDF-1.7', $forged[0]['text'], 'the naming line never inlines bytes');
    }

    public function testBedrockClaimsDocumentsForTheVisionFamiliesOnly(): void
    {
        $this->assertTrue(
            BedrockProvider::create('us-east-1', 'anthropic.claude-sonnet-4-5')->supportsDocuments(),
            'C-TOOL-2.e: the same Claude families that read images read document blocks',
        );
        $this->assertFalse(
            BedrockProvider::create('us-east-1', 'meta.llama3-70b-instruct')->supportsDocuments(),
            'a text-only family would answer the block with a ValidationException',
        );
    }

    public function testGeminiCarriesAWellFormedPdfAsApplicationPdfInlineData(): void
    {
        $parts = AttachmentEncoding::geminiParts(self::pdf());

        $this->assertSame([
            ['text' => 'read this?'],
            ['inlineData' => ['mimeType' => 'application/pdf', 'data' => base64_encode(self::PDF)]],
        ], $parts, 'C-TOOL-2.e: the PDF rides the same inlineData family as the image leg');
    }

    public function testGeminiNamesAForgedOrOversizedPdfAndBuildsNoPart(): void
    {
        $forged = AttachmentEncoding::geminiParts(self::pdf('notes.pdf', 'not a pdf at all'));
        $oversized = AttachmentEncoding::geminiParts(
            self::pdf('big.pdf', str_pad('%PDF-1.7', FileMentions::DOCUMENT_MAX_BYTES + 1, chr(0))),
        );

        foreach (['forged' => $forged, 'oversized' => $oversized] as $case => $parts) {
            $this->assertCount(1, $parts, $case . ': one text part, no inlineData document');
            $this->assertStringContainsString('[Document attachment ', $parts[0]['text']);
            $this->assertStringContainsString('was not sent: the wire carries only well-formed PDFs up to ', $parts[0]['text']);
        }

        $this->assertStringContainsString('notes.pdf', $forged[0]['text']);
        $this->assertStringContainsString('big.pdf', $oversized[0]['text']);
    }

    public function testOpenAiChatOptInCarriesThePdfAsAFilePart(): void
    {
        $content = AttachmentEncoding::openAiContent(self::pdf(), documentsAsBlocks: true);

        $this->assertSame([
            ['type' => 'text', 'text' => 'read this?'],
            ['type' => 'file', 'file' => [
                'filename' => 'spec.pdf',
                'file_data' => 'data:application/pdf;base64,' . base64_encode(self::PDF),
            ]],
        ], $content, 'C-TOOL-2.e: the chat arm pastes the PDF as a data: URL file part');
    }

    public function testOpenAiFilePartNameCollapsesControlBytesAndKeepsDots(): void
    {
        $content = AttachmentEncoding::openAiContent(self::pdf('handouts/sp' . chr(1) . 'ec.pdf'), documentsAsBlocks: true);

        $this->assertSame('sp ec.pdf', $content[1]['file']['filename']);

        $blank = AttachmentEncoding::openAiContent(self::pdf('handouts/' . chr(1) . chr(2)), documentsAsBlocks: true);
        $this->assertSame('document.pdf', $blank[1]['file']['filename'], 'a name that collapses to nothing still gives the part a legal filename');
    }

    public function testOpenAiOptInWithoutCarriedDocumentsKeepsTheOldShapes(): void
    {
        $plain = AttachmentEncoding::openAiContent(new UserMessage('no attachments at all'), documentsAsBlocks: true);
        $this->assertSame('no attachments at all', $plain, 'the string path is byte-unchanged for an opt-in caller without documents');

        $forged = AttachmentEncoding::openAiContent(self::pdf('notes.pdf', 'not a pdf at all'), documentsAsBlocks: true);
        $this->assertIsString($forged, 'a refused document alone never forces the parts shape');
        $this->assertStringContainsString('[Document attachment notes.pdf was not sent', $forged);

        $declined = AttachmentEncoding::openAiContent(self::pdf());
        $this->assertIsString($declined);
        $this->assertStringContainsString('this wire does not accept PDF document blocks', $declined, 'the Responses/SGLang/Custom callers keep the CTX-2 line');
    }

    public function testGeminiClaimsDocumentsForEveryGenerativeModel(): void
    {
        $this->assertTrue(
            (new GeminiProvider(apiKey: 'k'))->supportsDocuments(),
            'C-TOOL-2.e: the arm that already reads inlineData images reads application/pdf parts',
        );
    }

    /**
     * C-TOOL-2.e round-1 doc-truth pair: docs/ADDING_A_PROVIDER.md attributes
     * the byte fail-closed check to exactly the legs that build it. The
     * Anthropic Messages leg is a pass-through — its PDF guarantee lives in
     * the capture-time magic-number sniff, not the encoder — so the sentence
     * claiming every leg re-reads the bytes would be a doc lie, and this
     * test reddens together with it, from both directions.
     */
    public function testTheProviderDocScopesTheByteFailClosedClaimToTheLegsThatReSniff(): void
    {
        $doc = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ADDING_A_PROVIDER.md');

        $this->assertStringContainsString('The three C-TOOL-2.e legs fail', $doc);
        $this->assertStringContainsString('magic-number sniff', $doc);
        $this->assertStringNotContainsString(
            'named in the text on every leg',
            $doc,
            'round-1 cure: only the three native legs re-read the bytes, never "every leg"',
        );

        $forged = (new UserMessage('x'))
            ->withAttachment(new Attachment('forge.pdf', AttachmentType::Document, 'not a pdf at all', 'application/pdf'));

        // The pass-through side: on the CTX-2 leg a declared document becomes
        // a block whatever its bytes say - which is exactly what the scoped
        // sentence says the OTHER legs refuse to do.
        $blocks = AttachmentEncoding::anthropicBlocks($forged);
        $this->assertSame('document', $blocks[1]['type']);

        // The three native sides: forged bytes name themselves, no block.
        $this->assertCount(1, AttachmentEncoding::bedrockBlocks($forged));
        $this->assertCount(1, AttachmentEncoding::geminiParts($forged));
        $this->assertIsString(AttachmentEncoding::openAiContent($forged, documentsAsBlocks: true));
    }
}

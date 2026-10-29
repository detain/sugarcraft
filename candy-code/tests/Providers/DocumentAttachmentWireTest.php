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
}

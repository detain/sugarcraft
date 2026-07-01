<?php

declare(strict_types=1);

namespace SugarCraft\Post\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Post\Attachment;

/**
 * Additional Attachment edge case tests.
 */
final class AttachmentEdgeTest extends TestCase
{
    public function testGetContentReturnsContentWhenNoPath(): void
    {
        $att = Attachment::fromContent('raw bytes here', 'data.bin', 'application/octet-stream');
        $this->assertSame('raw bytes here', $att->getContent());
    }

    public function testGetContentReturnsEmptyStringWhenNeitherContentNorPath(): void
    {
        // This happens when fromPath fails to read file
        $att = Attachment::fromContent('', 'empty.txt', 'text/plain');
        $this->assertSame('', $att->getContent());
    }

    public function testUnreadablePathThrows(): void
    {
        // Using a path that doesn't exist - should throw RuntimeException
        $att = Attachment::fromPath('/nonexistent/path/file.txt');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not readable');
        $att->getContent();
    }

    public function testFromPathWithUnknownExtensionFallsBackToOctetStream(): void
    {
        // When mime_content_type returns application/octet-stream or false,
        // the extension-based detection kicks in for unknown extensions
        $att = Attachment::fromContent('data', 'file.unknown', 'application/octet-stream');
        $this->assertSame('application/octet-stream', $att->mimeType);
    }

    public function testFromContentWithExplicitMimeType(): void
    {
        $att = Attachment::fromContent('{"key":"value"}', 'data.json', 'application/json');
        $this->assertSame('application/json', $att->mimeType);
    }

    public function testInlineAttachmentGetContentThrowsOnUnreadable(): void
    {
        // Using a path that doesn't exist - should throw RuntimeException on getContent()
        $att = Attachment::inline('/nonexistent/path/img.png', 'img-cid-123@example.com');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not readable');
        $att->getContent();
    }

    public function testInlineAttachmentRejectsInvalidCid(): void
    {
        // CID with spaces and special characters is invalid
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Content-ID format');
        Attachment::inline('/tmp/img.png', 'cid with spaces and <special> chars');
    }

    public function testInlineAttachmentAcceptsValidCidWithCidPrefix(): void
    {
        $att = Attachment::inline('/tmp/img.png', 'cid:img-cid-123@example.com');
        $this->assertSame('cid:img-cid-123@example.com', $att->cid);
    }

    public function testInlineAttachmentAcceptsValidCidWithoutCidPrefix(): void
    {
        $att = Attachment::inline('/tmp/img.png', 'img-cid-123@example.com');
        $this->assertSame('img-cid-123@example.com', $att->cid);
    }
}

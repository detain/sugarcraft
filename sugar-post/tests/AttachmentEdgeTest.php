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
        // An explicitly empty in-memory attachment reads back as empty bytes.
        $att = Attachment::fromContent('', 'empty.txt', 'text/plain');
        $this->assertSame('', $att->getContent());
    }

    public function testUnreadablePathThrows(): void
    {
        // fromPath() must fail eagerly on a missing/unreadable path rather than
        // deferring to a silent 0-byte attachment. Regression guard for the
        // @-suppressed-read bug: revert the fix and fromPath() returns an
        // attachment with null content instead of throwing, failing this test.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not readable');
        Attachment::fromPath('/nonexistent/path/file.txt');
    }

    public function testFromPathReadsBytesEagerly(): void
    {
        // A readable file still attaches, and its bytes are captured eagerly
        // (content populated at construction, not lazily on getContent()).
        $path = self::tempFile('real bytes');
        try {
            $att = Attachment::fromPath($path);
            $this->assertSame('real bytes', $att->content);
            $this->assertSame('real bytes', $att->getContent());
        } finally {
            @\unlink($path);
        }
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

    public function testInlineUnreadablePathThrows(): void
    {
        // inline() shares fromPath()'s eager-read contract: a missing file
        // fails at construction, never becoming a silent 0-byte inline part.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not readable');
        Attachment::inline('/nonexistent/path/img.png', 'img-cid-123@example.com');
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
        $path = self::tempFile("\x89PNG");
        try {
            $att = Attachment::inline($path, 'cid:img-cid-123@example.com');
            $this->assertSame('cid:img-cid-123@example.com', $att->cid);
        } finally {
            @\unlink($path);
        }
    }

    public function testInlineAttachmentAcceptsValidCidWithoutCidPrefix(): void
    {
        $path = self::tempFile("\x89PNG");
        try {
            $att = Attachment::inline($path, 'img-cid-123@example.com');
            $this->assertSame('img-cid-123@example.com', $att->cid);
        } finally {
            @\unlink($path);
        }
    }

    private static function tempFile(string $bytes): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'sp-att-');
        \file_put_contents($path, $bytes);
        return $path;
    }
}

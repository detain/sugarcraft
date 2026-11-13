<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mail\Attachment;

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

    public function testCharacterDevicesAreRejectedWithoutReading(): void
    {
        // M-F: /dev/zero exhausted memory past the exception path (a fatal,
        // not a throw); FIFOs hung forever. The regular-file gate closes all
        // non-regular shapes BEFORE any read is attempted.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a regular file');
        Attachment::fromPath('/dev/null');
    }

    /**
     * CM-50: wrapper URLs are refused BEFORE the first stat/mime touch. The
     * pre-fix bytes reached the reader chain and threw attachment.unreadable
     * (a RuntimeException about a missing file); the refusal now names the
     * wrapper and its class - InvalidArgumentException, caller-input taxonomy.
     */
    /**
     * CM-69: the filename ceiling shares the ctor choke with the size gates,
     * so even fromContent (no filesystem involved) refuses a megabyte name;
     * 255 octets is a legal byte budget and the byte (not code point) reading
     * is pinned with a 128-character two-byte string.
     */
    public function testFilenamesAreCappedAt255Octets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Attachment::fromContent('x', \str_repeat('n', 4096));
    }

    public function testFilenameCapIsCountedInOctetsNotCodePoints(): void
    {
        $boundary = Attachment::fromContent('x', \str_repeat('n', 255));
        $this->assertSame('x', $boundary->content());

        $multibyte = \str_repeat("\u{00e9}", 128); // 256 octets, 128 code points
        $thrown = null;
        try {
            Attachment::fromContent('x', $multibyte);
        } catch (\InvalidArgumentException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, '256 octets in two-byte code points still breaches the byte ceiling');
        $this->assertStringContainsString('255', $thrown->getMessage());
    }

    public function testStreamWrapperPathsAreRefusedNotStatTouched(): void
    {
        foreach (['phar:///tmp/evil.phar/x.txt', 'data://text/plain;base64,AAAA', 'glob://nope'] as $wrapper) {
            $thrown = null;
            try {
                Attachment::fromPath($wrapper);
            } catch (\InvalidArgumentException $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, "wrapper path {$wrapper} must be refused outright");
            $this->assertStringContainsString('stream wrapper', $thrown->getMessage());
        }

        $file = (string) \tempnam(\sys_get_temp_dir(), 'cm50');
        \file_put_contents($file, 'content');
        try {
            // The explicit file:// form is the same intent stated verbosely:
            // it loads, and the stored path is normalised to native.
            $attachment = Attachment::fromPath('file://' . $file);
            $this->assertSame('content', $attachment->content());
            $this->assertSame($file, $attachment->__debugInfo()['path'], 'the file:// prefix is stripped on store');
            // Inline shares the gate (the CM-08 cid check stands after it).
            $this->expectException(\InvalidArgumentException::class);
            Attachment::inline('phar:///tmp/evil.phar/secret', 'legit@x');
        } finally {
            \unlink($file);
        }
    }

    public function testOversizedFileIsRefusedBeforeTheRead(): void
    {
        $path = \tempnam(\sys_get_temp_dir(), 'att-cap-');
        $this->assertIsString($path);
        $handle = \fopen($path, 'wb');
        $this->assertNotFalse($handle);
        \ftruncate($handle, Attachment::MAX_BYTES + 1);
        \fclose($handle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('exceeds');
            Attachment::fromPath($path);
        } finally {
            @\unlink($path);
        }
    }

    public function testFromContentObeysTheSameCeiling(): void
    {
        // The constructor choke covers the memory path, not just disk reads
        // (CM-45 taxonomy: oversized caller input is InvalidArgumentException).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds');
        Attachment::fromContent(\str_repeat('A', Attachment::MAX_BYTES + 1), 'huge.bin');
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
        // The old arm fed fromContent its own expected type — a tautology.
        // Exercise the real detector: an unmapped extension must land
        // octet-stream through both roads of the sniff-then-map precedence
        // law, and a mapped one must resolve to its recorded type.
        $unknown = (string) \tempnam(sys_get_temp_dir(), 'candy-mail-unknown-');
        \rename($unknown, $unknown . '.qqz-unknown-ext');
        $unknown .= '.qqz-unknown-ext';
        \file_put_contents($unknown, 'x' . \random_bytes(64));

        $known = (string) \tempnam(sys_get_temp_dir(), 'candy-mail-known-');
        \rename($known, $known . '.txt');
        $known .= '.txt';
        \file_put_contents($known, 'hello');

        $detect = new \ReflectionMethod(Attachment::class, 'detectMimeType');
        $detect->setAccessible(true);
        try {
            $this->assertSame('application/octet-stream', $detect->invoke(null, $unknown));
            $this->assertMatchesRegularExpression('/^text\/plain(;|$)/', $detect->invoke(null, $known));
        } finally {
            @\unlink($unknown);
            @\unlink($known);
        }
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

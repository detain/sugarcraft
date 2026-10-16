<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests;

use SugarCraft\Mail\Attachment;
use PHPUnit\Framework\TestCase;

final class AttachmentTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-mail-test-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $files = \glob("{$this->tmpDir}/*") ?: [];
        foreach ($files as $f) { \unlink($f); }
        \rmdir($this->tmpDir);
    }

    public function testFromContent(): void
    {
        $att = Attachment::fromContent('hello world', 'hello.txt', 'text/plain');

        $this->assertSame('hello.txt', $att->filename);
        $this->assertSame('text/plain', $att->mimeType);
        $this->assertSame('base64', $att->encoding);
        $this->assertSame('hello world', $att->getContent());
        $this->assertNull($att->cid);
    }

    public function testFromPath(): void
    {
        $path = $this->tmpDir . '/test.txt';
        \file_put_contents($path, 'file content');

        $att = Attachment::fromPath($path);

        $this->assertSame('test.txt', $att->filename);
        $this->assertSame('file content', $att->getContent());
        $this->assertNull($att->cid);
    }

    public function testFromPathWithCustomFilename(): void
    {
        $path = $this->tmpDir . '/test.txt';
        \file_put_contents($path, 'content');

        $att = Attachment::fromPath($path, 'renamed.txt');

        $this->assertSame('renamed.txt', $att->filename);
    }

    public function testMimeTypeDetection(): void
    {
        $path = $this->tmpDir . '/image.png';
        \file_put_contents($path, "\x89PNG\r\n\x1a\n");

        $att = Attachment::fromPath($path);
        $this->assertSame('image/png', $att->mimeType);
    }

    public function testInlineAttachment(): void
    {
        $path = $this->tmpDir . '/img.png';
        \file_put_contents($path, "\x89PNG");

        $att = Attachment::inline($path, 'img-cid-001', 'logo.png');

        $this->assertSame('img-cid-001', $att->cid);
        $this->assertSame('logo.png', $att->filename);
    }

    public function testWithCid(): void
    {
        $att = Attachment::fromContent('data', 'doc.pdf', 'application/pdf')
            ->withCid('my-cid');

        $this->assertSame('my-cid', $att->cid);
    }

    public function testInlineAttachmentOverridesCidOnFromContent(): void
    {
        $att = Attachment::fromContent('img', 'pic.png', 'image/png');
        $this->assertNull($att->cid);
    }
}

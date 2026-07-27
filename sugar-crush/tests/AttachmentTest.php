<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;

final class AttachmentTest extends TestCase
{
    public function testConstructionWithFileType(): void
    {
        $attachment = new Attachment('/path/to/file.txt', AttachmentType::File);

        $this->assertSame('/path/to/file.txt', $attachment->path);
        $this->assertSame(AttachmentType::File, $attachment->type);
    }

    public function testConstructionWithImageType(): void
    {
        $attachment = new Attachment('/path/to/image.png', AttachmentType::Image);

        $this->assertSame('/path/to/image.png', $attachment->path);
        $this->assertSame(AttachmentType::Image, $attachment->type);
    }

    public function testPathCanBeEmpty(): void
    {
        $attachment = new Attachment('', AttachmentType::File);

        $this->assertSame('', $attachment->path);
        $this->assertSame(AttachmentType::File, $attachment->type);
    }

    public function testPathCanContainSpecialCharacters(): void
    {
        $attachment = new Attachment('/path/with spaces/and!@#$/file.txt', AttachmentType::File);

        $this->assertSame('/path/with spaces/and!@#$/file.txt', $attachment->path);
    }

    public function testPropertiesAreReadonly(): void
    {
        $attachment = new Attachment('/test.txt', AttachmentType::File);

        $reflection = new \ReflectionClass($attachment);
        $pathProperty = $reflection->getProperty('path');
        $typeProperty = $reflection->getProperty('type');

        $this->assertTrue($pathProperty->isReadOnly());
        $this->assertTrue($typeProperty->isReadOnly());
    }

    public function testReadonlyClass(): void
    {
        $reflection = new \ReflectionClass(Attachment::class);
        $this->assertTrue($reflection->isReadOnly());
    }
}

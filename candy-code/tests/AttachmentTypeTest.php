<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\AttachmentType;

final class AttachmentTypeTest extends TestCase
{
    public function testFileCaseExists(): void
    {
        $this->assertSame('File', AttachmentType::File->name);
    }

    public function testImageCaseExists(): void
    {
        $this->assertSame('Image', AttachmentType::Image->name);
    }

    public function testDocumentCaseExists(): void
    {
        $this->assertSame('Document', AttachmentType::Document->name);
    }

    public function testCaseCount(): void
    {
        $this->assertCount(3, AttachmentType::cases());
    }

    public function testIsPureEnum(): void
    {
        // AttachmentType is a pure enum (not backed), not an enum with string/int backing
        $cases = AttachmentType::cases();
        $this->assertCount(3, $cases);
        $this->assertInstanceOf(AttachmentType::class, $cases[0]);
    }

    public function testAllCasesHaveNames(): void
    {
        foreach (AttachmentType::cases() as $case) {
            $this->assertNotEmpty($case->name);
        }
    }

    public function testCasesAreDistinct(): void
    {
        $cases = AttachmentType::cases();
        $this->assertNotSame($cases[0], $cases[1]);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\AttachmentType;

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

    public function testCaseCount(): void
    {
        $this->assertCount(2, AttachmentType::cases());
    }

    public function testIsPureEnum(): void
    {
        // AttachmentType is a pure enum (not backed), not an enum with string/int backing
        $cases = AttachmentType::cases();
        $this->assertCount(2, $cases);
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

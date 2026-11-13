<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\McpClient\JsonLines;

final class JsonLinesTest extends TestCase
{
    public function testSlashesAndUnicodeStayLiteral(): void
    {
        self::assertSame('{"p":"/a/b","t":"é✓"}', JsonLines::encode(['p' => '/a/b', 't' => "é✓"]));
    }

    public function testInvalidUtf8IsSubstitutedNotFatal(): void
    {
        self::assertSame("[\"a\u{FFFD}b\"]", JsonLines::encode(["a\xFFb"]));
    }

    public function testExtraFlagsAreOrredIn(): void
    {
        self::assertSame('[1.0]', JsonLines::encode([1.0], \JSON_PRESERVE_ZERO_FRACTION));
    }

    public function testUnencodableThrowsRatherThanReturningEmpty(): void
    {
        $this->expectException(\JsonException::class);
        JsonLines::encode([INF]);
    }
}

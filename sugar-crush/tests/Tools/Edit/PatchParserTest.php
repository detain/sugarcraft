<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\Edit\PatchAction;
use SugarCraft\Crush\Tools\Edit\PatchParser;

/**
 * Roadmap 3.I-3: the `*** Begin Patch` grammar `ApplyPatch` reads — what it
 * accepts, and the malformed patches it refuses with a line number rather
 * than guessing at.
 */
final class PatchParserTest extends TestCase
{
    public function testEverySectionKindParses(): void
    {
        $ops = PatchParser::new()->parse(<<<'PATCH'
            *** Begin Patch
            *** Add File: src/New.php
            +<?php
            +echo 1;
            *** Update File: src/Cart.php
            *** Move to: src/Basket.php
            @@ class Cart
            @@ function total
                 $sum = 0;
            -    return $sum;
            +    return round($sum, 2);
            *** Delete File: src/Old.php
            *** End Patch
            PATCH);

        self::assertCount(3, $ops);
        self::assertSame(PatchAction::Add, $ops[0]->action);
        self::assertSame("<?php\necho 1;\n", $ops[0]->content);

        self::assertSame(PatchAction::Update, $ops[1]->action);
        self::assertSame('src/Basket.php', $ops[1]->moveTo);
        self::assertCount(1, $ops[1]->hunks);
        $hunk = $ops[1]->hunks[0];
        self::assertSame(['class Cart', 'function total'], $hunk->anchors);
        self::assertSame("    \$sum = 0;\n    return \$sum;\n", $hunk->oldText());
        self::assertSame("    \$sum = 0;\n    return round(\$sum, 2);\n", $hunk->newText());

        self::assertSame(PatchAction::Delete, $ops[2]->action);
        self::assertSame(['src/New.php', 'src/Cart.php', 'src/Basket.php', 'src/Old.php'], PatchParser::paths(<<<'PATCH'
            *** Begin Patch
            *** Add File: src/New.php
            +x
            *** Update File: src/Cart.php
            *** Move to: src/Basket.php
            *** Delete File: src/Old.php
            *** End Patch
            PATCH));
    }

    public function testHunksSplitOnEachAtAtLineAndTheFirstMayOmitIt(): void
    {
        $ops = PatchParser::new()->parse("*** Begin Patch\n*** Update File: a\n a\n-b\n+B\n@@\n c\n-d\n+D\n*** End of File\n*** End Patch");

        $hunks = $ops[0]->hunks;
        self::assertCount(2, $hunks);
        self::assertSame([], $hunks[0]->anchors);
        self::assertFalse($hunks[0]->endOfFile);
        self::assertTrue($hunks[1]->endOfFile);
        self::assertSame("c\nD\n", $hunks[1]->newText());
    }

    public function testAUnifiedDiffHeaderIsAcceptedWithItsNumbersIgnored(): void
    {
        $ops = PatchParser::new()->parse("*** Begin Patch\n*** Update File: a\n@@ -12,3 +12,3 @@ function f()\n-x\n+y\n*** End Patch");

        self::assertSame(['function f()'], $ops[0]->hunks[0]->anchors);
    }

    public function testAnEmptyLineInAHunkIsAnEmptyContextLineButTrailingBlanksAreDropped(): void
    {
        $ops = PatchParser::new()->parse("*** Begin Patch\n*** Update File: a\n a\n\n-b\n+c\n\n\n*** Delete File: z\n*** End Patch");

        self::assertSame("a\n\nb\n", $ops[0]->hunks[0]->oldText());
        self::assertSame(PatchAction::Delete, $ops[1]->action);
    }

    public function testCrlfAndAHeredocWrapperAreTolerated(): void
    {
        $ops = PatchParser::new()->parse("apply_patch <<'EOF'\r\n*** Begin Patch\r\n*** Add File: a\r\n+x\r\n*** End Patch\r\nEOF\r\n");

        self::assertSame("x\n", $ops[0]->content);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformed(): iterable
    {
        yield 'no begin' => ["*** Add File: a\n+x\n*** End Patch", 'must start with'];
        yield 'no end' => ["*** Begin Patch\n*** Add File: a\n+x", 'must end with'];
        yield 'empty' => ["*** Begin Patch\n*** End Patch", 'no file sections'];
        yield 'unknown header' => ["*** Begin Patch\n*** Rename File: a\n*** End Patch", 'patch line 2'];
        yield 'add line without plus' => ["*** Begin Patch\n*** Add File: a\nx\n*** End Patch", 'starts with "+"'];
        yield 'hunk line without prefix' => ["*** Begin Patch\n*** Update File: a\n-x\n*y\n*** End Patch", 'patch line 4'];
        yield 'context-only hunk' => ["*** Begin Patch\n*** Update File: a\n@@\n x\n*** End Patch", 'changes nothing'];
        yield 'update without hunks' => ["*** Begin Patch\n*** Update File: a\n*** End Patch", 'has no hunks'];
        yield 'path twice' => ["*** Begin Patch\n*** Delete File: a\n*** Delete File: ./a\n*** End Patch", 'two sections'];
        yield 'empty path' => ["*** Begin Patch\n*** Delete File: \n*** End Patch", 'is empty'];
    }

    /**
     * @dataProvider malformed
     */
    public function testMalformedPatchesAreRefused(string $patch, string $expected): void
    {
        try {
            PatchParser::new()->parse($patch);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }

        self::assertNull(PatchParser::paths($patch), 'a caller judging paths fails closed on the same patch');
    }

    public function testPathsOfANonStringIsNull(): void
    {
        self::assertNull(PatchParser::paths(null));
        self::assertNull(PatchParser::paths(['*** Begin Patch']));
    }
}

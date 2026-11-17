<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;

/**
 * Chat::withSize() is re-asserted on every frame by ChatPane::renderView(), so
 * an unchanged geometry must hand back the SAME instance. A rebuilt Chat per
 * frame allocates a fresh model and defeats any identity-keyed memo.
 *
 * @see Chat::withSize()
 */
final class WithSizeIdentityTest extends TestCase
{
    public function testUnchangedSizeReturnsTheSameInstance(): void
    {
        $sized = (new Chat())->withSize(100, 30);

        self::assertSame($sized, $sized->withSize(100, 30));
    }

    public function testRepeatedFrameSizingIsIdempotent(): void
    {
        $sized = (new Chat())->withSize(100, 30);
        $again = $sized;
        for ($frame = 0; $frame < 5; $frame++) {
            $again = $again->withSize(100, 30);
        }

        self::assertSame($sized, $again);
    }

    public function testChangedColsRebuildsWithTheNewWidth(): void
    {
        $sized = (new Chat())->withSize(100, 30);
        $wider = $sized->withSize(120, 30);

        self::assertNotSame($sized, $wider);
        self::assertSame(120, $wider->cols());
        self::assertSame(30, $wider->rows());
    }

    public function testChangedRowsRebuildsWithTheNewHeight(): void
    {
        $sized = (new Chat())->withSize(100, 30);
        $taller = $sized->withSize(100, 40);

        self::assertNotSame($sized, $taller);
        self::assertSame(100, $taller->cols());
        self::assertSame(40, $taller->rows());
    }

    public function testFirstSizingOfAnUnsizedChatIsAStoredChange(): void
    {
        $bare = new Chat();
        $sized = $bare->withSize(80, 24);

        self::assertNotSame($bare, $sized);
        self::assertSame(80, $sized->cols());
        self::assertSame(24, $sized->rows());
    }

    public function testNonPositiveDimensionsAreIgnoredAndKeepTheInstance(): void
    {
        $sized = (new Chat())->withSize(100, 30);

        self::assertSame($sized, $sized->withSize(0, -1));
    }
}

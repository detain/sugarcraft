<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Tear-down contract for tests that touch the SHARED ReactPHP loop: the
 * census must be empty at the end of every test, so no sibling's drain or
 * default-loop pin can pass or fail on file order (see SharedLoopResidue).
 */
trait SharedLoopClean
{
    protected function assertSharedLoopClean(): void
    {
        $census = SharedLoopResidue::census();
        $total = \array_sum($census);

        Assert::assertSame(
            0,
            $total,
            'left the shared loop armed: ' . SharedLoopResidue::describe()
            . ' — run loop-driving tests on a private StreamSelectLoop'
            . ' (the established pattern in this suite) or cancel what was armed.',
        );
    }
}

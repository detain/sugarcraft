<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\ParallelSafe;

/**
 * Audit F-E4: {@see ParallelSafe}'s docblock is the contract a new parallel
 * tool's author reads, and it described two orphan hazards that 15a B2 and B3
 * closed — "an orphaned tool child has no deadline" (the teardown now kills
 * the whole tree) and "an orphan holds the parent's result socket open" (the
 * frame socket is close-on-exec and the parent polls the child's pid). It
 * must name the mitigations that exist, while keeping the rule that a
 * ParallelSafe tool terminates on its own and the no-/proc caveat.
 */
final class ParallelSafeDocblockTest extends TestCase
{
    public function testTheContractNamesTheTreeKillAndTheCloseOnExecSocket(): void
    {
        $doc = (string) (new \ReflectionClass(ParallelSafe::class))->getDocComment();

        self::assertStringContainsString('ProcessContainment::killTree()', $doc);
        self::assertStringContainsString('ProcessContainment::closeOnExec()', $doc);
        self::assertStringContainsString('must still terminate on its own', $doc);
        self::assertStringContainsString('/proc', $doc, 'the degraded no-/proc case must stay documented');
    }

    public function testTheContractNoLongerClaimsTheHazardsB2AndB3Fixed(): void
    {
        $doc = (string) (new \ReflectionClass(ParallelSafe::class))->getDocComment();

        self::assertStringNotContainsString('An orphaned tool child has no deadline', $doc);
        self::assertStringNotContainsString('An orphan also holds the parent\'s result socket open', $doc);
        self::assertStringNotContainsString('nothing can signal it once its parent is SIGKILLed', $doc);
    }
}

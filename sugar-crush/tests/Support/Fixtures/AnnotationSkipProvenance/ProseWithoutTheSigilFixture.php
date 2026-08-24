<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support\Fixtures\AnnotationSkipProvenance;

use PHPUnit\Framework\TestCase;

/**
 * A FIXTURE, NOT A TEST OF THIS PROJECT, and the CONTROL for its sibling.
 *
 * This is the remedy written out: the same explanation, naming the same
 * directive, spelled WITHOUT the leading sigil that makes a comment executable
 * metadata. It says "a requires-PHP directive" in words. PHPUnit's parser finds
 * nothing here, so this test runs.
 *
 * Its job is to prove the sibling's skip comes from the sigil and not from the
 * subject matter, the file's location, or the way the child run is launched.
 */
final class ProseWithoutTheSigilFixture extends TestCase
{
    public function testTheBodyRuns(): void
    {
        $this->assertTrue(true);
    }
}

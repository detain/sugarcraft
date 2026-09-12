<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support\Fixtures\AnnotationSkipProvenance;

use PHPUnit\Framework\TestCase;

/**
 * A FIXTURE, NOT A TEST OF THIS PROJECT, and the PARENT half of the E391 pair.
 *
 * The trap is the one its siblings carry, moved up one rung of the class
 * ladder: the directive lives on a METHOD declared HERE, and the test that
 * runs is the CHILD's. PHPUnit resolves an inherited method to its declaring
 * declaration, so a paragraph that spells the executable form in a parent's
 * doc-comment skips every child that never mentions a requirement anywhere.
 *
 * ABSTRACT ON PURPOSE: the suite and the guard's own child run both collect by
 * file, and the population this fixture exists to model is the parent nobody
 * instantiates. Its skip must show up under the CHILD's name, which is what
 * the pair is there to prove.
 */
abstract class DirectiveBearingParentFixture extends TestCase
{
    /**
     * A paragraph explaining a version requirement, in the executable form.
     *
     * @requires PHP >= 99.0
     */
    public function testTheBodyNeverRuns(): void
    {
        $this->assertTrue(true);
    }

    /** The same parent, minus the directive: inheritance alone must not skip. */
    public function testTheNeighbourStillRuns(): void
    {
        $this->assertTrue(true);
    }
}

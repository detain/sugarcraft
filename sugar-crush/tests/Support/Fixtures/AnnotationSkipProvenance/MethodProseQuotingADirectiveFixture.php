<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support\Fixtures\AnnotationSkipProvenance;

use PHPUnit\Framework\TestCase;

/**
 * A FIXTURE, NOT A TEST OF THIS PROJECT, and the SECOND GRAMMATICAL SHAPE of
 * the trap its sibling carries.
 *
 * Its sibling quotes the directive in the CLASS doc-comment. This one quotes it
 * on a METHOD, which is both the commoner place for an explanatory paragraph
 * and a different parser call. A control that only ever exercised the class
 * shape left the method walk unpinned, and the mutation that disabled the
 * method walk was green until this file existed.
 *
 * The class doc-comment here deliberately carries NO directive, so a walk that
 * only reads class metadata comes back empty for this file.
 */
final class MethodProseQuotingADirectiveFixture extends TestCase
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

    /** Its neighbour in the same class is untouched, so the skip is per-method. */
    public function testTheNeighbourStillRuns(): void
    {
        $this->assertTrue(true);
    }
}

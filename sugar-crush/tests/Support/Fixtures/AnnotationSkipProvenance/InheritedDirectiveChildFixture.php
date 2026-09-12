<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support\Fixtures\AnnotationSkipProvenance;

/**
 * A FIXTURE, NOT A TEST OF THIS PROJECT, and the CHILD half of the E391 pair.
 *
 * Nothing in this file mentions a requirement, in any spelling, and one of
 * its inherited tests still skips — for the reason the parent's doc-comment
 * gives. Before the provenance walk started following the parent chain it
 * filtered exactly these methods out and reported this child as clean, while
 * the real child run said `skipped`; the census trusted the filter.
 *
 * The class body is empty BY DESIGN. A single override, or any new doc-comment
 * here, would blur which declaration the walker and PHPUnit are answering for.
 */
final class InheritedDirectiveChildFixture extends DirectiveBearingParentFixture
{
}

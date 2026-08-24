<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support\Fixtures\AnnotationSkipProvenance;

use PHPUnit\Framework\TestCase;

/**
 * A FIXTURE, NOT A TEST OF THIS PROJECT. It is deliberately not collected by
 * the suite (see the guard that owns it), and exists only to be run by that
 * guard in a child process.
 *
 * It reproduces the trap: a doc-comment that MENTIONS a requirement directive
 * IS that directive. Nothing here calls a skip-marking method, and the version
 * below cannot be satisfied by any PHP that will ever run this, so PHPUnit
 * reports this test SKIPPED for a reason the file itself never asked for.
 *
 * @requires PHP >= 99.0
 */
final class ProseQuotingADirectiveFixture extends TestCase
{
    public function testTheBodyNeverRuns(): void
    {
        $this->assertTrue(true);
    }
}

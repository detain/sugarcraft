<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * Every shipped example workflow must COMPILE through the public YAML load path.
 *
 * WHY THIS EXISTS: the per-file example tests (WorkflowRegistryTest's
 * lint-then-fix pin, WorkflowRetriesTest's copy) each cover exactly one
 * document, so a second or third example file in examples/workflows/ could
 * drift into an unparsable shape — or copy-paste a stale internal "name:"
 * that no longer matches its own file stem — and no test would notice until
 * a human tried to load it. This sweep turns every example into standing
 * compile coverage.
 *
 * WHY IT ENUMERATES VIA THE REGISTRY'S PUBLIC list(): walking the directory
 * here (scandir/glob/iterator) would make this test itself a directory
 * warker under tests/TreeWideGuardRosterTest.php's licensing, for a read of
 * a checked-in tree the registry ALREADY walks under its own rostered rows.
 * list() plus loadYaml() is the same surface /workflow uses, so this guard
 * proves the shipped examples load the way USERS load them.
 *
 * The registry is pointed ONLY at the repo's examples/workflows directory
 * (passed as its user-tier path; reads never mutate it), never at a real
 * HOME or project tier.
 */
final class ExampleWorkflowsLoadTest extends TestCase
{
    public function testEveryShippedExampleWorkflowCompilesAndNamesItself(): void
    {
        $examplesDir = dirname(__DIR__, 2) . '/examples/workflows';
        self::assertDirectoryExists($examplesDir);

        $registry = new WorkflowRegistry($examplesDir);
        $names = $registry->list();

        // Vacuity floor: the shipped lint-then-fix example plus the fix-wave,
        // docs-drift-sweep and review-loop templates. If the directory ever
        // stops being readable through list(), this fails instead of sweeping
        // nothing green.
        self::assertGreaterThanOrEqual(4, count($names));

        foreach ($names as $name) {
            $workflow = $registry->loadYaml($name);

            // A copy-pasted doc whose internal name no longer matches its
            // file stem loads under the doc's name and silently SHADOWS the
            // file: pin stem==name so the drift fails closed here.
            self::assertSame($name, $workflow->name, 'Internal name drifted from file stem for example: ' . $name);
            self::assertNotEmpty($workflow->stages, 'Example workflow has no stages: ' . $name);
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * Every shipped workflow LINTS clean of errors — the YAML examples under
 * examples/workflows/ and the PHP workflows under workflows/.
 *
 * WHY: the linter mirrors the engine, and a shipped example that trips it is
 * one of two bugs — a broken example, or a linter that reports something
 * the engine does not do (wf-impl track A found every original example
 * tripping the false "parallel stages never write .output" error). Either
 * way the copy-paste starting point for authors must lint clean. Warnings
 * are allowed: an example may legitimately expect run context it is handed.
 *
 * Enumerated through the registry's public list()/load() for the reason
 * {@see ExampleWorkflowsLoadTest} gives: it is the surface users load by.
 */
final class ExampleWorkflowsLintTest extends TestCase
{
    public function testEveryShippedWorkflowLintsWithoutErrors(): void
    {
        $linted = 0;
        foreach (['/examples/workflows', '/workflows'] as $dir) {
            $path = dirname(__DIR__, 2) . $dir;
            self::assertDirectoryExists($path);
            $registry = new WorkflowRegistry($path);

            foreach ($registry->list() as $name) {
                $report = WorkflowLinter::lint($registry->load($name));
                self::assertSame(0, $report->errorCount(), "{$dir}/{$name} lints with errors:\n" . $report->renderFindings());
                $linted++;
            }
        }

        // Vacuity floor: four YAML examples plus deep-research.php.
        self::assertGreaterThanOrEqual(5, $linted);
    }
}

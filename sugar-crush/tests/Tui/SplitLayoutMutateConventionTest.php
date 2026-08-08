<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\SplitLayout;

/**
 * @internal
 *
 * Regression pin for R26: SplitLayout's with*() methods must delegate
 * through a private mutate() helper (repo-wide immutable+fluent
 * convention, see candy-sprinkles/src/Style.php and
 * candy-buffer/src/Style.php) instead of hand-rolling `new self(...)` in
 * each setter. Source-inspects each with*() method body via reflection so
 * a revert back to hand-rolled `new self(...)` fails this test even though
 * the resulting behaviour would be identical.
 *
 * @see SplitLayout
 */
final class SplitLayoutMutateConventionTest extends TestCase
{
    public function testWithMethodsDelegateThroughPrivateMutateHelper(): void
    {
        $class = new \ReflectionClass(SplitLayout::class);

        $this->assertTrue($class->hasMethod('mutate'), 'SplitLayout must define a mutate() helper');

        $mutateMethod = $class->getMethod('mutate');
        $this->assertTrue($mutateMethod->isPrivate(), 'mutate() must be private');

        $withMethods = array_filter(
            $class->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn(\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'with'),
        );

        $this->assertNotEmpty($withMethods, 'expected at least one with*() method on SplitLayout');

        foreach ($withMethods as $method) {
            $source = $this->methodSource($method);

            $this->assertStringContainsString(
                '$this->mutate(',
                $source,
                "{$method->getName()}() must delegate through \$this->mutate()",
            );
            $this->assertStringNotContainsString(
                'new self(',
                $source,
                "{$method->getName()}() must not hand-roll new self(...) instead of using mutate()",
            );
        }
    }

    /**
     * Extracts a method's exact source text via its file/line span, so the
     * assertion above inspects real implementation code, not just its name.
     */
    private function methodSource(\ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();

        $this->assertNotFalse($file, 'reflection must resolve a source file');

        $lines = file($file);
        $this->assertNotFalse($lines, "failed to read source file $file");

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}

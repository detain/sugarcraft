<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Examples;

use PHPUnit\Framework\TestCase;

/**
 * Structural guard for examples/dashboard-live.php (review finding m1).
 *
 * The demo's C-1/C-2/C-3 cures are pinned at runtime only by a one-shot PTY
 * capture outside the repo, and GoldenSnapshotTest skips every interactive
 * demo by design — so nothing in the suite could catch a regression. This
 * guard is hermetic: it reads the example's SOURCE (no PTY, no event loop)
 * and pins the three cured shapes textually, the same genre as
 * ExampleImportsTest's import-resolution scan.
 */
final class DashboardLiveStructureTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../examples/dashboard-live.php';

    private static function exampleSource(): string
    {
        $src = @file_get_contents(self::EXAMPLE);
        if ($src === false) {
            throw new \RuntimeException('cannot read example source at ' . self::EXAMPLE);
        }

        return $src;
    }

    public function testTheDemoModelDeclaresSubscriptions(): void
    {
        // C-1: sugar-core Program consumes subscriptions(); its absence made
        // the demo fatal at launch. The example declares the contract once
        // and DashboardModel implements it — at least two declarations must
        // exist, on a DashboardModel that still implements Model.
        $src = self::exampleSource();

        $this->assertMatchesRegularExpression('/final class DashboardModel implements Model\b/', $src);
        $this->assertGreaterThanOrEqual(2, substr_count($src, 'function subscriptions('));
    }

    public function testAdvertisedQuitKeyMintsQuitCmd(): void
    {
        // C-3: the advertised 'q' key must reach Cmd::quit() through
        // update() itself — the old dead shape delegated to handleKey()
        // and waited for a QuitMsg nobody minted.
        $src = self::exampleSource();

        $at = strpos($src, "\$msg->rune === 'q'");
        $this->assertIsInt($at, "update() must branch on the advertised 'q' key");
        $this->assertStringContainsString(
            'Cmd::quit()',
            substr($src, $at, 160),
            "the 'q' branch must mint Cmd::quit() right there",
        );
    }

    public function testDemoNeverCallsThePhantomWithAlpha(): void
    {
        // C-2: FocusedPanel used to call Color::withAlpha() (and Panel
        // minSize()/getWidth()/getHeight() that never existed) and fatal on
        // first paint. The cured shape calls none of them; pin the arrow-
        // call token so a reintroduction goes red in-suite. (The prose
        // comment "Color has no withAlpha()" carries no arrow prefix.)
        $src = self::exampleSource();

        $this->assertStringNotContainsString('->withAlpha(', $src);
    }
}

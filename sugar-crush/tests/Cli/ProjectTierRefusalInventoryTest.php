<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Skills\SkillManager;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

/**
 * The two counts {@see Bootstrap::$projectTierRefusals} and
 * {@see Bootstrap::projectTierRefusals()} state in prose, measured.
 *
 * Both went stale in the SAME COMMIT that changed them: the collector doc-block
 * said "both subsystems" after a third started feeding it, and the enumeration of
 * repository-chosen directories listed four after a fifth was added. Neither is a
 * behaviour bug; both are a security argument's inventory describing a tree it no
 * longer matched, which is the defect class this session keeps finding.
 *
 * WHAT THIS PINS AND WHAT IT DOES NOT: it pins the number of subsystems that
 * EXPOSE a refusal seam and the number of repository-chosen directory names that
 * appear in `src/`. It cannot tell whether a subsystem that should refuse does —
 * that is what each tier's own containment test is for
 * ({@see \SugarCraft\Crush\Tests\Agents\AgentPresetDirContainmentTest} and its
 * siblings).
 */
final class ProjectTierRefusalInventoryTest extends TestCase
{
    /**
     * THREE feeders, named. Each exposes a pull-based refusal seam that
     * {@see Bootstrap::agentPresets()} and its siblings merge into one collector.
     */
    public function testTheThreeSubsystemsThatFeedTheCollectorAllExposeTheirSeam(): void
    {
        $this->assertTrue(method_exists(WorkflowRegistry::class, 'projectTierRefusal'));
        $this->assertTrue(method_exists(SkillManager::class, 'refusedDirectories'));
        $this->assertTrue(method_exists(AgentPresetRegistry::class, 'refusedDirectories'));

        $bootstrap = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php',
        );

        foreach (
            [
                'projectTierRefusal()',
                'refusedDirectories()',
            ] as $seam
        ) {
            $this->assertStringContainsString($seam, $bootstrap, "Bootstrap must drain {$seam}");
        }
    }

    /**
     * The FOURTH holder of a repository-chosen directory, and the reason it is
     * named as a gap rather than counted as a feeder: it reports by `error_log()`
     * and is dormant. Pinned so "three feeders" cannot quietly become four (or
     * stay three after this one is wired) without this test saying so.
     */
    public function testCommandLoaderIsDormantAndDoesNotFeedTheCollector(): void
    {
        $this->assertFalse(method_exists(CommandLoader::class, 'refusedDirectories'));
        $this->assertFalse(method_exists(CommandLoader::class, 'projectTierRefusal'));

        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Commands/CommandLoader.php');
        $this->assertStringNotContainsString('projectTierRefusal', $source);
        $this->assertStringContainsString('error_log', $source);

        // Dormant, not disabled: the anchored two-argument contract is present and
        // its containment check is real — nothing in src/ or bin/ constructs it
        // with an anchor yet.
        $anchored = (new \ReflectionMethod(CommandLoader::class, 'loadFromDirectory'))
            ->getParameters();
        $this->assertCount(2, $anchored);
        $this->assertSame('anchoredIn', $anchored[1]->getName());
        $this->assertTrue($anchored[1]->isOptional());
    }

    /**
     * FIVE repository-chosen directory names, which is what the enumeration in
     * {@see Bootstrap::projectTierRefusals()} claims. Derived from `src/`, so the
     * enumeration cannot drift from the tiers that exist.
     */
    public function testTheFiveRepositoryChosenDirectoryNames(): void
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $haystack = '';
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $haystack .= (string) file_get_contents($file->getPathname());
            }
        }

        $names = [
            '.sugar-crush/workflows',
            '.sugar-crush/skills',
            '.claude/skills',
            '.opencode/skills',
            '.sugar-crush/agents',
        ];

        foreach ($names as $name) {
            $this->assertStringContainsString($name, $haystack, "{$name} is one of the five");
        }

        // SCOPED TO THE DOC-BLOCK THAT MAKES THE CLAIM, not to the file. Asserted
        // file-wide, this passed while the enumeration itself was missing a name,
        // because the same name appears backticked in another comment a few
        // hundred lines away — a false green of exactly the shape being fixed.
        $enumeration = $this->docBlockAbove($src . '/Cli/Bootstrap.php', 'public static function projectTierRefusals()');

        foreach ($names as $name) {
            $this->assertStringContainsString(
                '`' . $name . '`',
                $enumeration,
                "projectTierRefusals()'s own doc-block must name {$name}",
            );
        }

        $this->assertCount(5, $names);
    }

    /**
     * The doc-comment immediately preceding $signature, and nothing else in the
     * file — see {@see testTheFiveRepositoryChosenDirectoryNames()} for why the
     * narrowing is the point.
     */
    private function docBlockAbove(string $file, string $signature): string
    {
        $lines = (array) file($file);

        $end = null;
        foreach ($lines as $i => $line) {
            if (str_contains((string) $line, $signature)) {
                $end = $i;

                break;
            }
        }

        $this->assertNotNull($end, "{$signature} not found in {$file}");

        $block = [];
        for ($i = (int) $end - 1; $i >= 0; --$i) {
            $trimmed = trim((string) $lines[$i]);
            $block[] = $trimmed;
            if (str_starts_with($trimmed, '/**')) {
                break;
            }

            $this->assertTrue(
                $trimmed === '' || str_starts_with($trimmed, '*'),
                "no doc-block immediately above {$signature}",
            );
        }

        return implode("\n", array_reverse($block));
    }
}

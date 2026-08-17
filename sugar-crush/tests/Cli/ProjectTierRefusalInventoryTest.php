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
 * AND THE ENUMERATION ITSELF WAS THE NEXT INSTANCE. Its replacement's doc-block
 * said "Derived from `src/`, so the enumeration cannot drift from the tiers that
 * exist" over a hard-coded five-element literal whose only contact with the tree
 * was `assertStringContainsString()` on names it already held, closing with
 * `assertCount(5, $names)` — a literal asserted to have the length it was
 * written with. It could not discover a sixth tier, and the two it was missing
 * were already `true` in its own haystack. Derived-in-name is worse than prose,
 * because it reads as proven; see
 * {@see testTheDotPathEnumerationIsDerivedFromSrc()} for what replaced it.
 *
 * WHAT THIS PINS AND WHAT IT DOES NOT: it pins the number of subsystems that
 * EXPOSE a refusal seam, every dot-path literal in `src/` and its
 * classification, and the presence of both containment gates in each dormant
 * holder. It cannot tell whether a gate that is present is CORRECT — that is
 * what each tier's own containment test is for
 * ({@see \SugarCraft\Crush\Tests\Agents\AgentPresetDirContainmentTest},
 * {@see \SugarCraft\Crush\Tests\Agents\ForeignAgentPresetDirContainmentTest} and
 * their siblings) — and it cannot see a repository-chosen path built from
 * fragments rather than written as one literal.
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
     * The dot-paths that exist in `src/`, CLASSIFIED — every one of them, not a
     * list of the ones somebody remembered.
     *
     * The KEYS are asserted against a derivation over `src/`, so this map cannot
     * be short: a new dot-path literal anywhere in `src/` reds
     * {@see testTheDotPathEnumerationIsDerivedFromSrc()} until it is classified
     * here. The VALUES are a judgement — "is this a path a cloned REPOSITORY
     * chooses" is not visible in a string literal — and they are written down so
     * the judgement is reviewable rather than implicit.
     *
     * @var array<string, string>
     */
    private const DOT_PATHS = [
        // Repository-chosen: the checkout says where these point.
        '.claude/agents' => self::REPOSITORY,
        '.claude/skills' => self::REPOSITORY,
        '.opencode/agents' => self::REPOSITORY,
        '.opencode/memory' => self::REPOSITORY,
        '.opencode/skills' => self::REPOSITORY,
        '.sugar-crush/agents' => self::REPOSITORY,
        '.sugar-crush/commands' => self::REPOSITORY,
        '.sugar-crush/hooks.yaml' => self::REPOSITORY,
        '.sugar-crush/skills' => self::REPOSITORY,
        '.sugar-crush/workflows' => self::REPOSITORY,

        // User-tier: rooted at `~`, so nobody but the user chose the location.
        '.config/opencode' => self::USER,
        '.config/sugarcraft-crush' => self::USER,
        '.local/share' => self::USER,
        '.sugar-crush/config.dev.json' => self::USER,
        '.sugar-crush/config.json' => self::USER,
        '.sugar-crush/config.json.' => self::USER,
        '.sugar-crush/teams' => self::USER,
        '.sugar-crush/worktrees' => self::USER,

        // Neither: not a tier this collector is about.
        '.git/info' => self::NOT_A_TIER,
        '.well-known/oauth-authorization-server' => self::NOT_A_TIER,
    ];

    private const REPOSITORY = 'repository-chosen';
    private const USER = 'user-tier';
    private const NOT_A_TIER = 'not a tier';

    /**
     * THE DERIVATION, and it is the whole point of this revision.
     *
     * The test this replaces carried the sentence "Derived from `src/`, so the
     * enumeration cannot drift from the tiers that exist" above a hard-coded
     * five-element literal. Its only contact with `src/` was
     * `assertStringContainsString($name, $wholeSrcConcatenated)` — an assertion
     * that each name it already knew was somewhere in the tree — and it closed
     * with `assertCount(5, $names)`, asserting that a five-element literal has
     * five elements. It was structurally incapable of discovering a sixth tier,
     * and BOTH names it was missing (`.claude/agents` and `.opencode/agents`)
     * were already in its own haystack, `true` on both counts. A test that calls
     * itself derived and is a literal is worse than prose, because it reads as
     * proven.
     *
     * This walks `src/` with `token_get_all()`, takes every string literal, and
     * pulls out every `.<dot-dir>/<segment>` it contains. On this tree that is
     * TWENTY, of which TEN are repository-chosen. A new one anywhere in `src/`
     * fails here by name until somebody classifies it.
     */
    public function testTheDotPathEnumerationIsDerivedFromSrc(): void
    {
        $derived = $this->dotPathsIn(\dirname(__DIR__, 2) . '/src');

        // Sorted on both sides: the map above is grouped by classification for
        // a reader, the derivation is `ksort`ed, and the comparison is about
        // membership rather than either ordering.
        $classified = array_keys(self::DOT_PATHS);
        sort($classified);

        $this->assertSame(
            $classified,
            array_keys($derived),
            'a dot-path in src/ that this inventory does not classify: '
            . implode(', ', array_diff(array_keys($derived), array_keys(self::DOT_PATHS))),
        );
    }

    /**
     * TEN repository-chosen paths, and the enumeration in
     * {@see Bootstrap::projectTierRefusals()}'s own doc-block must name every one
     * of them. It named FOUR, then FIVE, both hand-written, while `src/` held
     * ten.
     */
    public function testEveryRepositoryChosenPathIsNamedWhereTheClaimIsMade(): void
    {
        $repository = array_keys(
            array_filter(self::DOT_PATHS, static fn (string $kind): bool => $kind === self::REPOSITORY),
        );

        $this->assertCount(10, $repository);

        // SCOPED TO THE DOC-BLOCKS THAT MAKE THE CLAIM, not to the file. Asserted
        // file-wide, this passed while the enumeration itself was missing a name,
        // because the same name appears backticked in another comment a few
        // hundred lines away — a false green of exactly the shape being fixed.
        $bootstrap = \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php';
        $enumeration = $this->docBlockAbove($bootstrap, 'public static function projectTierRefusals()')
            . "\n" . $this->docBlockAbove($bootstrap, 'private static array $projectTierRefusals = [];');

        foreach ($repository as $name) {
            $this->assertStringContainsString(
                '`' . $name . '`',
                $enumeration,
                "the collector's own doc-blocks must name {$name}",
            );
        }
    }

    /**
     * Which of the ten reach the collector, and which are gated elsewhere. FIVE
     * and FIVE — stated here so "three feeders" cannot quietly stand in for "and
     * five paths nobody drains".
     */
    public function testTheFiveThatFeedTheCollectorAndTheFiveThatAreNamedGaps(): void
    {
        $feeders = ['.claude/skills', '.opencode/skills', '.sugar-crush/agents',
            '.sugar-crush/skills', '.sugar-crush/workflows'];
        $gaps = ['.claude/agents', '.opencode/agents', '.opencode/memory',
            '.sugar-crush/commands', '.sugar-crush/hooks.yaml'];

        $repository = array_keys(
            array_filter(self::DOT_PATHS, static fn (string $kind): bool => $kind === self::REPOSITORY),
        );

        $union = array_merge($feeders, $gaps);
        sort($union);

        $this->assertSame($repository, $union, 'every repository-chosen path is one or the other');
        $this->assertSame([], array_intersect($feeders, $gaps), 'and never both');
    }

    /**
     * DORMANT IS NOT UNGATED — the finding this round's gating work came from.
     * Each of the four dormant holders routes its repository-chosen directory
     * through {@see \SugarCraft\Crush\Support\ContainedPath}, so "nothing
     * constructs it yet" is never again the whole answer to "is it contained".
     *
     * @return array<string, array{0: string}>
     */
    public static function dormantHolders(): array
    {
        return [
            'foreign agent presets' => ['src/Agents/ForeignAgentPresetRegistry.php'],
            'foreign memory import' => ['src/Memory/ForeignMemoryImporter.php'],
            'custom commands' => ['src/Commands/CommandLoader.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dormantHolders')]
    public function testEveryDormantHolderOfARepositoryChosenDirectoryIsGated(string $relative): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $relative);

        $this->assertStringContainsString('ContainedPath::below(', $source, "{$relative} anchors its directory");
        $this->assertStringContainsString('ContainedPath::within(', $source, "{$relative} bounds its entries");
    }

    /**
     * Every `.<dir>/<segment>` appearing in a string literal under $src.
     *
     * Token-derived rather than grepped: a `.claude/agents` inside a doc-comment
     * is a cross-reference, and the previous instrument's whole-file
     * concatenation could not tell one from a path the code builds.
     *
     * @return array<string, list<string>> dot-path => files it appears in, sorted
     */
    private function dotPathsIn(string $src): array
    {
        $found = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($src) + 1);
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!\is_array($token)
                    || !\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)
                ) {
                    continue;
                }

                if (preg_match_all('#(?:^|/|\'|")(\.[a-z][a-z0-9._-]*/[A-Za-z0-9._-]+)#', $token[1], $matches)) {
                    foreach ($matches[1] as $hit) {
                        $found[$hit][$relative] = true;
                    }
                }
            }
        }

        ksort($found);

        return array_map(static fn (array $files): array => array_keys($files), $found);
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

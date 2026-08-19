<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
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
 * classification, and the presence of both containment gates in every holder of a
 * repository-chosen directory — dormant ({@see dormantHolders()}) or wired
 * ({@see wiredHolders()}), because acquiring a production caller must not be how a
 * holder loses its gate requirement.
 * It cannot tell whether a gate that is present is CORRECT — that is
 * what each tier's own containment test is for
 * ({@see \SugarCraft\Crush\Tests\Agents\AgentPresetDirContainmentTest},
 * {@see \SugarCraft\Crush\Tests\Agents\ForeignAgentPresetDirContainmentTest} and
 * their siblings) — and it cannot see a repository-chosen path built from
 * fragments rather than written as one literal.
 */
final class ProjectTierRefusalInventoryTest extends TestCase
{
    /**
     * FOUR feeders, named. Each exposes a pull-based refusal seam that
     * {@see Bootstrap::agentPresets()} and its siblings merge into one collector.
     *
     * The fourth is {@see ForeignAgentPresetRegistry}, which arrived when
     * crush_code.md Phase 1 item 3 gave its seam a reader in
     * {@see Bootstrap::foreignAgentPresets()}. It had been listed as a named GAP
     * on the collector for the round its gates existed without a consumer, which
     * is the transition this file exists to keep visible in both directions.
     */
    public function testTheFourSubsystemsThatFeedTheCollectorAllExposeTheirSeam(): void
    {
        $this->assertTrue(method_exists(WorkflowRegistry::class, 'projectTierRefusal'));
        $this->assertTrue(method_exists(SkillManager::class, 'refusedDirectories'));
        $this->assertTrue(method_exists(AgentPresetRegistry::class, 'refusedDirectories'));
        $this->assertTrue(method_exists(ForeignAgentPresetRegistry::class, 'refusedDirectories'));

        $bootstrap = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php',
        );

        foreach (
            [
                'projectTierRefusal()',
                'refusedDirectories()',
                // The CONSTRUCTION, not only the drain: `refusedDirectories()`
                // above is satisfied by the native registry's call alone, so a
                // deleted foreign call site would leave this test green on the
                // seam it is meant to be checking.
                'new ForeignAgentPresetRegistry()',
            ] as $seam
        ) {
            $this->assertStringContainsString($seam, $bootstrap, "Bootstrap must drain {$seam}");
        }
    }

    /**
     * THE FOURTH SEAM, and the collector's own doc-block now says so: the workflow
     * registry exposes TWO, because its user tier — `~/.sugar-crush/workflows`, the
     * one directory in the package whose `.php` files are `require`d — can be refused
     * as well as its project tier.
     *
     * Asserted rather than left in prose, since "three feeders quietly becoming four"
     * is the exact drift this file exists to refuse. It is the one entry in the
     * collector that is NOT a project tier; the collector's doc-block states that
     * mismatch, and this test pins that the sentence and the drain both exist.
     */
    public function testTheWorkflowRegistryExposesAUserTierSeamAndBootstrapDrainsIt(): void
    {
        $this->assertTrue(method_exists(WorkflowRegistry::class, 'userTierRefusal'));

        $bootstrap = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        $this->assertStringContainsString('userTierRefusal()', $bootstrap, 'Bootstrap must drain it');

        $collector = $this->docBlockAbove(
            \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php',
            'private static array $projectTierRefusals = [];',
        );
        $this->assertStringContainsString(
            'userTierRefusal()',
            $collector,
            "the collector's own doc-block must name the seam that is not a project tier",
        );
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
     * KEYED BY `file|dot-path`, NOT BY THE DOT-PATH, and that is this revision's
     * whole point. The previous map keyed on the STRING, so one classification
     * covered every occurrence of it — and `.sugar-crush/config.json` was
     * classified `user-tier` with the note "rooted at `~`, so nobody but the user
     * chose the location", which is true of {@see Bootstrap}'s call site and
     * FALSE of {@see \SugarCraft\Crush\Agents\WorktreeConfig::new()}'s, where the
     * same string is resolved from `__DIR__` and had no containment at all. The
     * ninth ungated read path was therefore classified as safe by the instrument
     * whose job is to classify it. Two more strings are the same shape:
     * `.claude/skills` and `.sugar-crush/skills` each serve BOTH tiers inside one
     * file, from one constant.
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
        // Repository-chosen: the checkout under analysis says where these point.
        'Agents/ForeignAgentPresetRegistry.php|.opencode/agents' => self::REPOSITORY,
        'Chat.php|.sugar-crush/workflows' => self::REPOSITORY,
        'Cli/Bootstrap.php|.sugar-crush/agents' => self::REPOSITORY,
        'Cli/Bootstrap.php|.sugar-crush/hooks.yaml' => self::REPOSITORY,
        'Cli/Bootstrap.php|.sugar-crush/workflows' => self::REPOSITORY,
        'Commands/CommandLoader.php|.sugar-crush/commands' => self::REPOSITORY,
        'Memory/ForeignMemoryImporter.php|.opencode/memory' => self::REPOSITORY,
        'Skills/ForeignSkillDiscovery.php|.opencode/skills' => self::REPOSITORY,
        'Skills/SkillLoader.php|.sugar-crush/skills' => self::REPOSITORY,
        'Workflows/WorkflowRegistry.php|.sugar-crush/workflows' => self::REPOSITORY,

        // ONE STRING, BOTH TIERS, inside one file — the shape the old key could
        // not express. Each of these builds a project path and a `~` path from
        // the same literal (`ForeignAgentPresetRegistry::scanClaude()`,
        // `ForeignSkillDiscovery::discoverClaude()`, and `SkillDiscovery`'s
        // PROJECT_SUBDIR/USER_SUBDIR pair, which are the same string twice).
        'Agents/ForeignAgentPresetRegistry.php|.claude/agents' => self::BOTH,
        'Skills/ForeignSkillDiscovery.php|.claude/skills' => self::BOTH,
        'Skills/SkillDiscovery.php|.sugar-crush/skills' => self::BOTH,

        // User-tier: rooted at `~`, so nobody but the user chose the location.
        'Agents/ForeignAgentPresetRegistry.php|.config/opencode' => self::USER,
        'Agents/Team.php|.sugar-crush/teams' => self::USER,
        'Agents/TeamConfig.php|.sugar-crush/teams' => self::USER,
        'Agents/TeamManager.php|.sugar-crush/teams' => self::USER,
        'Agents/Teammate.php|.sugar-crush/teams' => self::USER,
        'Cli/Help.php|.sugar-crush/config.json' => self::USER,
        'Cli/Help.php|.sugar-crush/config.json.' => self::USER,
        'MCP/OAuthClientRegistration.php|.local/share' => self::USER,
        'Session.php|.config/sugarcraft-crush' => self::USER,
        'Skills/ForeignSkillDiscovery.php|.config/opencode' => self::USER,

        // PACKAGE-RELATIVE — the tier the old two-value map had no name for, and
        // the one the ninth read path lived in. Resolved from `__DIR__`, so the
        // location is chosen by whoever laid the INSTALL out: the monorepo root
        // in development, `vendor/sugarcraft/` under a composer install. Not `~`
        // and not the checkout under analysis. Still gated, because a
        // package-relative path is one a symlink can move just as easily.
        'Agents/WorktreeConfig.php|.sugar-crush/config.json' => self::PACKAGE,
        'Providers/ProviderFactory.php|.sugar-crush/config.dev.json' => self::PACKAGE,

        // Neither: not a tier this collector is about.
        'Agents/WorktreeConfig.php|.sugar-crush/worktrees' => self::NOT_A_TIER,
        'Commands/McpAuthCommand.php|.well-known/oauth-authorization-server' => self::NOT_A_TIER,
        'Tools/IgnoreRules.php|.git/info' => self::NOT_A_TIER,
    ];

    private const REPOSITORY = 'repository-chosen';
    private const USER = 'user-tier';
    private const BOTH = 'both tiers, from one string';
    private const PACKAGE = 'package-relative';
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
     * pulls out every `.<dot-dir>/<segment>` it contains, KEYED BY THE FILE IT
     * APPEARS IN. On this tree that is TWENTY-EIGHT occurrences of twenty
     * distinct paths, of which ten distinct paths are repository-chosen. A new
     * one anywhere in `src/` fails here by file and name until somebody
     * classifies it — and the SAME path arriving in a SECOND file fails too,
     * which is the case the string-keyed version could not express.
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
            'a dot-path occurrence in src/ that this inventory does not classify: '
            . implode(', ', array_diff(array_keys($derived), array_keys(self::DOT_PATHS))),
        );
    }

    /**
     * THE TIER IS A PROPERTY OF THE OCCURRENCE, not of the string — asserted so
     * a future revision cannot quietly go back to keying on the path.
     *
     * Two strings prove it on this tree: `.sugar-crush/config.json` is user-tier
     * in `Cli/Help.php` and PACKAGE-RELATIVE in `Agents/WorktreeConfig.php`
     * (where it had no containment at all for nine rounds), and
     * `.sugar-crush/workflows` is repository-chosen in three files while
     * `.sugar-crush/skills` serves BOTH tiers in one.
     */
    public function testOneDotPathCanCarryDifferentTiersInDifferentFiles(): void
    {
        $byPath = [];
        foreach (self::DOT_PATHS as $occurrence => $kind) {
            [, $path] = explode('|', $occurrence, 2);
            $byPath[$path][$kind] = true;
        }

        $this->assertSame(
            [self::USER => true, self::PACKAGE => true],
            $byPath['.sugar-crush/config.json'],
            'the string that was classified user-tier everywhere while one of its two homes was ungated',
        );

        $this->assertArrayHasKey(self::BOTH, $byPath['.sugar-crush/skills']);
    }

    /**
     * TEN repository-chosen paths, and the enumeration in
     * {@see Bootstrap::projectTierRefusals()}'s own doc-block must name every one
     * of them. It named FOUR, then FIVE, both hand-written, while `src/` held
     * ten.
     *
     * `BOTH` counts here: a string serving the project tier is repository-chosen
     * whatever else it also serves.
     */
    public function testEveryRepositoryChosenPathIsNamedWhereTheClaimIsMade(): void
    {
        $repository = $this->repositoryChosenPaths();

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
     * THE DERIVATION'S SHAPE IS THE DOMAIN OF BOTH NUMBERS, and this is what says
     * so — because a number stated without its domain is the defect this whole
     * file exists to catch, and the enumeration above had made it about itself.
     *
     * {@see dotPathsIn()} matches `.<dir>/<segment>`, so a bare dot-FILE is
     * invisible to it. `.mcp.json` is exactly that: repository-chosen, whose
     * contents name commands to `proc_open()`, gated by containment AND by
     * `trustedProjectMcp`, and a direct writer of the collector — and it is not
     * one of the ten. Both halves are asserted, so neither "TEN" nor the
     * enumeration under it can quietly start reading as "every repository-chosen
     * path".
     */
    public function testTheDerivationCannotSeeABareDotFileAndTheOneThisCollectorHoldsIsNamedAnyway(): void
    {
        $derived = $this->dotPathsIn(\dirname(__DIR__, 2) . '/src');

        foreach (array_keys($derived) as $occurrence) {
            $this->assertStringNotContainsString(
                '.mcp.json',
                $occurrence,
                'the derivation matches a dot-DIRECTORY path; a bare dot-file appearing here '
                . 'means the shape widened and both counts need re-deriving',
            );
        }

        $this->assertNotContains('.mcp.json', $this->repositoryChosenPaths());
        $this->assertSame('.mcp.json', Bootstrap::MCP_CONFIG_FILENAME, 'the file the claim below is about');

        // ...and it must still be NAMED where the counts are stated, exactly as
        // each of the ten is.
        $bootstrap = \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php';
        $enumeration = $this->docBlockAbove($bootstrap, 'public static function projectTierRefusals()')
            . "\n" . $this->docBlockAbove($bootstrap, 'private static array $projectTierRefusals = [];');

        $this->assertStringContainsString('`.mcp.json`', $enumeration);
        // And the qualifier that makes "TEN" true, rather than the bare number
        // the last revision of that doc-block carried.
        $this->assertStringContainsString('DOT-DIRECTORY', $enumeration);
    }

    /**
     * Which of the ten reach the collector, and which are gated elsewhere. SEVEN
     * and THREE — stated here so "four feeders" cannot quietly stand in for "and
     * three paths nobody drains".
     *
     * It was FIVE AND FIVE until crush_code.md Phase 1 item 3 wired
     * {@see Bootstrap::foreignAgentPresets()}: `.claude/agents` and
     * `.opencode/agents` moved from the gap column to the feeder column, which is
     * a named gap being closed rather than a count drifting. The two halves are
     * asserted against the derivation, so the move cannot be recorded in one
     * column only.
     */
    public function testTheSevenThatFeedTheCollectorAndTheThreeThatAreNamedGaps(): void
    {
        $feeders = ['.claude/agents', '.claude/skills', '.opencode/agents',
            '.opencode/skills', '.sugar-crush/agents', '.sugar-crush/skills',
            '.sugar-crush/workflows'];
        $gaps = ['.opencode/memory', '.sugar-crush/commands', '.sugar-crush/hooks.yaml'];

        $union = array_merge($feeders, $gaps);
        sort($union);

        $this->assertSame(
            $this->repositoryChosenPaths(),
            $union,
            'every repository-chosen path is one or the other',
        );
        $this->assertSame([], array_intersect($feeders, $gaps), 'and never both');
    }

    /**
     * The distinct dot-paths any occurrence of which serves the project tier.
     *
     * @return list<string>
     */
    private function repositoryChosenPaths(): array
    {
        $paths = [];
        foreach (self::DOT_PATHS as $occurrence => $kind) {
            if ($kind === self::REPOSITORY || $kind === self::BOTH) {
                [, $path] = explode('|', $occurrence, 2);
                $paths[$path] = true;
            }
        }

        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    /**
     * DORMANT IS NOT UNGATED — the finding this round's gating work came from.
     * Each dormant holder routes its non-`~` directory through
     * {@see \SugarCraft\Crush\Support\ContainedPath}, so "nothing constructs it
     * yet" is never again the whole answer to "is it contained".
     *
     * THIS PROVIDER NO LONGER CARRIES THE GATE REQUIREMENT ON ITS OWN. The
     * requirement is keyed to holding a repository-chosen directory, not to being
     * dormant, so it is driven from {@see holdersOfARepositoryChosenDirectory()};
     * a holder that acquires a production caller moves to
     * {@see wiredHolders()} and keeps every gate. What this list still states, and
     * the only thing it states, is which holders nothing in `src/` or `bin/`
     * constructs.
     *
     * THE DOC-BLOCK HERE SAID "FOUR" WHILE THE PROVIDER RETURNED THREE, and a
     * fourth genuinely existed: {@see \SugarCraft\Crush\Agents\WorktreeConfig}
     * read `.sugar-crush/config.json` from `__DIR__` with no containment at all,
     * and {@see \SugarCraft\Crush\Agents\WorktreeManager} then turned that file's
     * `worktreeIncludeFile` value into a read of an arbitrary file and every one
     * of its lines into a copy pattern — measured reading outside the checkout
     * AND writing outside the worktree. A count in a doc-block disagreeing with
     * the list under it, in the test that exists to catch counts disagreeing with
     * lists, is why this provider is now the ONLY place the number lives.
     *
     * THE REQUIRED GATE KINDS ARE PER-HOLDER, not one blanket pair, because
     * "both gates everywhere" is a claim that is FALSE of one of them and a test
     * asserting it would have to be weakened rather than met.
     * {@see \SugarCraft\Crush\Agents\WorktreeManager} has no directory to
     * ANCHOR: both of its boundaries are entry-level — the include file against
     * the repo root, and each copy pattern's source against the same root — so
     * it needs `within` twice and `below` never. Writing the expectation down
     * per holder is the difference between a pinned decision and a hole.
     *
     * @return array<string, array{0: string, 1: class-string, 2: list<string>}>
     */
    public static function dormantHolders(): array
    {
        return [
            'foreign memory import' => [
                'src/Memory/ForeignMemoryImporter.php',
                \SugarCraft\Crush\Tests\Memory\ForeignMemoryImporterContainmentTest::class,
                ['below', 'within'],
            ],
            'custom commands' => [
                'src/Commands/CommandLoader.php',
                \SugarCraft\Crush\Tests\Support\CommandLoaderContainmentTest::class,
                ['below', 'within'],
            ],
            'worktree config' => [
                'src/Agents/WorktreeConfig.php',
                \SugarCraft\Crush\Tests\Agents\WorktreeConfigTest::class,
                ['below', 'within'],
            ],
            'worktree include resolution' => [
                'src/Agents/WorktreeManager.php',
                \SugarCraft\Crush\Tests\Agents\WorktreeIncludeContainmentTest::class,
                ['within'],
            ],
        ];
    }

    /**
     * The holders that are NO LONGER DORMANT and must keep every gate they were
     * given while they were.
     *
     * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} left
     * {@see dormantHolders()} when crush_code.md Phase 1 item 3 wired it into
     * {@see Bootstrap::foreignAgentPresets()}. Moving it out of that provider
     * without moving it into this one would have DELETED the gate requirement at
     * the exact moment the class acquired a production caller — the inverse of the
     * defect this file was written for, and the reason the requirement is keyed to
     * "holds a repository-chosen directory" rather than to "is dormant".
     *
     * @return array<string, array{0: string, 1: class-string, 2: list<string>}>
     */
    public static function wiredHolders(): array
    {
        return [
            'foreign agent presets' => [
                'src/Agents/ForeignAgentPresetRegistry.php',
                \SugarCraft\Crush\Tests\Agents\ForeignAgentPresetDirContainmentTest::class,
                ['below', 'within'],
            ],
        ];
    }

    /**
     * Every holder of a repository-chosen directory, dormant or wired — the union
     * the gate requirement is actually keyed to.
     *
     * @return array<string, array{0: string, 1: class-string, 2: list<string>}>
     */
    public static function holdersOfARepositoryChosenDirectory(): array
    {
        return [...self::dormantHolders(), ...self::wiredHolders()];
    }

    /**
     * PRESENCE IS NOT ENFORCEMENT, which is the category this class's own
     * doc-block opens by condemning — and this test was an instance of it. It
     * was `assertStringContainsString('ContainedPath::below(', $source)`, which a
     * gate whose RESULT IS DISCARDED satisfies exactly as well as a real one.
     * That is the same defect
     * {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest} measured
     * on `InstructionFileLoader::loadRoot()`: call present, result thrown away,
     * escape fully live, every string-presence assertion green.
     *
     * So each holder must have BOTH gates with their results CONSUMED — decided
     * by the same statement-level rule the inventory uses — and must name a test
     * class that DRIVES them. A gate nobody executes is a gate nobody has
     * checked; the inventory can see that a compare is written and enforcing,
     * and only a behavioural test can see that it is correct.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('holdersOfARepositoryChosenDirectory')]
    public function testEveryHolderOfARepositoryChosenDirectoryIsGated(
        string $relative,
        string $driver,
        array $requiredGates,
    ): void {
        $consumed = $this->consumedContainmentCalls(\dirname(__DIR__, 2) . '/' . $relative);

        foreach ($requiredGates as $gate) {
            $this->assertContains($gate, $consumed, "{$relative} calls ContainedPath::{$gate}() and uses the answer");
        }

        $this->assertTrue(class_exists($driver), "{$relative} names a test class that drives its gates");
        $this->assertNotSame(
            [],
            array_filter(
                get_class_methods($driver),
                static fn (string $method): bool => str_starts_with($method, 'test'),
            ),
            "{$driver} has at least one test",
        );
    }

    /**
     * The names of every `ContainedPath::within()`/`::below()` in $file whose
     * result the enclosing statement CONSUMES.
     *
     * A deliberately small reimplementation of
     * {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest}'s rule
     * rather than a call into it: this file asks a yes/no question per holder,
     * that one derives a whole-tree census, and sharing the machinery would make
     * the census's data providers depend on this class's fixtures. What is
     * shared is the RULE — a call that is the whole of its own expression
     * statement has nowhere to put its answer.
     *
     * @return list<string>
     */
    private function consumedContainmentCalls(string $file): array
    {
        $tokens = [];
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (\is_array($token)
                && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)
            ) {
                continue;
            }

            $tokens[] = $token;
        }

        $consumed = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || $token[0] !== \T_DOUBLE_COLON) {
                continue;
            }

            $subject = $tokens[$i - 1] ?? null;
            $method = $tokens[$i + 1] ?? null;

            if (!\is_array($method) || $method[0] !== \T_STRING
                || !\in_array(strtolower($method[1]), ['within', 'below'], true)
            ) {
                continue;
            }

            if (!\is_array($subject) || !str_ends_with((string) $subject[1], 'ContainedPath')) {
                continue;
            }

            $before = $tokens[$i - 2] ?? null;
            $discarded = $before === null
                || \in_array($before, [';', '{', '}', ':', ')'], true)
                || (\is_array($before) && \in_array($before[0], [\T_OPEN_TAG, \T_ELSE, \T_DO], true));

            if (!$discarded) {
                $consumed[] = strtolower($method[1]);
            }
        }

        return array_values(array_unique($consumed));
    }

    /**
     * Every `.<dir>/<segment>` appearing in a string literal under $src, keyed
     * `<file>|<dot-path>`.
     *
     * KEYED BY THE OCCURRENCE, not by the path — see {@see DOT_PATHS} for the
     * ninth ungated read path the path-keyed version classified as safe. The
     * file is part of the key because the TIER is a property of where the
     * string is used, not of the string.
     *
     * Token-derived rather than grepped: a `.claude/agents` inside a doc-comment
     * is a cross-reference, and an earlier instrument's whole-file concatenation
     * could not tell one from a path the code builds.
     *
     * @return array<string, true> `<file>|<dot-path>` => true, sorted by key
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
                        $found[$relative . '|' . $hit] = true;
                    }
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * The doc-comment immediately preceding $signature, and nothing else in the
     * file — see {@see testEveryRepositoryChosenPathIsNamedWhereTheClaimIsMade()}
     * for why the narrowing is the point. (This cited
     * `testTheFiveRepositoryChosenDirectoryNames()`, a method renamed two rounds
     * ago and gone; a `{@see}` to nothing is the same drift class in miniature.)
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

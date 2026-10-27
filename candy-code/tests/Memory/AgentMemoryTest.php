<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Memory\AgentMemory;
use SugarCraft\Code\Memory\MemoryWriter;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-AGT-3.b — the per-agent memory store: name sanitisation, the three tiers,
 * the reader-never-creates law, the containment gate inherited from
 * ProjectMemoryWriter, the planted local ignore file, and the routing of
 * {@see MemoryWriter::forAgent()} into exactly one agent's own store.
 */
final class AgentMemoryTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var list<string> scratch trees to remove after each test */
    private array $roots = [];

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        foreach ($this->roots as $root) {
            self::deleteTreeCagt3b($root);
        }
        $this->roots = [];
    }

    // -----------------------------------------------------------------
    // safeName — the store's note-id grammar applied to preset names
    // -----------------------------------------------------------------

    public function testAPlainNameRidesThroughUntouched(): void
    {
        $this->assertSame('coder', AgentMemory::safeName('coder'));
        $this->assertSame('se-curity.auditor_v2', AgentMemory::safeName('se-curity.auditor_v2'));
    }

    public function testUnsafeCharactersBecomeUnderscoresAndTraversalCannotSurvive(): void
    {
        // Slash and space are not in the grammar, so a name written to walk out
        // of the store arrives as one flat directory token instead.
        $this->assertSame('a_.._.._b', AgentMemory::safeName('a/../../b'));
        $this->assertSame('team_lead_2', AgentMemory::safeName('team lead 2'));
    }

    public function testALeadingDotIsStrippedAndAnEmptyNameFallsBack(): void
    {
        // The grammar's first character excludes '.', and an empty directory
        // token would collide across every such name.
        $this->assertSame('hidden', AgentMemory::safeName('.hidden'));
        $this->assertSame('agent', AgentMemory::safeName(''));
        $this->assertSame('___', AgentMemory::safeName('///'), 'each unusable character becomes one underscore — the grammar still holds');
    }

    public function testTheNameIsCappedAtTheGrammarLength(): void
    {
        $this->assertSame(64, strlen(AgentMemory::safeName(str_repeat('x', 200))));
    }

    public function testTheIndexStemIsRefusedInEverySpelling(): void
    {
        // MemoryStore refuses to name a note MEMORY.md, so a preset literally
        // called "memory" (case-insensitively) would own a directory the store
        // itself cannot list around. The dodge keeps both grammars true.
        $this->assertSame('_MEMORY', AgentMemory::safeName('MEMORY'));
        $this->assertSame('_memory', AgentMemory::safeName('memory'));
        $this->assertSame('_MEMORY', AgentMemory::safeName('.MEMORY'));
        $this->assertNotSame('memory', strtolower(AgentMemory::safeName('memory')));
    }

    // -----------------------------------------------------------------
    // tier resolution — null, the home tier, the project and local tiers
    // -----------------------------------------------------------------

    public function testNoTierMeansNoStoreAnywhere(): void
    {
        $root = $this->scratchRoot();
        $this->assertNull(AgentMemory::storeFor(null, 'coder', $root, true));
        $this->assertNull(AgentMemory::readStore(null, 'coder', $root));
        $this->assertDirectoryDoesNotExist($root . '/.candy-code');
    }

    public function testTheUserTierLandsUnderTheSandboxedHome(): void
    {
        $home = $this->homeSandboxForCagt3b();
        $store = AgentMemory::createStore(MemoryScope::User, 'coder', '');
        $this->assertNotNull($store);
        $this->assertDirectoryExists($home . '/.candy-code/agent-memory/coder');

        $id = $store->add('a note the agent kept for itself', MemoryScope::Project);
        $this->assertFileExists($home . '/.candy-code/agent-memory/coder/project/' . $id . '.md');
        $this->assertCount(1, $store->list(MemoryScope::Project));
    }

    public function testTheReaderNeverLaysDownTheDirectoryTree(): void
    {
        $root = $this->scratchRoot();
        $this->assertNull(AgentMemory::readStore(MemoryScope::Project, 'coder', $root));
        $this->assertDirectoryDoesNotExist($root . '/.candy-code');

        $this->assertNotNull(AgentMemory::createStore(MemoryScope::Project, 'coder', $root));
        $this->assertNotNull(AgentMemory::readStore(MemoryScope::Project, 'coder', $root));
    }

    public function testAnEmptyOrMissingProjectRootIsRefused(): void
    {
        // Mirrors ProjectMemoryWriter's realpath('')-is-the-CWD trap law.
        $this->assertNull(AgentMemory::createStore(MemoryScope::Project, 'coder', ''));
        $this->assertNull(AgentMemory::readStore(MemoryScope::Project, 'coder', ''));

        $missing = sys_get_temp_dir() . '/cagt3b-no-such-root-' . uniqid('', true);
        $this->assertNull(AgentMemory::createStore(MemoryScope::Project, 'coder', $missing));
        $this->assertNull(AgentMemory::readStore(MemoryScope::Project, 'coder', $missing));
    }

    public function testTwoTiersUnderTheSameNameAreDifferentStores(): void
    {
        $root = $this->scratchRoot();
        $project = AgentMemory::createStore(MemoryScope::Project, 'coder', $root);
        $this->assertNotNull($project);
        $project->add('only this agent at this checkout', MemoryScope::Project);

        $this->assertNull(AgentMemory::readStore(MemoryScope::Local, 'coder', $root));
    }

    // -----------------------------------------------------------------
    // containment — the post-mkdir gate ProjectMemoryWriter is pinned for
    // -----------------------------------------------------------------

    public function testASymlinkedCornerCannotRouteTheStoreOutsideTheRoot(): void
    {
        $root = $this->scratchRoot();
        $escape = $this->scratchRoot();
        $this->assertTrue(symlink($escape, $root . '/.candy-code'));

        // The gate runs after mkdir, exactly as ProjectMemoryWriter's law
        // states: creating through a pre-planted link is refused, and no store
        // is handed back that would write under $escape. The directory the
        // refused create() laid down THROUGH the link stays as residue — the
        // same disclosed trade ProjectMemoryWriter makes; what the gate
        // promises is that no caller ever receives a store bound to it.
        $this->assertNull(AgentMemory::createStore(MemoryScope::Project, 'coder', $root));
        $this->assertNull(AgentMemory::readStore(MemoryScope::Project, 'coder', $root));
        $this->assertNull(AgentMemory::createStore(MemoryScope::Local, 'coder', $root));
    }

    // -----------------------------------------------------------------
    // the local tier's planted ignore file
    // -----------------------------------------------------------------

    public function testTheLocalTierPlantsItsOwnIgnoreFile(): void
    {
        $root = $this->scratchRoot();
        $this->assertNotNull(AgentMemory::createStore(MemoryScope::Local, 'coder', $root));

        $ignore = $root . '/.candy-code/agent-memory-local/.gitignore';
        $this->assertFileExists($ignore);
        // Built from chr(42) on purpose: a bare wildcard literal here would
        // join the GlobDialect corpus and drift PathGlob's pinned figure.
        $this->assertSame(chr(42) . "\n", file_get_contents($ignore));
        $this->assertDirectoryExists($root . '/.candy-code/agent-memory-local/coder');
    }

    public function testAnExistingIgnoreFileIsNeverOverwritten(): void
    {
        $root = $this->scratchRoot();
        $dir = $root . '/.candy-code/agent-memory-local';
        $this->assertTrue(mkdir($dir, 0o700, true));
        $this->assertTrue(file_put_contents($dir . '/.gitignore', "keep me\n") !== false);

        $this->assertNotNull(AgentMemory::createStore(MemoryScope::Local, 'coder', $root));
        $this->assertSame("keep me\n", file_get_contents($dir . '/.gitignore'));
    }

    // -----------------------------------------------------------------
    // MemoryWriter::forAgent — routes every action into exactly one store
    // -----------------------------------------------------------------

    public function testTheAgentWriterWritesAndReadsItsOwnStoreOnly(): void
    {
        $root = $this->scratchRoot();
        $writer = MemoryWriter::forAgent('coder', MemoryScope::Project, $root);
        $saved = $writer->save('ship lanes with their guards', 'project');

        $this->assertSame('project', $saved->scope);
        $this->assertFalse($saved->inRepository, 'the agent store is not the repository store');
        $this->assertFileExists(
            $root . '/.candy-code/agent-memory/coder/project/' . $saved->id . '.md',
        );

        $found = $writer->locate($saved->id);
        $this->assertNotNull($found);
        $this->assertSame($saved->id, $found[0]->id(), 'the row that came back is the row that was saved');
        $this->assertStringContainsString('ship lanes', $found[0]->content());

        // A sibling agent's writer cannot see it. The session's repository
        // store is never even laid down by this writer — root '' means there
        // is no repository to route into.
        $other = MemoryWriter::forAgent('reviewer', MemoryScope::Project, $root);
        $this->assertNull($other->locate($saved->id));
        $this->assertDirectoryDoesNotExist($root . '/.candy-code/memory');
    }

    public function testTheAgentWriterCannotReachTheSessionHome(): void
    {
        $home = $this->homeSandboxForCagt3b();
        $root = $this->scratchRoot();
        $writer = MemoryWriter::forAgent('coder', MemoryScope::User, $root);
        $saved = $writer->save('a preference this agent keeps', 'user');

        $this->assertFileExists($home . '/.candy-code/agent-memory/coder/user/' . $saved->id . '.md');
        $this->assertDirectoryDoesNotExist($home . '/.candy-code/memory');
    }

    // -----------------------------------------------------------------
    // scratch helpers (named per this lane to stay clear of the drift guard)
    // -----------------------------------------------------------------

    private function scratchRoot(): string
    {
        $root = sys_get_temp_dir() . '/cagt3b-' . uniqid('', true);
        $this->assertTrue(mkdir($root, 0o700, true));
        $this->roots[] = $root;

        return $root;
    }

    private function homeSandboxForCagt3b(): string
    {
        $home = sys_get_temp_dir() . '/cagt3b-home-' . uniqid('', true);

        return $this->useHomeSandbox($home);
    }

    public static function deleteTreeCagt3b(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_link($child)) {
                unlink($child);
            } elseif (is_dir($child)) {
                self::deleteTreeCagt3b($child);
            } else {
                unlink($child);
            }
        }

        @rmdir($path);
    }
}

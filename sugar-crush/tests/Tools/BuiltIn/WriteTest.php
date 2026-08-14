<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Agents\PathJailConfig;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Crush\Tools\ParallelSafe;

/**
 * @see Write
 */
final class WriteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath((string) sys_get_temp_dir()) . '/sugarcrush_write_' . uniqid();
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    // =========================================================================
    // The case Edit structurally cannot do: creating a file
    // =========================================================================

    public function testCreatesANewFileWithTheGivenContent(): void
    {
        $tool = new Write($this->root);

        $result = $tool->execute([
            'id' => 'call_1',
            'file_path' => 'notes.md',
            'content' => "hello\nworld\n",
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('call_1', $result->toolCallId());
        $this->assertSame("hello\nworld\n", file_get_contents($this->root . '/notes.md'));
        $this->assertStringContainsString('File created', $result->content());
    }

    /**
     * The exact call Edit rejects, pinned side by side so the two tools'
     * division of labour cannot silently drift.
     */
    public function testEditStillRefusesTheNewFileCaseThisToolExistsToCover(): void
    {
        $path = $this->root . '/fresh.txt';

        $edit = (new Edit($this->root))->execute([
            'file_path' => $path,
            'old_string' => 'x',
            'new_string' => 'y',
        ]);
        $this->assertTrue($edit->isError());
        $this->assertStringContainsString('not found', $edit->content());

        $write = (new Write($this->root))->execute(['file_path' => $path, 'content' => 'y']);
        $this->assertFalse($write->isError());
        $this->assertSame('y', file_get_contents($path));
    }

    public function testCreatesMissingParentDirectories(): void
    {
        $tool = new Write($this->root);

        $result = $tool->execute([
            'file_path' => 'a/b/c/deep.txt',
            'content' => 'deep',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('deep', file_get_contents($this->root . '/a/b/c/deep.txt'));
    }

    public function testWritingAnEmptyFileIsAllowed(): void
    {
        $result = (new Write($this->root))->execute(['file_path' => 'empty.txt', 'content' => '']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertTrue(is_file($this->root . '/empty.txt'));
        $this->assertSame('', file_get_contents($this->root . '/empty.txt'));
        // Nothing changed between the empty "before" and the empty "after", so
        // there is genuinely no diff to show.
        $this->assertFalse($result->hasDiff());
    }

    // =========================================================================
    // Diff against empty content - the reason this is not a Bash heredoc
    // =========================================================================

    public function testProducesAUnifiedDiffAgainstEmptyContentForANewFile(): void
    {
        $result = (new Write($this->root))->execute([
            'file_path' => 'new.txt',
            'content' => "alpha\nbeta\n",
        ]);

        $diff = (string) $result->diff();
        $this->assertTrue($result->hasDiff());
        $this->assertStringContainsString('--- a/' . $this->root . '/new.txt', $diff);
        $this->assertStringContainsString('+++ b/' . $this->root . '/new.txt', $diff);
        // `diff -u` anchors an empty old side at line 0.
        $this->assertStringContainsString("@@ -0,0 +1,2 @@\n+alpha\n+beta\n", $diff);
        $this->assertStringNotContainsString('-alpha', $diff);
    }

    public function testDiffRidesItsOwnFieldAndIsNotConcatenatedIntoTheSummary(): void
    {
        $result = (new Write($this->root))->execute([
            'file_path' => 'summary.txt',
            'content' => "line\n",
        ]);

        $this->assertStringNotContainsString('@@', $result->content());
        $this->assertStringNotContainsString('--- a/', $result->content());
    }

    /**
     * Reuse, not reimplementation: the trait extracted out of Edit has to be
     * the code producing both diffs, so the two tools cannot drift apart.
     */
    public function testWriteAndEditShareOneDiffImplementation(): void
    {
        $shared = \SugarCraft\Crush\Tools\Concerns\BuildsUnifiedDiff::class;

        $this->assertContains($shared, class_uses(Write::class));
        $this->assertContains($shared, class_uses(Edit::class));
    }

    // =========================================================================
    // Overwrite semantics - refuse by default, explicit opt-in
    // =========================================================================

    public function testRefusesToClobberAnExistingFileByDefault(): void
    {
        file_put_contents($this->root . '/kept.txt', 'precious');

        $result = (new Write($this->root))->execute([
            'file_path' => 'kept.txt',
            'content' => 'destroyed',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('already exists', $result->content());
        // The error must name both escape hatches, or the model retries blind.
        $this->assertStringContainsString('Edit', $result->content());
        $this->assertStringContainsString('overwrite', $result->content());
        $this->assertSame('precious', file_get_contents($this->root . '/kept.txt'));
    }

    public function testOverwritesWhenExplicitlyAskedAndDiffsAgainstTheOldContents(): void
    {
        file_put_contents($this->root . '/kept.txt', "old line\n");

        $result = (new Write($this->root))->execute([
            'file_path' => 'kept.txt',
            'content' => "new line\n",
            'overwrite' => true,
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame("new line\n", file_get_contents($this->root . '/kept.txt'));
        $this->assertStringContainsString('File overwritten', $result->content());
        // An overwrite diffs against what was really there, not against empty.
        $this->assertStringContainsString('-old line', (string) $result->diff());
        $this->assertStringContainsString('+new line', (string) $result->diff());
    }

    public function testRefusesToWriteOverADirectory(): void
    {
        mkdir($this->root . '/adir');

        $result = (new Write($this->root))->execute(['file_path' => 'adir', 'content' => 'x']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('directory', $result->content());
        $this->assertTrue(is_dir($this->root . '/adir'));
    }

    // =========================================================================
    // Path jail
    // =========================================================================

    public function testRejectsAnAbsolutePathOutsideTheWorkspaceRoot(): void
    {
        $outside = (string) realpath((string) sys_get_temp_dir()) . '/sugarcrush_write_escape_' . uniqid() . '.txt';

        $result = (new Write($this->root))->execute(['file_path' => $outside, 'content' => 'pwned']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside workspace root', $result->content());
        $this->assertFalse(is_file($outside), 'the escaping path must not have been created');
    }

    /**
     * The escape that only shows up once missing parents are allowed: a `..`
     * hop laundered through a directory that does not exist yet, which no
     * realpath() call can resolve. Containment is settled lexically first.
     */
    public function testRejectsTraversalThroughANonexistentDirectory(): void
    {
        $result = (new Write($this->root))->execute([
            'file_path' => 'nope/../../escaped.txt',
            'content' => 'pwned',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside workspace root', $result->content());
        $this->assertFalse(is_file(\dirname($this->root) . '/escaped.txt'));
    }

    public function testRejectsAPathEscapingThroughASymlinkedDirectory(): void
    {
        $outsideDir = \dirname($this->root) . '/sugarcrush_write_out_' . uniqid();
        mkdir($outsideDir, 0o777, true);
        symlink($outsideDir, $this->root . '/link');

        try {
            $result = (new Write($this->root))->execute([
                'file_path' => 'link/pwned.txt',
                'content' => 'pwned',
            ]);

            $this->assertTrue($result->isError());
            $this->assertFalse(is_file($outsideDir . '/pwned.txt'));
        } finally {
            unlink($this->root . '/link');
            $this->rrmdir($outsideDir);
        }
    }

    /** With no root configured the tool is unjailed, matching Edit/Read. */
    public function testWritesAnywhereWhenNoRootIsConfigured(): void
    {
        $path = $this->root . '/unjailed.txt';

        $result = (new Write())->execute(['file_path' => $path, 'content' => 'ok']);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('ok', file_get_contents($path));
    }

    // =========================================================================
    // Worktree jail - isAllowed() answers false for every not-yet-existing file
    // =========================================================================

    public function testCreatesANewFileInsideAWorktreeJail(): void
    {
        $jail = new AgentPathJail($this->root, new PathJailConfig());

        $result = (new Write(worktreeJail: $jail))->execute([
            'file_path' => 'sub/created.txt',
            'content' => 'in-worktree',
        ]);

        // Pre-fix this failed: PathJail::isAllowed() realpath()s the target,
        // which does not exist yet, so every new-file write read as an escape.
        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('in-worktree', file_get_contents($this->root . '/sub/created.txt'));
    }

    public function testRejectsAWriteOutsideTheWorktreeJail(): void
    {
        $jail = new AgentPathJail($this->root, new PathJailConfig());
        $outside = \dirname($this->root) . '/sugarcrush_write_wt_escape_' . uniqid() . '.txt';

        $result = (new Write(worktreeJail: $jail))->execute([
            'file_path' => $outside,
            'content' => 'pwned',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside worktree', $result->content());
        $this->assertFalse(is_file($outside));
    }

    // =========================================================================
    // Schema / interface contract
    // =========================================================================

    public function testSchemaDeclaresOnlyValidJsonSchemaPrimitives(): void
    {
        $schema = (new Write())->inputSchema();
        $valid = ['string', 'number', 'integer', 'boolean', 'object', 'array', 'null'];

        $this->assertSame('object', $schema['type']);
        foreach ($schema['properties'] as $name => $property) {
            $this->assertContains(
                $property['type'],
                $valid,
                "Write.$name declares '{$property['type']}', which is not a JSON-Schema primitive",
            );
        }
        // Spelled `boolean`, never PHP's `bool` - a strict guided-decoding
        // backend rejects the whole request over one bad type.
        $this->assertSame('boolean', $schema['properties']['overwrite']['type']);
    }

    public function testSchemaRequiresPathContentAndAHumanReadableDescription(): void
    {
        $schema = (new Write())->inputSchema();

        $this->assertSame(['file_path', 'content', 'description'], $schema['required']);
        $this->assertStringContainsString(
            'active voice',
            $schema['properties']['description']['description'],
        );
        // Optional, so a model that never wants to clobber need not send it.
        $this->assertNotContains('overwrite', $schema['required']);
    }

    public function testNameAndDescriptionSteerTheModelAwayFromClobbering(): void
    {
        $tool = new Write();

        $this->assertSame('Write', $tool->name());
        $this->assertStringContainsString('Edit', $tool->description());
        $this->assertStringContainsString('overwrite', $tool->description());
    }

    /**
     * A mutating tool must never join a concurrent group: a forked child
     * orphaned by a cancelled turn has no deadline left to stop it, so it could
     * still land a file the user never approved.
     */
    public function testIsNotParallelSafe(): void
    {
        $this->assertNotInstanceOf(ParallelSafe::class, new Write());
    }

    public function testMissingArgumentsAreReportedNotFatal(): void
    {
        $result = (new Write($this->root))->execute([]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('file_path', $result->content());
    }

    // =========================================================================
    // Nested-instruction wiring, matching Edit
    // =========================================================================

    public function testSurfacesANestedInstructionFileWithoutMixingItIntoTheWrittenBytes(): void
    {
        mkdir($this->root . '/pkg');
        file_put_contents($this->root . '/pkg/CLAUDE.md', 'PKG RULES');

        $result = (new Write($this->root, instructionLoader: new InstructionFileLoader($this->root)))->execute([
            'file_path' => 'pkg/thing.php',
            'content' => "<?php\n",
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertStringContainsString('PKG RULES', $result->content());
        // The on-disk file must be exactly what was asked for - the instruction
        // text is informational only (the bug Edit already had to fix once).
        $this->assertSame("<?php\n", file_get_contents($this->root . '/pkg/thing.php'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
        rmdir($dir);
    }
}

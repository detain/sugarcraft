<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\ForeignMemoryImporter;
use SugarCraft\Crush\Memory\MemoryStore;

/**
 * The THIRD ungated repository-chosen reader found in the same sweep as
 * {@see \SugarCraft\Crush\Tests\Agents\ForeignAgentPresetDirContainmentTest}:
 * `{projectRoot}/.opencode/memory`, read by
 * {@see ForeignMemoryImporter::importOpencode()} with no containment of any
 * kind, one committed `.opencode/memory -> <outside>` away from importing
 * arbitrary files into this session's memory store under a `source:opencode`
 * tag asserting they came from the project.
 *
 * DORMANT — nothing in `src/` or `bin/` constructs the importer — which is why
 * it is gated here rather than removed, and why it is gated NOW rather than
 * when `/memory import opencode` lands in `Chat.php`.
 *
 * FIXTURES LIVE OUTSIDE ANY CHECKOUT. The escape is only expressible as a
 * symlink, and a symlink out of a repository must never be committed into one.
 */
final class ForeignMemoryImporterContainmentTest extends TestCase
{
    private const SECRET = 'MEMORY-ESCAPE-BODY sk-live-C0FFEE';

    private string $sandbox;
    private string $project;
    private string $outside;
    private MemoryStore $store;
    private ForeignMemoryImporter $importer;
    private string|false $originalHome;
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/foreign_memory_containment_' . uniqid('', true);
        $this->project = $this->sandbox . '/repo';
        $this->outside = $this->sandbox . '/outside';

        mkdir($this->project . '/.opencode', 0o777, true);
        mkdir($this->outside, 0o777, true);
        mkdir($this->sandbox . '/store', 0o777, true);
        mkdir($this->sandbox . '/home', 0o700, true);

        file_put_contents($this->outside . '/private.md', self::SECRET . "\n");

        // importClaudeCode()'s default `~/.claude` lookup must not reach the
        // developer's real memory tree.
        $this->originalHome = getenv('HOME');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->sandbox . '/home');
        $_SERVER['HOME'] = $this->sandbox . '/home';

        $this->store = new MemoryStore($this->sandbox . '/store');
        $this->importer = new ForeignMemoryImporter($this->store);
    }

    protected function tearDown(): void
    {
        $this->originalHome === false ? putenv('HOME') : putenv('HOME=' . $this->originalHome);
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeTree($this->sandbox);

        parent::tearDown();
    }

    /** `is_link()` first — see the sibling containment suites for why. */
    private function removeTree(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            if (is_link($dir) || is_file($dir)) {
                unlink($dir);
            }

            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $this->removeTree($dir . '/' . $entry);
        }

        rmdir($dir);
    }

    /**
     * Every byte the importer put in the store, read off DISK rather than
     * through `list()`: the assertions here are about which BODIES landed, and
     * `MemoryEntry`'s properties do not survive `json_encode()`.
     */
    private function storedText(): string
    {
        $text = '';
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandbox . '/store', \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile()) {
                $text .= (string) file_get_contents($file->getPathname());
            }
        }

        return $text;
    }

    public function testAMemoryDirectorySymlinkedOutOfTheCheckoutImportsNothing(): void
    {
        symlink($this->outside, $this->project . '/.opencode/memory');

        $this->assertSame(0, $this->importer->importOpencode($this->project));
        $this->assertStringNotContainsString(self::SECRET, $this->storedText());
        $this->assertArrayHasKey(
            $this->project . '/.opencode/memory',
            $this->importer->refusedDirectories(),
        );
    }

    /** `.opencode/memory -> ..` resolves onto the checkout root, which is not a memory tree. */
    public function testAMemoryDirectoryResolvingOntoTheCheckoutRootIsRefused(): void
    {
        file_put_contents($this->project . '/notes.md', self::SECRET . "\n");
        symlink($this->project, $this->project . '/.opencode/memory');

        $this->assertSame(0, $this->importer->importOpencode($this->project));
        $this->assertStringContainsString(
            'which is exactly',
            implode("\n", $this->importer->refusedDirectories()),
        );
    }

    /**
     * The second boundary: the directory is contained, an ENTRY inside it need
     * not be — and the refusal costs only that entry.
     */
    public function testAMemoryFileSymlinkedOutOfAContainedDirectoryIsRefused(): void
    {
        mkdir($this->project . '/.opencode/memory');
        file_put_contents($this->project . '/.opencode/memory/real.md', "REAL-NOTE\n");
        symlink($this->outside . '/private.md', $this->project . '/.opencode/memory/link.md');

        $this->assertSame(1, $this->importer->importOpencode($this->project));

        $stored = $this->storedText();
        $this->assertStringContainsString('REAL-NOTE', $stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertArrayHasKey(
            $this->project . '/.opencode/memory/link.md',
            $this->importer->refusedDirectories(),
        );
    }

    /** A symlink is not the defect; leaving the checkout is. */
    public function testAMemoryDirectoryLinkedElsewhereInsideTheCheckoutStillImports(): void
    {
        mkdir($this->project . '/shared-notes');
        file_put_contents($this->project . '/shared-notes/vendored.md', "VENDORED-NOTE\n");
        symlink($this->project . '/shared-notes', $this->project . '/.opencode/memory');

        $this->assertSame(1, $this->importer->importOpencode($this->project));
        $this->assertStringContainsString('VENDORED-NOTE', $this->storedText());
        $this->assertSame([], $this->importer->refusedDirectories());
    }

    /**
     * A project with no `.opencode/memory` at all — the overwhelmingly common
     * case — reports NOTHING. The refusal notice is deliberately narrower than
     * the decision, matching
     * {@see \SugarCraft\Crush\Agents\AgentPresetRegistry::readableSearchPaths()}.
     */
    public function testAnAbsentMemoryDirectoryIsNotReportedAsARefusal(): void
    {
        $this->assertSame(0, $this->importer->importOpencode($this->project));
        $this->assertSame([], $this->importer->refusedDirectories());
    }

    /**
     * The entry boundary applies to the CLAUDE tier too, even though that
     * directory's location is the user's rather than a repository's: `glob()`
     * does not resolve symlinks either way.
     */
    public function testAClaudeMemoryEntrySymlinkedOutOfItsDirectoryIsRefused(): void
    {
        $slug = '-' . ltrim(str_replace('/', '-', $this->project), '-');
        $dir = $this->sandbox . '/claude/projects/' . $slug . '/memory';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/real.md', "---\ndescription: real\n---\nREAL-CLAUDE-NOTE\n");
        file_put_contents($this->outside . '/framed.md', "---\ndescription: stolen\n---\n" . self::SECRET . "\n");
        symlink($this->outside . '/framed.md', $dir . '/link.md');

        $this->assertSame(1, $this->importer->importClaudeCode($this->project, $this->sandbox . '/claude'));

        $stored = $this->storedText();
        $this->assertStringContainsString('REAL-CLAUDE-NOTE', $stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);
    }

    /** Refusals belong to a CALL, not to an object. */
    public function testRefusalsDoNotOutliveTheConditionThatCausedThem(): void
    {
        symlink($this->outside, $this->project . '/.opencode/memory');

        $this->importer->importOpencode($this->project);
        $this->assertNotSame([], $this->importer->refusedDirectories());

        unlink($this->project . '/.opencode/memory');
        $this->importer->importOpencode($this->project);

        $this->assertSame([], $this->importer->refusedDirectories());
    }
}

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

    // ─── the CLAUDE tier's home ─────────────────────────────────────

    /**
     * THE USER TIER, which stayed on {@see \SugarCraft\Crush\Support\HomeDirectory::path()}
     * through the very commit that gated its sibling
     * ({@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::userDir()}).
     *
     * The per-ENTRY gate above does not help here and that is the whole point:
     * when the entire home is attacker-writable, every entry the attacker plants
     * resolves neatly INSIDE the directory it was listed from. MEASURED against
     * the build that read `path()`, with `HOME` at a mode-1777 directory:
     * `imported=1`, `refusedDirectories()=[]`, and the body entering the store
     * tagged `source:claude` — while `HomeDirectory::owned()` returned NULL for
     * that same home.
     */
    public function testAWorldWritableHomeImportsNothingFromTheClaudeTier(): void
    {
        $home = $this->sandbox . '/home';
        chmod($home, 0o1777);

        $slug = '-' . ltrim(str_replace('/', '-', $this->project), '-');
        $dir = $home . '/.claude/projects/' . $slug . '/memory';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/planted.md', "---\ndescription: planted\n---\n" . self::SECRET . "\n");

        try {
            $this->assertSame(0, $this->importer->importClaudeCode($this->project));
            $this->assertStringNotContainsString(self::SECRET, $this->storedText());

            // Refused, not merely empty — the distinction refusedDirectories()
            // exists to make.
            $this->assertNotSame([], $this->importer->refusedDirectories());
        } finally {
            chmod($home, 0o700);
        }
    }

    /**
     * THE CONTROL. Without it the assertion above is satisfied by a Claude tier
     * that simply stopped working: the same tree under an OWNED home still
     * imports.
     */
    public function testAnOwnedHomeStillImportsTheClaudeTier(): void
    {
        $home = $this->sandbox . '/home';
        $slug = '-' . ltrim(str_replace('/', '-', $this->project), '-');
        $dir = $home . '/.claude/projects/' . $slug . '/memory';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/note.md', "---\ndescription: mine\n---\nOWNED-HOME-NOTE\n");

        $this->assertSame(1, $this->importer->importClaudeCode($this->project));
        $this->assertStringContainsString('OWNED-HOME-NOTE', $this->storedText());
        $this->assertSame([], $this->importer->refusedDirectories());
    }

    /**
     * An EXPLICIT `$claudeHome` is the caller naming a directory rather than
     * this class deriving one, so it is not gated — pinned so the gate above
     * cannot silently grow into the parameter and break every test and
     * non-default install that passes one.
     */
    public function testAnExplicitClaudeHomeIsNotGatedByTheOwnershipCheck(): void
    {
        $home = $this->sandbox . '/home';
        chmod($home, 0o1777);

        $slug = '-' . ltrim(str_replace('/', '-', $this->project), '-');
        $dir = $this->sandbox . '/claude/projects/' . $slug . '/memory';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/note.md', "---\ndescription: explicit\n---\nEXPLICIT-HOME-NOTE\n");

        try {
            $this->assertSame(1, $this->importer->importClaudeCode($this->project, $this->sandbox . '/claude'));
            $this->assertStringContainsString('EXPLICIT-HOME-NOTE', $this->storedText());
        } finally {
            chmod($home, 0o700);
        }
    }
}

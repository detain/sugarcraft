<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Memory\ForeignMemoryImporter;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * Every markdown-frontmatter reader in this package, driven end to end with a
 * colon-bearing `description:`.
 *
 * WHY THEY LIVE TOGETHER. {@see \SugarCraft\Crush\Support\Frontmatter} is one
 * class with seven callers, and the interesting claim is about the SET: that
 * no reader was left on the strict parse. Split across seven files that claim
 * is unfalsifiable -- the eighth caller someone adds next year fails no test
 * anywhere. Here it is one list, next to the helper it exercises.
 *
 * ACTIVE VERSUS LATENT, measured on this machine the day these were written.
 * Only the skill readers were dropping real files: six SKILL.md on this box,
 * four in this repository and two outside it. A survey of the machine's actual
 * agent-preset and slash-command frontmatter -- 34 files across
 * `.opencode/agents`, `.sugar-crush/agents`, `.opencode/commands`,
 * `~/.config/opencode/command` and `~/.claude/commands` -- found ZERO
 * currently rejected, because those descriptions are terse while a skill's is
 * long prose that reaches for a colon. Those sites shared the defect and now
 * share the fix, but nothing of the user's was being lost through them yet.
 * That makes these fixtures the ONLY evidence those readers were ever broken,
 * and the only thing standing between here and the first person who writes a
 * normal prose description into a preset or a command.
 */
final class FrontmatterCallSitesTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /** The colon after "touchpoint" is the entire defect. */
    private const DESCRIPTION = "Scaffolds a port end-to-end across every touchpoint: creates the user's composer.json.";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sc_fm_sites_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testSkillFromFileKeepsTheDescriptionWhole(): void
    {
        $path = $this->write('skills/probe-skill/SKILL.md', $this->frontmatter('probe-skill'));

        $this->assertSame(self::DESCRIPTION, Skill::fromFile($path)->description);
    }

    public function testSkillLoaderManifestKeepsTheDescriptionWhole(): void
    {
        $this->write('skills/probe-skill/SKILL.md', $this->frontmatter('probe-skill'));

        $manifest = (new SkillLoader())->loadSkillManifest($this->root . '/skills/probe-skill');

        $this->assertSame(self::DESCRIPTION, $manifest['description']);
    }

    /**
     * The skip recorder is the assertion that matters here: before the shared
     * reader, this directory produced zero skills and one recorded skip, which
     * is exactly what the six real SKILL.md files were doing at launch.
     */
    public function testSkillLoaderLoadsTheDirectoryWithoutRecordingASkip(): void
    {
        $this->write('skills/probe-skill/SKILL.md', $this->frontmatter('probe-skill'));

        $loader = new SkillLoader(false);
        $skills = $loader->loadFromDirectory($this->root . '/skills');

        $this->assertSame([], $loader->skipped());
        $this->assertArrayHasKey('probe-skill', $skills);
        $this->assertSame(self::DESCRIPTION, $skills['probe-skill']->description);
    }

    /**
     * Latent case, pinned: no preset on this machine carries a colon in its
     * description today, so nothing but this fixture would notice the reader
     * going back to a strict parse.
     */
    public function testAgentPresetRegistryKeepsTheDescriptionWhole(): void
    {
        $this->write('agents/probe.md', $this->frontmatter('probe'));

        $preset = (new AgentPresetRegistry([$this->root . '/agents']))->load('probe');

        $this->assertSame(self::DESCRIPTION, $preset->description);
    }

    /** Latent case, pinned -- see {@see testAgentPresetRegistryKeepsTheDescriptionWhole()}. */
    public function testForeignClaudeAgentDiscoveryKeepsTheDescriptionWhole(): void
    {
        $this->write('proj/.claude/agents/probe.md', $this->frontmatter('probe'));

        $presets = (new ForeignAgentPresetRegistry())->discoverClaude($this->root . '/proj');

        $this->assertArrayHasKey('probe', $presets);
        $this->assertSame(self::DESCRIPTION, $presets['probe']->description);
    }

    /** Latent case, pinned -- no slash command on this machine reaches for a colon yet. */
    public function testCommandSpecKeepsTheDescriptionWhole(): void
    {
        $path = $this->write('commands/probe.md', $this->frontmatter('probe'));

        $this->assertSame(self::DESCRIPTION, CommandSpec::fromFile($path, 'probe')->description);
    }

    /**
     * Claude Code's own memory entries. The description is what the importer
     * writes as the entry's first line, so a rejected block was not a degraded
     * import -- it was no import at all.
     */
    public function testForeignMemoryImporterImportsAColonBearingEntry(): void
    {
        $store = new MemoryStore($this->mkdir('mem'));

        $projectRoot = $this->root . '/pr';
        $slug = '-' . ltrim(str_replace('/', '-', $projectRoot), '-');
        $this->write("claudehome/projects/$slug/memory/entry.md", $this->frontmatter('entry'));

        $imported = (new ForeignMemoryImporter($store))->importClaudeCode($projectRoot, $this->root . '/claudehome');

        $this->assertSame(1, $imported);

        // MemoryScope::Local, not the string 'local': normalizeScope() maps
        // the enum case onto the on-disk scope named 'agent' on purpose, and
        // the raw string is passed through unchanged. See MemoryStore's
        // class doc-block.
        $entries = $store->list(MemoryScope::Local);
        $this->assertCount(1, $entries);
        $this->assertStringStartsWith(self::DESCRIPTION, $entries[array_key_first($entries)]->content());
    }

    /**
     * MemoryStore writes its own frontmatter, so its blocks are well-formed by
     * construction -- until a foreign key rides in on one, which is exactly
     * what {@see ForeignMemoryImporter} is for. A rejected block made the whole
     * entry vanish from `get()`, silently, because parseEntry() answers null.
     */
    public function testMemoryStoreReadsBackAnEntryCarryingAColonBearingKey(): void
    {
        $store = new MemoryStore($this->mkdir('mem2'));
        $id = $store->add('MEMORY BODY', 'user', ['t']);

        $file = $this->root . '/mem2/user/' . $id . '.md';
        $raw = (string) file_get_contents($file);
        file_put_contents($file, preg_replace('/^---\n/', "---\ndescription: " . self::DESCRIPTION . "\n", $raw, 1));

        $this->assertSame('MEMORY BODY', $store->get($id)?->content());
    }

    private function frontmatter(string $name): string
    {
        return "---\nname: {$name}\ndescription: " . self::DESCRIPTION . "\n---\n\nBody prose for {$name}.\n";
    }

    private function mkdir(string $relative): string
    {
        $path = $this->root . '/' . $relative;
        mkdir($path, 0o700, true);

        return $path;
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o700, true);
        file_put_contents($path, $contents);

        return $path;
    }
}

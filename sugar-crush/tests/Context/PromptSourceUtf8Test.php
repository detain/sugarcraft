<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Context\RepoMapBlock;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Memory\ForeignMemoryImporter;
use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillPromptLine;

/**
 * Every loader whose output reaches the system prompt hands back valid UTF-8,
 * whatever encoding the file on disk was written in (audit 15d-08).
 *
 * The failure each test guards: the system prompt is JSON-encoded into every
 * provider request through Guzzle, which THROWS on malformed UTF-8, so one
 * Latin-1 byte in any of these sources failed every turn of the session. Each
 * test plants `\xe9` (Latin-1 `é`, invalid as UTF-8) beside a canary and
 * asserts three things: the loader's output is valid UTF-8, the text around
 * the bad byte (the canary) survived, and — where the source is big enough to
 * carry one — the `[encoding: …]` notice names the file.
 *
 * The end-to-end proof through the real assembler is
 * {@see \SugarCraft\Crush\Tests\BaseSystemPromptTest::testANonUtf8InstructionFileStillProducesAnEncodableRequest()}.
 */
final class PromptSourceUtf8Test extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir() . '/crush_utf8_sources_' . bin2hex(random_bytes(6));
        mkdir($this->scratch . '/repo', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->dropScratchTree($this->scratch);
        parent::tearDown();
    }

    public function testARootInstructionFileIsScrubbedAndNamed(): void
    {
        $this->put('repo/CLAUDE.md', "# Legacy\n\nCaf\xe9 au lait ROOTCANARY\n");

        $docs = (new InstructionFileLoader($this->scratch . '/repo'))->loadRoot();

        self::assertCount(1, $docs);
        $this->assertEncodable($docs[0]);
        self::assertStringContainsString('Caf? au lait ROOTCANARY', $docs[0]);
        self::assertStringContainsString('[encoding: 1 byte sequence(s) of CLAUDE.md were not valid UTF-8', $docs[0]);
    }

    public function testAnImportedFileIsScrubbedOnItsOwnBytesAndNamedByItsReference(): void
    {
        $this->put('repo/CLAUDE.md', "# Root\n\nSee @./legacy.md for more.\n");
        $this->put('repo/legacy.md', "Imported na\xefve IMPORTCANARY\n");

        $docs = (new InstructionFileLoader($this->scratch . '/repo'))->loadRoot();

        self::assertCount(1, $docs);
        $this->assertEncodable($docs[0]);
        self::assertStringContainsString('Imported na?ve IMPORTCANARY', $docs[0]);
        self::assertStringContainsString('of ./legacy.md were not valid UTF-8', $docs[0]);
        self::assertStringNotContainsString('of CLAUDE.md were', $docs[0], 'the root file was valid; only the import is named');
    }

    public function testAForcedInstructionMatchIsScrubbedAndNamed(): void
    {
        // A forced pattern may match a binary as easily as a document.
        $this->put('repo/LEGACY.md', "Forced \xff\xfe FORCEDCANARY\n");

        $docs = (new InstructionFileLoader($this->scratch . '/repo', ['LEGACY.md']))->loadForced();

        self::assertCount(1, $docs);
        $this->assertEncodable($docs[0]);
        self::assertStringContainsString('FORCEDCANARY', $docs[0]);
        self::assertStringContainsString('[encoding: 2 byte sequence(s) of LEGACY.md were', $docs[0]);
    }

    public function testANestedInstructionFileIsScrubbedAndNamed(): void
    {
        $this->put('repo/sub/CLAUDE.md', "Nested caf\xe9 NESTEDCANARY\n");
        $this->put('repo/sub/x.php', "<?php\n");

        $doc = (new InstructionFileLoader($this->scratch . '/repo'))->loadForPath($this->scratch . '/repo/sub/x.php');

        self::assertIsString($doc);
        $this->assertEncodable($doc);
        self::assertStringContainsString('Nested caf? NESTEDCANARY', $doc);
        self::assertStringContainsString('of sub/CLAUDE.md were not valid UTF-8', $doc);
    }

    public function testAProjectRuleIsLoadedNotSkippedAndItsBodyIsScrubbed(): void
    {
        $this->put('repo/.sugar-crush/rules/legacy.md', "---\nname: caf\xe9-rule\n---\nRule caf\xe9 RULECANARY\n");

        $loader = new RuleLoader($this->scratch . '/repo', reportRefusals: false);
        $rules = $loader->loadProjectRules();

        self::assertSame([], $loader->skippedFiles(), 'a Latin-1 byte must not cost the whole rule');
        self::assertCount(1, $rules);
        $this->assertEncodable($rules[0]->name);
        $this->assertEncodable($rules[0]->body);
        self::assertStringContainsString('Rule caf? RULECANARY', $rules[0]->body);
        self::assertStringContainsString('of rules file legacy.md were not valid UTF-8', $rules[0]->body);
    }

    public function testASkillLoadedEagerlyHasAnEncodableDescriptionAndBody(): void
    {
        $path = $this->put('skills/legacy/SKILL.md', "---\ndescription: Caf\xe9 helper DESCCANARY\n---\nBody caf\xe9 SKILLCANARY\n");

        $skill = Skill::fromFile($path);

        $this->assertEncodable($skill->description);
        self::assertSame('Caf? helper DESCCANARY', $skill->description);
        $this->assertEncodable($skill->content);
        self::assertStringContainsString('Body caf? SKILLCANARY', $skill->content);
        self::assertStringContainsString('of the SKILL.md of skill "legacy" were not valid UTF-8', $skill->content);
        $this->assertEncodable($skill->systemPromptContribution());
    }

    public function testTheLazySkillManifestAndBodyMatchTheEagerLoad(): void
    {
        $path = $this->put('skills/legacy/SKILL.md', "---\ndescription: Caf\xe9 helper DESCCANARY\n---\nBody caf\xe9 SKILLCANARY\n");
        $loader = new SkillLoader(reportSkips: false);

        $manifest = $loader->loadSkillManifest(\dirname($path));
        $body = $loader->loadSkillBody($path);

        self::assertSame('Caf? helper DESCCANARY', $manifest['description']);
        $this->assertEncodable($body);
        self::assertSame(Skill::fromFile($path)->content, $body, 'Stage 2 must hand the model the bytes the eager load does');
    }

    public function testASkillListingLineIsEncodableEvenWhenTheNameIsNot(): void
    {
        // The name is the skill's directory name, which no loader rewrites.
        $skill = Skill::parse("---\ndescription: ok\n---\nbody\n", "caf\xe9");

        $line = SkillPromptLine::render($skill, SkillPromptLine::LISTING_MAX_BYTES);

        $this->assertEncodable($line);
        self::assertSame('- caf?: ok', $line);
    }

    public function testAMemoryNoteIsReadNotSkippedAndItsContentIsScrubbed(): void
    {
        mkdir($this->scratch . '/memory', 0o700, true);
        $this->put('memory/project/latin.md', "---\nid: latin-note\ntype: pattern\nscope: project\n---\nNote caf\xe9 MEMCANARY\n");
        $store = new MemoryStore($this->scratch . '/memory');

        $entries = $store->list(MemoryScope::Project);

        self::assertSame([], $store->skipped(), 'a Latin-1 byte must not cost the whole note');
        self::assertCount(1, $entries);
        self::assertSame('Note caf? MEMCANARY', $entries[0]->content());
    }

    public function testAMemoryEntryWithInvalidContentStillRendersItsText(): void
    {
        // MemoryBlock's own backstop: an entry that reached it without passing
        // through MemoryStore's read used to render as an empty `- [pattern] `.
        $entry = MemoryEntry::new(type: 'pattern', content: "Direct caf\xe9 BLOCKCANARY", scope: 'project', tags: [], id: 'direct');
        // MemoryStore is final and scrubs on read, so the only way to hand the
        // block such an entry is its own (private) constructor.
        $class = new \ReflectionClass(MemoryBlock::class);
        $block = $class->newInstanceWithoutConstructor();
        $class->getConstructor()?->invoke($block, [$entry]);

        $rendered = $block->render();

        $this->assertEncodable($rendered);
        self::assertStringContainsString('- [pattern] Direct caf? BLOCKCANARY', $rendered);
    }

    public function testARepoMapPackageWithALatin1DescriptionIsMappedNotDropped(): void
    {
        $this->put('repo/pkg/composer.json', "{\"name\":\"acme/pkg\",\"description\":\"Caf\xe9 tools REPOCANARY\"}");

        $rendered = RepoMapBlock::capture($this->scratch . '/repo')->render();

        $this->assertEncodable($rendered);
        self::assertStringContainsString('- pkg/  Caf? tools REPOCANARY', $rendered);
    }

    public function testAnImportedForeignMemoryFileIsStoredAsValidUtf8(): void
    {
        mkdir($this->scratch . '/memory', 0o700, true);
        $this->put('repo/.opencode/memory/legacy.md', "Foreign caf\xe9 FOREIGNCANARY\n");
        $store = new MemoryStore($this->scratch . '/memory');

        self::assertSame(1, (new ForeignMemoryImporter($store))->importOpencode($this->scratch . '/repo'));

        $entries = $store->list(MemoryScope::Local);
        self::assertCount(1, $entries);
        $this->assertEncodable($entries[0]->content());
        self::assertStringContainsString('Foreign caf? FOREIGNCANARY', $entries[0]->content());
    }

    private function assertEncodable(string $text): void
    {
        self::assertTrue(mb_check_encoding($text, 'UTF-8'), 'output must be valid UTF-8');
        // What SglangProvider/CustomProvider's `'json' => $params` reaches.
        self::assertIsString(Utils::jsonEncode(['content' => $text]));
    }

    private function put(string $relative, string $bytes): string
    {
        $path = $this->scratch . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    private function dropScratchTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($walk as $node) {
            $node->isDir() && !$node->isLink() ? rmdir($node->getPathname()) : unlink($node->getPathname());
        }
        rmdir($dir);
    }
}

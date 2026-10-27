<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\BuiltIn\SkillTool;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-EXT-2: the `asset` arm of the Skill tool, the resource announcement on a
 * body result, `${CLAUDE_SKILL_DIR}` substitution, and the invokedIn exclusion.
 *
 * Before this step {@see SkillLoader::loadSkillAsset()} had no caller at all
 * (plan_comparison GAP-SKL-10): the read was jailed to the project root, so a
 * user-tier skill's scripts/ and references/ files were unreachable through
 * the tool, and a body referencing them gave the model only a base-directory
 * line to guess against. These are the tool-level pins for the wiring; the
 * loader-level listing pins live beside the loader in tests/Skills.
 */
final class SkillToolAssetTest extends TestCase
{
    /** @var list<string> temp directories to prune */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    // =========================================================================
    // (a) The `asset` argument loads one bundled file through the loader's
    // jailed reader, under the SAME two header lines as a body load.
    // =========================================================================

    public function testAssetCallReturnsFileContentUnderTheTwoHeaderLines(): void
    {
        $dir = $this->skillTree('asset-load', "Body.\n", ['scripts/run.sh' => "#!/bin/sh\necho ran\n"]);
        $tool = $this->toolFor('asset-load', $dir . '/SKILL.md');

        $result = $tool->execute(['id' => 'a1', 'name' => 'asset-load', 'asset' => 'scripts/run.sh']);

        $this->assertFalse($result->isError());
        $this->assertSame(
            "## Skill: asset-load\n\n> Base directory for this skill: $dir\n\n#!/bin/sh\necho ran\n",
            $result->content(),
        );
    }

    public function testAssetCallIsRefusedOnTraversalOutOfTheSkillDirectory(): void
    {
        $dir = $this->skillTree('asset-escape', "Body.\n", ['scripts/run.sh' => "x\n"]);
        $sentinel = 'outside-' . basename($dir) . '.txt';
        file_put_contents(dirname($dir) . '/' . $sentinel, 'SENTINEL-ASSET-SECRET');
        $tool = $this->toolFor('asset-escape', $dir . '/SKILL.md');

        // `scripts/..` is back at the skill directory; the second `..` leaves
        // it — the same shape SkillLoaderTest pins at the loader, now pinned
        // through the tool door that reaches it.
        $result = $tool->execute([
            'id' => 'a2',
            'name' => 'asset-escape',
            'asset' => 'scripts/../../' . $sentinel,
        ]);

        unlink(dirname($dir) . '/' . $sentinel);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Error loading skill asset', $result->content());
        $this->assertStringContainsString('escapes skill directory', $result->content());
        $this->assertStringNotContainsString('SENTINEL-ASSET-SECRET', $result->content());
    }

    public function testAssetCallIsRefusedOutsideTheBundledSubdirectories(): void
    {
        $dir = $this->skillTree('asset-other', "Body.\n", ['notes/other.txt' => 'other\n']);
        $tool = $this->toolFor('asset-other', $dir . '/SKILL.md');

        $result = $tool->execute(['id' => 'a3', 'name' => 'asset-other', 'asset' => 'notes/other.txt']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Error loading skill asset', $result->content());
        $this->assertStringContainsString('must be within', $result->content());
    }

    public function testAssetCallRefusesANonStringLikeEveryOtherField(): void
    {
        $dir = $this->skillTree('asset-typed', "Body.\n", []);
        $tool = $this->toolFor('asset-typed', $dir . '/SKILL.md');

        $result = $tool->execute(['id' => 'a4', 'name' => 'asset-typed', 'asset' => ['scripts/run.sh']]);

        $this->assertTrue($result->isError());
        $this->assertSame('Error: asset must be a string', $result->content());
    }

    // =========================================================================
    // (b) A body result announces up to ten bundled resource paths.
    // =========================================================================

    public function testBodyResultListsBundledResourcesUnderTheBody(): void
    {
        $dir = $this->skillTree('asset-list', "Do the thing.\n", [
            'scripts/b.sh' => "b\n",
            'scripts/a.sh' => "a\n",
            'references/guide.md' => "g\n",
        ]);
        $tool = $this->toolFor('asset-list', $dir . '/SKILL.md');

        $result = $tool->execute(['id' => 'b1', 'name' => 'asset-list']);

        $this->assertFalse($result->isError());
        // Sorted across all three subdirectories, announced after the body.
        $this->assertSame(
            "## Skill: asset-list\n\n> Base directory for this skill: $dir\n\nDo the thing.\n\n"
            . "> Skill resources (load one with the `asset` argument):\n"
            . "> - references/guide.md\n> - scripts/a.sh\n> - scripts/b.sh",
            $result->content(),
        );
    }

    public function testBodyResultListsAtMostTenResources(): void
    {
        $files = [];
        for ($i = 0; $i < 12; $i++) {
            $files['scripts/file' . $i . '.txt'] = "f$i\n";
        }
        $dir = $this->skillTree('asset-cap', "Body.\n", $files);
        $tool = $this->toolFor('asset-cap', $dir . '/SKILL.md');

        $result = $tool->execute(['id' => 'b2', 'name' => 'asset-cap']);

        $listed = preg_split('/\n/', $result->content());
        $announced = array_values(array_filter(
            $listed,
            static fn(string $line): bool => str_starts_with($line, '> - '),
        ));
        $this->assertCount(10, $announced);
        // The sorted head is what returns, and the sort is lexicographic:
        // file10 and file11 ride right after file1, and file8/file9 are the
        // two paths past the ten the model sees. Pinning the edge names is
        // what proves "capped at ten" is a truncation, not an arbitrary pick.
        $this->assertSame('> - scripts/file0.txt', $announced[0]);
        $this->assertSame('> - scripts/file7.txt', $announced[9]);
        $this->assertStringNotContainsString('> - scripts/file8.txt', $result->content());
        $this->assertStringNotContainsString('> - scripts/file9.txt', $result->content());
    }

    public function testBodyWithoutResourcesKeepsTheByteExactHeaderAndBody(): void
    {
        // The regression door for (b): a skill with nothing bundled must not
        // grow an empty announcement — every existing body byte-pin (and the
        // prompt-stability arithmetic built on it) stands on this.
        $dir = $this->skillTree('asset-none', "Plain body.", []);
        $tool = $this->toolFor('asset-none', $dir . '/SKILL.md');

        $result = $tool->execute(['id' => 'b3', 'name' => 'asset-none']);

        $this->assertSame(
            "## Skill: asset-none\n\n> Base directory for this skill: $dir\n\nPlain body.",
            $result->content(),
        );
    }

    // =========================================================================
    // (c) ${CLAUDE_SKILL_DIR} and ${SKILL_DIR} in a body become the base dir.
    // =========================================================================

    public function testBodyPlaceholdersBecomeTheSkillBaseDirectory(): void
    {
        $body = "run \${CLAUDE_SKILL_DIR}/scripts/run.sh then \${SKILL_DIR}/scripts/other.sh\n";
        $dir = $this->skillTree('asset-dirs', $body, []);
        $tool = $this->toolFor('asset-dirs', $dir . '/SKILL.md');

        $result = $tool->execute(['id' => 'c1', 'name' => 'asset-dirs']);

        $this->assertStringContainsString("run $dir/scripts/run.sh then $dir/scripts/other.sh", $result->content());
        $this->assertStringNotContainsString('CLAUDE_SKILL_DIR', $result->content());
        $this->assertStringNotContainsString('${SK', $result->content());
    }

    public function testManifestSkillLeavesTheDirectoryPlaceholderAlone(): void
    {
        // dirname('') is '.', a confident lie about the session directory —
        // the same refusal baseDirLine() makes. The token surviving is the
        // honest answer for a skill that has no file to sit beside.
        $registry = new SkillRegistry();
        $registry->registerFromManifest([
            'name' => 'no-source',
            'description' => 'manifest only',
            'disableModelInvocation' => false,
            'userInvocable' => true,
            'context' => 'thread',
            'paths' => [],
            'sourcePath' => '',
        ]);
        // Manifest skills have no source file, so the body read cannot happen;
        // pin the pure substitution arm directly instead.
        $this->assertSame(
            'x ${CLAUDE_SKILL_DIR} y ${SKILL_DIR} z',
            SkillTool::substituteSkillDir('x ${CLAUDE_SKILL_DIR} y ${SKILL_DIR} z', ''),
        );

        $result = (new SkillTool($registry))->execute(['id' => 'c2', 'name' => 'no-source']);
        $this->assertTrue($result->isError());
    }

    // =========================================================================
    // (d) invokedIn() skips `asset` calls so ReinjectionPlan never re-injects
    // a bundled file as a skill body.
    // =========================================================================

    public function testInvokedInSkipsAssetCallsAndKeepsBodyCalls(): void
    {
        $messages = [
            new AssistantMessage('', [
                new ToolCall('id-body', 'Skill', ['name' => 'alpha']),
                new ToolCall('id-asset', 'Skill', ['name' => 'beta', 'asset' => 'scripts/one.sh']),
                new ToolCall('id-blank', 'Skill', ['name' => 'gamma', 'asset' => '   ']),
            ]),
        ];

        $invoked = SkillTool::invokedIn($messages);

        // 'beta' loaded a file, not a body — out. 'gamma' carries an
        // all-whitespace asset, which execute() reads as "no asset" (the body
        // branch), so the exclusion must use the same trim door or the two
        // halves would disagree about what an asset call is.
        $this->assertSame(
            [['name' => 'gamma', 'args' => ''], ['name' => 'alpha', 'args' => '']],
            $invoked,
        );
    }

    // ---------------------------------------------------------------------

    private function toolFor(string $key, string $skillPath): SkillTool
    {
        $registry = new SkillRegistry();
        $registry->register([$key => $this->bundledSkill($key, $skillPath)]);

        return new SkillTool($registry);
    }

    private function bundledSkill(string $name, string $sourcePath): Skill
    {
        return new Skill(
            name: $name,
            description: "Skill: $name",
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: '',
            sourcePath: $sourcePath,
        );
    }

    /**
     * A skill directory holding SKILL.md with $body plus $files written
     * relative to it. Returns the directory.
     *
     * @param array<string, string> $files relative path => content
     */
    private function skillTree(string $key, string $body, array $files): string
    {
        $dir = sys_get_temp_dir() . '/skilltool_asset_' . $key . '_' . uniqid('', true);
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;
        file_put_contents($dir . '/SKILL.md', "---\ndescription: d\n---\n" . $body);

        foreach ($files as $relative => $content) {
            $target = $dir . '/' . $relative;
            $parent = dirname($target);
            if (!is_dir($parent)) {
                mkdir($parent, 0700, true);
            }
            file_put_contents($target, $content);
        }

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        @rmdir($dir);
    }
}

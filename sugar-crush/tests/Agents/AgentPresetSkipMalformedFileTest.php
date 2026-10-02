<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * One malformed preset file costs that preset, not the roster (audit AG-2).
 *
 * MEASURED before the fix (`_audit-scratch/15e/preset_list.php`): a tier
 * holding a valid `good.md` (`tools: [Read]`) and a `reviewer.md` written in
 * Claude Code's `tools: Read, Grep, Glob` spelling made
 * {@see AgentPresetRegistry::list()} throw a TypeError, and
 * {@see Bootstrap::agentPresets()} degraded that to NO presets in ANY tier.
 * `name: 123`, `permissionMode: 5` and a file with no frontmatter failed the
 * same way. Two fixes are pinned here: the comma string is a legal list
 * spelling, and a genuinely broken file is skipped and NAMED while every other
 * preset still loads.
 */
final class AgentPresetSkipMalformedFileTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tempDir;
    private string $originalHome;
    private mixed $originalServerHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_preset_skip_' . uniqid('', true);
        mkdir($this->tempDir . '/agents', 0755, true);
        mkdir($this->tempDir . '/home', 0700, true);

        // Both spellings, for the reason AgentPresetDirContainmentTest states:
        // the launch-level test below must not read the developer's own
        // ~/.sugar-crush/agents.
        $this->originalHome = getenv('HOME') ?: '';
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->tempDir . '/home');
        $_SERVER['HOME'] = $this->tempDir . '/home';
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== '') {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function preset(string $dir, string $name, string $frontmatter, string $body = 'Prompt.'): string
    {
        $path = $dir . '/' . $name . '.md';
        file_put_contents($path, "---\n" . $frontmatter . "\n---\n" . $body . "\n");

        return $path;
    }

    /** The audit's own fixture: the comma line now parses and hides nothing. */
    public function testAClaudeCodeCommaToolsLineParsesAndDoesNotHideItsSibling(): void
    {
        $dir = $this->tempDir . '/agents';
        $this->preset($dir, 'good', "name: good\ndescription: fine preset\ntools: [Read]");
        $this->preset($dir, 'reviewer', "name: reviewer\ndescription: Claude-Code-style tools line\ntools: Read, Grep");

        $registry = new AgentPresetRegistry([$dir]);
        $presets = $registry->list();

        self::assertSame(['good', 'reviewer'], array_keys($presets));
        self::assertSame(['Read'], $presets['good']->tools);
        self::assertSame(['Read', 'Grep'], $presets['reviewer']->tools);
        self::assertSame([], $registry->skippedFiles());
    }

    /** Every list-of-names field takes the comma spelling, trimmed, empties dropped. */
    public function testEveryNameListFieldAcceptsTheCommaString(): void
    {
        $dir = $this->tempDir . '/agents';
        $this->preset(
            $dir,
            'auditor',
            "name: auditor\ndescription: d\ntools: ' Read ,, Grep , '\n"
            . "disallowedTools: Write, Edit\nskills: php-best-practices\nmcpServers: git, github",
        );

        $preset = (new AgentPresetRegistry([$dir]))->load('auditor');

        self::assertSame(['Read', 'Grep'], $preset->tools);
        self::assertSame(['Write', 'Edit'], $preset->disallowedTools);
        self::assertSame(['php-best-practices'], $preset->skills);
        self::assertSame(['git', 'github'], $preset->mcpServers);
    }

    /**
     * Shapes nothing can coerce sensibly: each is skipped, named with its
     * reason, and the valid preset in the same tier survives.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function brokenPresets(): iterable
    {
        yield 'no frontmatter' => ['', 'No YAML frontmatter found'];
        yield 'numeric name' => ["name: 123\ndescription: d", '`name:` must be a string, int given'];
        yield 'numeric permissionMode' => ["description: d\npermissionMode: 5", '`permissionMode:` must be a string, int given'];
        yield 'map tools' => ["description: d\ntools: {Read: true}", '`tools:` must be a list of names or a comma-separated string; it holds a bool entry'];
        yield 'numeric tools' => ["description: d\ntools: 7", '`tools:` must be a list of names or a comma-separated string, int given'];
        yield 'nested list in skills' => ["description: d\nskills: [a, [b]]", '`skills:` must be a list of names or a comma-separated string; it holds a array entry'];
    }

    /** @dataProvider brokenPresets */
    public function testABrokenFileIsSkippedAndNamedWhileTheGoodOneSurvives(string $frontmatter, string $reason): void
    {
        $dir = $this->tempDir . '/agents';
        $this->preset($dir, 'good', "name: good\ndescription: fine preset\ntools: [Read]");
        $bad = $dir . '/broken.md';
        file_put_contents(
            $bad,
            $frontmatter === '' ? "# Just a header\n\nNo YAML here.\n" : "---\n{$frontmatter}\n---\nBody.\n",
        );

        $registry = new AgentPresetRegistry([$dir]);
        $presets = $registry->list();

        self::assertSame(['good'], array_keys($presets));
        self::assertSame([$bad], array_keys($registry->skippedFiles()));
        self::assertStringContainsString($reason, $registry->skippedFiles()[$bad]);
    }

    /** load() names one preset, so it still gets that file's failure as an exception. */
    public function testLoadingTheBrokenPresetByNameStillThrowsNamingTheFile(): void
    {
        $dir = $this->tempDir . '/agents';
        $bad = $this->preset($dir, 'broken', "name: 123\ndescription: d");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($bad . ': `name:` must be a string, int given');

        (new AgentPresetRegistry([$dir]))->load('broken');
    }

    /**
     * A skipped file does not claim its name: the lower tier's same-named
     * preset is the only definition of it that can be honoured.
     */
    public function testASkippedHigherTierFileLetsTheLowerTierPresetLoad(): void
    {
        $project = $this->tempDir . '/agents';
        $user = $this->tempDir . '/user-agents';
        mkdir($user, 0755, true);
        $bad = $this->preset($project, 'reviewer', "description: d\npermissionMode: 5");
        $this->preset($user, 'reviewer', "description: The user's reviewer");

        $registry = new AgentPresetRegistry([$project, $user]);
        $presets = $registry->list();

        self::assertSame("The user's reviewer", $presets['reviewer']->description);
        self::assertSame([$bad], array_keys($registry->skippedFiles()));
    }

    /** Recomputed per call, so a skip never outlives the file that caused it. */
    public function testSkippedFilesIsRecomputedByEachList(): void
    {
        $dir = $this->tempDir . '/agents';
        $bad = $this->preset($dir, 'broken', "name: 123\ndescription: d");

        $registry = new AgentPresetRegistry([$dir]);
        $registry->list();
        self::assertArrayHasKey($bad, $registry->skippedFiles());

        $this->preset($dir, 'broken', "name: fixed\ndescription: d");
        self::assertSame(['broken'], array_keys($registry->list()));
        self::assertSame([], $registry->skippedFiles());
    }

    /**
     * THE LAUNCH PATH the audit measured: the project tier's good preset
     * reaches the roster, and the skipped file is put in front of the user by
     * path rather than taking every preset down with it.
     */
    public function testALaunchKeepsTheGoodPresetAndNoticesTheSkippedFileByPath(): void
    {
        $root = $this->tempDir . '/checkout';
        $agents = $root . '/.sugar-crush/agents';
        mkdir($agents, 0755, true);
        $this->preset($agents, 'good', "name: good\ndescription: fine preset\ntools: [Read]");
        $this->preset($agents, 'reviewer', "name: reviewer\ndescription: d\ntools: Read, Grep, Glob");
        $bad = $this->preset($agents, 'broken', "name: 123\ndescription: d");

        $presets = Bootstrap::agentPresets($root);

        self::assertSame(['good', 'reviewer'], array_keys($presets));
        self::assertSame(['Read', 'Grep', 'Glob'], $presets['reviewer']->tools);

        $notices = implode("\n", [...Bootstrap::launchNotices(), ...Bootstrap::launchNoticesDropped()]);
        self::assertStringContainsString(
            sprintf(Bootstrap::AGENT_PRESET_SKIP_NOTICE_FORMAT, 1, '', $bad . ' (`name:` must be a string'),
            $notices,
        );
        self::assertStringContainsString('`name:` must be a string, int given', $notices);
    }
}

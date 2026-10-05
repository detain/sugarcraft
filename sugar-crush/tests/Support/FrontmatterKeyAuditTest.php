<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Support\FrontmatterKeyAudit;

/**
 * X-37a: the audit that names frontmatter keys a loaded file declares and
 * nothing acts on, and the three loader seams that feed it.
 */
final class FrontmatterKeyAuditTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/fm_audit_' . uniqid('', true);
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
        parent::tearDown();
    }

    public function testAFileDeclaringOnlyLiveKeysHasNothingIgnored(): void
    {
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, [
            'name' => 'pdf', 'description' => 'x', 'paths' => ['*.pdf'], 'license' => 'MIT',
        ]));
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::RULE, [
            'name' => 'r', 'enabled' => false, 'keywords' => ['a'],
        ]));
    }

    public function testAnInertKeyIsNamed(): void
    {
        self::assertSame(
            ['`allowed-tools` is not acted on', '`effort` is not acted on'],
            FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, ['effort' => 'high', 'allowed-tools' => 'Read']),
        );
    }

    public function testANoOpValueOfAnInertKeyIsNotReported(): void
    {
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::AGENT, [
            'model' => 'inherit', 'permissionMode' => 'Default', 'background' => false, 'isolation' => 'none',
        ]));
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, ['context' => 'thread']));
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::COMMAND, ['subtask' => false]));
    }

    public function testAValueSensitiveInertKeyShowsTheValue(): void
    {
        self::assertSame(
            ['`context: fork` is not acted on'],
            FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, ['context' => 'fork']),
        );
        self::assertSame(
            ['`subtask: true` is not acted on'],
            FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::COMMAND, ['subtask' => true]),
        );
    }

    public function testAnUnknownKeyIsNamedWithADidYouMean(): void
    {
        self::assertSame(
            ['`permisionMode` is not an agent preset field (did you mean `permissionMode`?)'],
            FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::AGENT, ['permisionMode' => 'plan']),
        );
        self::assertSame(
            ['`hooks` is not a skill field'],
            FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, ['hooks' => []]),
        );
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function suggestionCases(): iterable
    {
        yield 'case' => [FrontmatterKeyAudit::AGENT, 'PermissionMode', 'permissionMode'];
        yield 'snake for camel' => [FrontmatterKeyAudit::AGENT, 'disallowed_tools', 'disallowedTools'];
        yield 'camel for kebab' => [FrontmatterKeyAudit::SKILL, 'allowedTools', 'allowed-tools'];
        yield 'one typo' => [FrontmatterKeyAudit::RULE, 'keyword', 'keywords'];
        yield 'two typos' => [FrontmatterKeyAudit::COMMAND, 'descripton', 'description'];
        yield 'short key stays strict' => [FrontmatterKeyAudit::SKILL, 'ox', 'os'];
        yield 'short key, two off' => [FrontmatterKeyAudit::SKILL, 'ab', null];
        yield 'nothing close' => [FrontmatterKeyAudit::RULE, 'priority', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('suggestionCases')]
    public function testSuggestion(string $format, string $key, ?string $expected): void
    {
        self::assertSame($expected, FrontmatterKeyAudit::suggestion($format, $key));
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FrontmatterKeyAudit::inspect('workflow', []);
    }

    public function testTheNoticeGroupsByLabelAndCountsFiles(): void
    {
        $row = FrontmatterKeyAudit::notice(FrontmatterKeyAudit::SKILL, [
            'a' => ['`allowed-tools` is not acted on'],
            'b' => ['`allowed-tools` is not acted on', '`effort` is not acted on'],
            'c' => [],
        ]);

        self::assertSame(
            sprintf(
                FrontmatterKeyAudit::NOTICE_FORMAT,
                2,
                'skills',
                '',
                '`allowed-tools` is not acted on (a, b); `effort` is not acted on (b)',
            ),
            $row,
        );
    }

    public function testTheNoticeQuotesAFewNamesAndCountsTheRest(): void
    {
        $findings = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $findings[$name] = ['`model` is not acted on'];
        }

        self::assertSame(
            '5 commands declare frontmatter sugar-crush ignores: `model` is not acted on (a, b, c +2 more)',
            FrontmatterKeyAudit::notice(FrontmatterKeyAudit::COMMAND, $findings),
        );
    }

    public function testNothingIgnoredIsNoNotice(): void
    {
        self::assertNull(FrontmatterKeyAudit::notice(FrontmatterKeyAudit::RULE, ['x' => []]));
        self::assertNull(FrontmatterKeyAudit::notice(FrontmatterKeyAudit::RULE, []));
    }

    /**
     * Keys, values and names are whatever a repository's file says, and the
     * row reaches the transcript: an escape sequence must not survive.
     */
    public function testRepositoryTextIsFlattenedAndClipped(): void
    {
        $labels = FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::RULE, ["evil\e]52;c;AAAA\x07\nkey" => 1]);
        self::assertCount(1, $labels);
        self::assertStringNotContainsString("\e", $labels[0]);
        self::assertStringNotContainsString("\n", $labels[0]);

        $row = (string) FrontmatterKeyAudit::notice(FrontmatterKeyAudit::RULE, [str_repeat('n', 200) => ['x']]);
        self::assertStringContainsString(str_repeat('n', 47) . '…', $row);
        self::assertStringNotContainsString(str_repeat('n', 48), $row);
    }

    public function testMetaOfReadsAMappingAndToleratesEverythingElse(): void
    {
        self::assertSame(['a' => 1], FrontmatterKeyAudit::metaOf("---\na: 1\n---\nbody\n"));
        self::assertSame([], FrontmatterKeyAudit::metaOf("no frontmatter\n"));
        self::assertSame([], FrontmatterKeyAudit::metaOf("---\n- a\n- b\n---\nbody\n"));
        self::assertSame([], FrontmatterKeyAudit::metaOf("---\na: [unclosed\n---\nbody\n"));
    }

    // -- the loader seams ------------------------------------------------------

    public function testAgentPresetRegistryRecordsWhatALoadedPresetIgnores(): void
    {
        file_put_contents($this->dir . '/rev.md', "---\ndescription: R\ncolor: red\ncolour: red\n---\nbody\n");
        file_put_contents($this->dir . '/clean.md', "---\ndescription: C\nmodel: inherit\n---\nbody\n");

        $registry = new AgentPresetRegistry([$this->dir]);
        $presets = $registry->list();

        self::assertArrayHasKey('rev', $presets, 'an ignored key never refuses the preset');
        self::assertSame(
            ['rev' => [
                '`color` is not acted on',
                '`colour` is not an agent preset field (did you mean `color`?)',
            ]],
            $registry->ignoredFrontmatter(),
        );
    }

    public function testCommandSpecCarriesWhatItsFileIgnores(): void
    {
        $path = $this->dir . '/c.md';
        file_put_contents($path, "---\ndescription: D\nmodel: big\nagent: build\n---\nDo it.\n");

        self::assertSame(
            ['`model` is not acted on', '`agent` is not a command field'],
            CommandSpec::fromFile($path, 'c')->ignoredFrontmatter,
        );
        self::assertSame([], CommandSpec::new('help', 'Help', 'Session')->ignoredFrontmatter);
    }

    public function testRuleLoaderRecordsWhatALoadedRuleIgnores(): void
    {
        $root = $this->dir . '/repo';
        mkdir($root . '/.sugar-crush/rules', 0o700, true);
        file_put_contents($root . '/.sugar-crush/rules/tabs.md', "---\nenable: false\n---\nUse tabs.\n");

        $loader = new RuleLoader($root, reportRefusals: false);
        $rules = $loader->loadProjectRules();

        self::assertCount(1, $rules);
        self::assertTrue($rules[0]->enabled, 'the typo is exactly why the rule stays on');
        self::assertSame(
            ['tabs' => ['`enable` is not a rule field (did you mean `enabled`?)']],
            $loader->ignoredFrontmatter(),
        );
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}

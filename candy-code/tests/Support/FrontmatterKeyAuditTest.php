<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentPresetRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Context\RuleLoader;
use SugarCraft\Code\Support\FrontmatterKeyAudit;

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
            'name' => 'r', 'enabled' => false, 'paths' => ['*.php'],
        ]));
    }

    public function testAnInertKeyIsNamed(): void
    {
        // C-EXT-2.b (R-EXT-1): a skill's `allowed-tools`/`disallowed-tools`
        // feed the turn's gate grants and its `model`/`effort` ride the step
        // loop's next request — declaring them is acted on and names nothing.
        self::assertSame(
            [],
            FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, ['effort' => 'high', 'allowed-tools' => 'Read']),
        );
        self::assertArrayNotHasKey('allowed-tools', FrontmatterKeyAudit::INERT[FrontmatterKeyAudit::SKILL]);
        self::assertArrayNotHasKey('disallowed-tools', FrontmatterKeyAudit::INERT[FrontmatterKeyAudit::SKILL]);
        self::assertArrayNotHasKey('model', FrontmatterKeyAudit::INERT[FrontmatterKeyAudit::SKILL]);
        self::assertArrayNotHasKey('effort', FrontmatterKeyAudit::INERT[FrontmatterKeyAudit::SKILL]);
        // ISS-068: `keywords` has no matcher and stays inert. `models` left
        // INERT[RULE] when the seam closed — Runtime threads the session model
        // into RuleLoader — so declaring it is acted on and must NOT be named.
        // The live expectation rests on the end-to-end pins in
        // RulebookPromptRenderTest::testThePromptSplicesAModelScopedRuleOnlyForTheSessionModelItNames(),
        // which redden if the threading disappears; before those existed this
        // `[]` alone would have blessed a silent un-wiring.
        self::assertSame(
            ['`keywords` is not acted on'],
            FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::RULE, ['keywords' => ['a']]),
        );
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::RULE, ['models' => ['qwen3-coder']]));
    }

    public function testANoOpValueOfAnInertKeyIsNotReported(): void
    {
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::AGENT, [
            'model' => 'inherit', 'permissionMode' => 'Default', 'background' => false, 'isolation' => 'none',
        ]));
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, ['context' => 'thread']));
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::COMMAND, ['subtask' => false]));
    }

    public function testIsolationIsLiveSoAWorktreeRequestIsNotReported(): void
    {
        // Roadmap 4.9: `Task` and `/bg` honour `isolation: worktree`, so the
        // key left the inert map and no value of it is "ignored" any more.
        self::assertArrayNotHasKey('isolation', FrontmatterKeyAudit::INERT[FrontmatterKeyAudit::AGENT]);
        self::assertContains('isolation', FrontmatterKeyAudit::KNOWN[FrontmatterKeyAudit::AGENT]);
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::AGENT, ['isolation' => 'worktree']));
    }

    public function testForkContextAndAgentAreLiveSoNoSkillKeyIsInert(): void
    {
        // C-EXT-2.c: SkillTool gained the forked-run path, so `context` left
        // the inert map and `agent` joined the known ones — no value of
        // either is "ignored" any more, and skills have no inert row at all.
        self::assertSame([], FrontmatterKeyAudit::INERT[FrontmatterKeyAudit::SKILL]);
        self::assertArrayNotHasKey('context', FrontmatterKeyAudit::INERT[FrontmatterKeyAudit::SKILL]);
        self::assertContains('context', FrontmatterKeyAudit::KNOWN[FrontmatterKeyAudit::SKILL]);
        self::assertContains('agent', FrontmatterKeyAudit::KNOWN[FrontmatterKeyAudit::SKILL]);
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, ['context' => 'fork']));
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::SKILL, ['agent' => 'sage']));
    }

    public function testCommandModelSubtaskAndAgentAreLiveSoNoCommandKeyIsInert(): void
    {
        // C-EXT-2.e: TurnController reads all three — `subtask: true` and
        // `agent: X` route the expansion through the delegation directive and
        // `model: X` pins that one turn — so the command format's inert pair
        // left the map and no value of any command key is "ignored" any more.
        self::assertSame([], FrontmatterKeyAudit::INERT[FrontmatterKeyAudit::COMMAND]);
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::COMMAND, ['subtask' => true]));
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::COMMAND, ['model' => 'big']));
        self::assertSame([], FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::COMMAND, ['agent' => 'sage']));
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
            'a' => ['`context: fork` is not acted on'],
            'b' => ['`context: fork` is not acted on', '`user_invocable` is not a skill field'],
            'c' => [],
        ]);

        self::assertSame(
            sprintf(
                FrontmatterKeyAudit::NOTICE_FORMAT,
                2,
                'skills',
                '',
                '`context: fork` is not acted on (a, b); `user_invocable` is not a skill field (b)',
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
            '5 commands declare frontmatter candy-code ignores: `model` is not acted on (a, b, c +2 more)',
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
        // `color` is NOT reported: it went live with P-AGENT.a (the strip, the
        // header, the activity line and the dashboard row render it). `colour`
        // stays unknown and still suggests the live spelling.
        self::assertSame(
            ['rev' => [
                '`colour` is not an agent preset field (did you mean `color`?)',
            ]],
            $registry->ignoredFrontmatter(),
        );
    }

    public function testCommandSpecCarriesWhatItsFileIgnores(): void
    {
        $path = $this->dir . '/c.md';
        file_put_contents($path, "---\ndescription: D\nmodel: big\nagent: build\ncolour: red\n---\nDo it.\n");

        // INT-3 flipped `agent` from unrecognised to KNOWN; C-EXT-2.e made it
        // and `model` ACTED ON (delegation directive, one-turn model pin), so
        // neither earns a row any more. `colour` keeps the unknown-key row the
        // seam is for — and no did-you-mean: no command key folds near it.
        self::assertSame(
            ['`colour` is not a command field'],
            CommandSpec::fromFile($path, 'c')->ignoredFrontmatter,
        );
        self::assertSame(
            [],
            FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::COMMAND, ['allowed-tools' => 'Bash', 'template' => 'body']),
            'the imported vocabulary earns no notice of either kind (KNOWN, not INERT — see the KNOWN doc-block)'
        );
        self::assertSame([], CommandSpec::new('help', 'Help', 'Session')->ignoredFrontmatter);
        // C-EXT-2.e: the agent name is carried off the row, not just tolerated.
        self::assertSame('build', CommandSpec::fromFile($path, 'c')->agent);
    }

    public function testRuleLoaderRecordsWhatALoadedRuleIgnores(): void
    {
        $root = $this->dir . '/repo';
        mkdir($root . '/.candy-code/rules', 0o700, true);
        file_put_contents($root . '/.candy-code/rules/tabs.md', "---\nenable: false\n---\nUse tabs.\n");

        $loader = new RuleLoader($root, reportRefusals: false);
        $rules = $loader->loadProjectRules();

        self::assertCount(1, $rules);
        self::assertTrue($rules[0]->enabled, 'the typo is exactly why the rule stays on');
        self::assertSame(
            ['tabs' => ['`enable` is not a rule field (did you mean `enabled`?)']],
            $loader->ignoredFrontmatter(),
        );
    }

    public function testARuleDeclaringTheForeignAliasKeysEarnsNoNoticeAndFoldsCorrectly(): void
    {
        $root = $this->dir . '/repo-alias';
        mkdir($root . '/.claude/rules', 0o700, true);
        file_put_contents($root . '/.claude/rules/mod.mdc', "---\nglobs:\n  - src/main.php\nalwaysApply: false\n---\nbody\n");

        $loader = new RuleLoader($root, reportRefusals: false);
        $rules = $loader->loadProjectClaudeRules();

        self::assertCount(1, $rules);
        // C-CTX-1.b flipped the fold this pinned: `alwaysApply: false` BESIDE
        // globs is Cursor's apply-on-match declaration, so the rule is enabled
        // and path-scoped — ACTED ON now means the patterns arrive, not the
        // AND-off. The pattern-less act-on proof lives in
        // RuleLoaderTest::testAlwaysApplyFalseDisablesAtTheParseBoundaryAndEarnsNoAuditNotice.
        self::assertTrue($rules[0]->enabled, 'alwaysApply: false beside globs is acted on as Cursor semantics, not folded off');
        self::assertSame(['src/main.php'], $rules[0]->triggers[0]->globs, 'the globs: alias builds the PathTrigger the enable decision just consulted');
        self::assertNull($rules[0]->disabledBy);
        self::assertSame([], $loader->ignoredFrontmatter(), 'a did-you-mean on a working alias would train the operator to ignore the audit');
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

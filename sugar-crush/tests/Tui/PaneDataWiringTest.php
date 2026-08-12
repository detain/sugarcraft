<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tui\Components\FilesPane;
use SugarCraft\Crush\Tui\Components\SkillsPane;
use SugarCraft\Crush\Tui\Components\ToolsPane;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer;

/**
 * W3.M4 — the shell's sidebars read the live session instead of placeholder
 * text (crush_feat.md §1 E6 for {@see ToolsPane}, §5 E7 for the pane layer
 * as a whole, §10.5 for {@see SkillsPane}'s provenance badges).
 *
 * @see FilesPane
 * @see ToolsPane
 * @see SkillsPane
 */
final class PaneDataWiringTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Renderer::resetSizeCache();
        Renderer::setSize(120, 40);

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        Renderer::resetSizeCache();
        parent::tearDown();
    }

    // =====================================================================
    // ToolsPane — crush_feat.md §1 E6
    // =====================================================================

    /**
     * @testdox ToolsPane::render() lists finished tool calls from the hosted Chat's history
     */
    public function testToolsPaneListsFinishedToolResults(): void
    {
        $app = $this->appWithHistory(Pane::Tools, [
            Message::assistant('read it')->withToolResults([ToolResult::ok('Read', 'contents', 'c1')]),
            Message::assistant('grepped')->withToolResults([ToolResult::ok('Grep', 'hits', 'c2')]),
        ]);

        $output = ToolsPane::render($app, 40, 20);

        $this->assertStringContainsString('Read', $output);
        $this->assertStringContainsString('Grep', $output);
        // Regression: the pane hardcoded this string for every session.
        $this->assertStringNotContainsString('(tool history empty)', $output);
    }

    /**
     * @testdox ToolsPane::render() puts the newest tool call first, so sidebar clipping keeps it
     */
    public function testToolsPaneOrdersNewestFirst(): void
    {
        $app = $this->appWithHistory(Pane::Tools, [
            Message::assistant('one')->withToolResults([ToolResult::ok('OldestTool', 'x', 'c1')]),
            Message::assistant('two')->withToolResults([ToolResult::ok('NewestTool', 'y', 'c2')]),
        ]);

        $output = ToolsPane::render($app, 40, 20);

        $this->assertLessThan(
            strpos($output, 'OldestTool'),
            strpos($output, 'NewestTool'),
            'Tui\Renderer clips the sidebar from the bottom, so the newest row must be on top',
        );
    }

    /**
     * @testdox ToolsPane::render() marks a failed tool call with its error
     */
    public function testToolsPaneShowsErrorRow(): void
    {
        $app = $this->appWithHistory(Pane::Tools, [
            Message::assistant('boom')->withToolResults([ToolResult::error('Bash', 'exit 1', 'c1')]),
        ]);

        $output = ToolsPane::render($app, 40, 20);

        $this->assertStringContainsString('✖', $output);
        $this->assertStringContainsString('Bash', $output);
    }

    /**
     * @testdox ToolsPane::render() shows a still-running call from its placeholder message
     */
    public function testToolsPaneShowsRunningPlaceholder(): void
    {
        $app = $this->appWithHistory(Pane::Tools, [
            Message::toolRunning(new ToolCall('Bash', ['description' => 'List files'], 'c1')),
        ]);

        $output = ToolsPane::render($app, 40, 20);

        $this->assertStringContainsString('◌', $output);
        $this->assertStringContainsString('List files', $output);
    }

    /**
     * @testdox ToolsPane::render() never emits more rows than the pane was given
     */
    public function testToolsPaneRespectsRowBudget(): void
    {
        $history = [];
        for ($i = 0; $i < 200; $i++) {
            $history[] = Message::assistant("call {$i}")
                ->withToolResults([ToolResult::ok("Tool{$i}", 'ok', "c{$i}")]);
        }
        $app = $this->appWithHistory(Pane::Tools, $history);

        $output = ToolsPane::render($app, 40, 8);

        // 8 rows = 2 border edges + at most 6 tool rows.
        $this->assertLessThanOrEqual(8, substr_count($output, "\n") + 1);
        $this->assertStringContainsString('Tool199', $output);
        $this->assertStringNotContainsString('Tool0 ', $output);
    }

    /**
     * @testdox ToolsPane::render() still reports an empty history honestly
     */
    public function testToolsPaneEmptyHistoryKeepsPlaceholder(): void
    {
        $app = $this->appWithHistory(Pane::Tools, []);

        $this->assertStringContainsString('(tool history empty)', ToolsPane::render($app, 40, 20));
    }

    /**
     * A tool's stderr is tool-authored text on the render path: left raw, its
     * newlines each cost a row the pane never budgeted for, so the sidebar
     * outgrows `paneRows` and {@see Renderer}'s clipHead() eats the box's
     * bottom border.
     *
     * @testdox ToolsPane::render() keeps a multi-line tool error inside the row budget
     */
    public function testToolsPaneFlattensMultiLineToolError(): void
    {
        $app = $this->appWithHistory(Pane::Tools, [
            Message::assistant('boom')->withToolResults([
                ToolResult::error('Bash', "line1\nline2\nline3\nline4\nline5", 'c1'),
            ]),
        ]);

        $output = ToolsPane::render($app, 40, 6);

        $this->assertLessThanOrEqual(6, substr_count($output, "\n") + 1);
    }

    /**
     * @testdox ToolsPane::render() strips control bytes out of a tool's name and error
     */
    public function testToolsPaneSanitizesToolResultLabels(): void
    {
        $app = $this->appWithHistory(Pane::Tools, [
            Message::assistant('boom')->withToolResults([
                ToolResult::error("Ba\x07sh", "\x1b[31mRED\x1b[0m\x07BEL\rCR", 'c1'),
            ]),
        ]);

        $output = ToolsPane::render($app, 60, 20);

        $this->assertStringNotContainsString("\x07", $output);
        $this->assertStringNotContainsString("\r", $output);
    }

    /**
     * @testdox ToolsPane::render() strips control bytes out of a running call's placeholder
     */
    public function testToolsPaneSanitizesRunningPlaceholder(): void
    {
        $app = $this->appWithHistory(Pane::Tools, [
            // No `description` argument, so describeToolCall() falls back to
            // the argument dump, which passes the model-authored tool NAME
            // through verbatim.
            Message::toolRunning(new ToolCall("Ba\x07sh\nEVIL", ['x' => 'y'], 'c1')),
        ]);

        $output = ToolsPane::render($app, 40, 4);

        $this->assertLessThanOrEqual(4, substr_count($output, "\n") + 1);
        $this->assertStringNotContainsString("\x07", $output);
    }

    // =====================================================================
    // FilesPane
    // =====================================================================

    /**
     * @testdox FilesPane::render() lists files the transcript's tool calls touched
     */
    public function testFilesPaneListsTranscriptTouchedFiles(): void
    {
        $app = $this->appWithHistory(Pane::Files, [
            $this->callMessage(['file_path' => '/proj/src/Widget.php']),
        ]);

        $output = FilesPane::render($app, 40, 20);

        $this->assertStringContainsString('Widget.php', $output);
        $this->assertStringNotContainsString('(no files attached)', $output);
    }

    /**
     * @testdox FilesPane::render() caps its rows and its walk at the pane height
     *
     * The defect this covers: one row per file the transcript had EVER
     * touched, which both blew the row budget and cost 83ms/frame at 1000
     * files.
     */
    public function testFilesPaneRespectsRowBudget(): void
    {
        $history = [];
        for ($i = 0; $i < 500; $i++) {
            $history[] = $this->callMessage(['file_path' => "/proj/src/File{$i}.php"]);
        }
        $app = $this->appWithHistory(Pane::Files, $history);

        $output = FilesPane::render($app, 40, 10);

        $this->assertLessThanOrEqual(10, substr_count($output, "\n") + 1);
    }

    /**
     * @testdox FilesPane::render() keeps the RECENT files, not the oldest ones
     *
     * Tui\Renderer clips the sidebar with clipHead() (keeps the top), so a
     * first-seen-first list froze the pane on files from the start of the
     * session.
     */
    public function testFilesPaneKeepsRecentFilesNotOldest(): void
    {
        $history = [];
        for ($i = 0; $i < 40; $i++) {
            $history[] = $this->callMessage(['file_path' => "/proj/src/File{$i}.php"]);
        }
        $app = $this->appWithHistory(Pane::Files, $history);

        $output = FilesPane::render($app, 40, 6);

        $this->assertStringContainsString('File39.php', $output);
        $this->assertStringNotContainsString('File0.php', $output);
    }

    /**
     * @testdox FilesPane::render() ignores a search tool's directory argument
     */
    public function testFilesPaneIgnoresSearchRoots(): void
    {
        $app = $this->appWithHistory(Pane::Files, [
            $this->callMessage(['pattern' => '*.php', 'path' => '/proj/src'], 'Glob'),
        ]);

        $output = FilesPane::render($app, 40, 20);

        $this->assertStringContainsString('(no files attached)', $output);
    }

    /**
     * @testdox FilesPane::render() lists a plain path argument from a non-search tool
     */
    public function testFilesPaneAcceptsBarePathArgument(): void
    {
        $app = $this->appWithHistory(Pane::Files, [
            $this->callMessage(['path' => '/proj/notes.md'], 'Open'),
        ]);

        $this->assertStringContainsString('notes.md', FilesPane::render($app, 40, 20));
    }

    /**
     * @testdox FilesPane::render() lists attached context files before transcript ones
     */
    public function testFilesPaneShowsAttachedFilesFirst(): void
    {
        $app = $this->appWithHistory(Pane::Files, [
            $this->callMessage(['file_path' => '/proj/src/Touched.php']),
        ])->withContextFiles(['/proj/Attached.php']);

        $output = FilesPane::render($app, 40, 20);

        $this->assertLessThan(
            strpos($output, 'Touched.php'),
            strpos($output, 'Attached.php'),
        );
    }

    /**
     * @testdox FilesPane::render() lists a file touched twice only once
     */
    public function testFilesPaneDeduplicatesPaths(): void
    {
        $app = $this->appWithHistory(Pane::Files, [
            $this->callMessage(['file_path' => '/proj/src/Twice.php']),
            $this->callMessage(['file_path' => '/proj/src/Twice.php']),
        ]);

        $output = FilesPane::render($app, 40, 20);

        $this->assertSame(1, substr_count($output, 'Twice.php'));
    }

    /**
     * A `file_path` argument is MODEL-authored, so it is untrusted the moment
     * this pane started sourcing paths from the transcript: an embedded LF
     * buys the model a row the pane never budgeted for, and a BEL reaches the
     * terminal.
     *
     * @testdox FilesPane::render() flattens and scrubs a model-supplied file_path
     */
    public function testFilesPaneSanitizesModelSuppliedPath(): void
    {
        $app = $this->appWithHistory(Pane::Files, [
            $this->callMessage(['file_path' => "/a/b\nEVIL\x07x.php"]),
        ]);

        $output = FilesPane::render($app, 40, 3);

        // 3 rows = 2 border edges + exactly 1 file row.
        $this->assertLessThanOrEqual(3, substr_count($output, "\n") + 1);
        $this->assertStringNotContainsString("\x07", $output);
    }

    // =====================================================================
    // SkillsPane — crush_feat.md §10.5
    // =====================================================================

    /**
     * @testdox SkillsPane::render() lists the skills actually discovered when none is enabled
     */
    public function testSkillsPaneListsAvailableRegistrySkills(): void
    {
        $registry = new SkillRegistry();
        $registry->register(['on-disk' => $this->skill('on-disk', SkillSource::Native)]);
        $app = App::new($this->provider, 'test-model')
            ->withPane(Pane::Skills)
            ->withAvailableSkills($registry);

        $output = SkillsPane::render($app, 40, 20);

        $this->assertStringContainsString('on-disk', $output);
        $this->assertStringNotContainsString('(no skills enabled)', $output);
    }

    /**
     * @testdox SkillsPane::render() badges an imported skill in the available list
     */
    public function testSkillsPaneBadgesAvailableForeignSkill(): void
    {
        $registry = new SkillRegistry();
        $registry->register(['imported' => $this->skill('imported', SkillSource::Claude)]);
        $app = App::new($this->provider, 'test-model')
            ->withPane(Pane::Skills)
            ->withAvailableSkills($registry);

        $output = SkillsPane::render($app, 40, 20);

        $this->assertStringContainsString('[claude]', $output);
        $this->assertMatchesRegularExpression('/\[claude\].*imported/s', $output);
    }

    /**
     * @testdox SkillsPane::render() caps the available list at the pane height
     */
    public function testSkillsPaneRespectsRowBudget(): void
    {
        $registry = new SkillRegistry();
        $skills = [];
        for ($i = 0; $i < 60; $i++) {
            $skills["skill-{$i}"] = $this->skill("skill-{$i}", SkillSource::Native);
        }
        $registry->register($skills);
        $app = App::new($this->provider, 'test-model')
            ->withPane(Pane::Skills)
            ->withAvailableSkills($registry);

        $output = SkillsPane::render($app, 40, 9);

        $this->assertLessThanOrEqual(9, substr_count($output, "\n") + 1);
    }

    /**
     * @testdox SkillsPane::render() prefers the enabled skills over the available list
     */
    public function testSkillsPanePrefersEnabledSkills(): void
    {
        $registry = new SkillRegistry();
        $registry->register(['available-only' => $this->skill('available-only', SkillSource::Native)]);
        $app = App::new($this->provider, 'test-model')
            ->withPane(Pane::Skills)
            ->withAvailableSkills($registry)
            ->withEnabledSkills(['turned-on']);

        $output = SkillsPane::render($app, 40, 20);

        $this->assertStringContainsString('turned-on', $output);
        $this->assertStringNotContainsString('available-only', $output);
    }

    /**
     * An App whose hosted Chat carries $history, focused on $pane.
     *
     * @param list<Message> $history
     */
    private function appWithHistory(Pane $pane, array $history): App
    {
        return App::new($this->provider, 'test-model')
            ->withPane($pane)
            ->withChat(new Chat($history));
    }

    /**
     * An assistant message carrying one tool call with $arguments.
     *
     * @param array<string, mixed> $arguments
     */
    private function callMessage(array $arguments, string $tool = 'Read'): Message
    {
        return Message::assistant('calling')
            ->withToolCalls([new ToolCall($tool, $arguments, 'call-' . spl_object_id((object) $arguments))]);
    }

    private function skill(string $name, SkillSource $source): Skill
    {
        return new Skill(
            name: $name,
            description: 'A skill',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'fresh',
            paths: [],
            content: 'Body.',
            sourcePath: "/tmp/{$name}/SKILL.md",
            source: $source,
        );
    }
}

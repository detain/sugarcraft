<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @see Renderer
 */
final class RendererTest extends TestCase
{
    private function chat(array $history = [], string $buf = '', bool $inFlight = false): Chat
    {
        return new Chat(
            history:  $history,
            inputBuf: $buf,
            inFlight: $inFlight,
        );
    }

    private function agentManagerWith(array $agents): AgentManager
    {
        $provider = $this->createMock(ProviderInterface::class);
        $manager = new AgentManager($provider, new SkillRegistry());
        foreach ($agents as $agent) {
            $manager->register($agent);
        }

        return $manager;
    }

    private function reviewerAgent(bool $isActive = true): Agent
    {
        return new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: $isActive,
        );
    }

    // =========================================================================
    // R20: agent status/view wiring
    // =========================================================================

    /**
     * @see Renderer::render()
     */
    public function testRendersAgentStatusAndViewWhenAgentManagerHasActiveAgents(): void
    {
        $chat = new Chat(agentManager: $this->agentManagerWith([$this->reviewerAgent()]));

        $out = Renderer::render($chat);

        // AgentStatusBar's status-bullet line.
        $this->assertStringContainsString('reviewer', $out);
        // AgentViewPane's bracketed status format ("[working]") is distinctive
        // from AgentsCommand's plain "/agents" text output (which emits
        // "● active" / "○ inactive", never a bracketed status word) — this
        // is the proof the real component rendered, not just echoed text.
        $this->assertStringContainsString('[working]', $out);
        // AgentViewPane's bordered "agents" panel title.
        $this->assertStringContainsString('agents', $out);
    }

    /**
     * @see Renderer::render()
     */
    public function testOmitsAgentViewWhenNoAgentManagerSet(): void
    {
        $out = Renderer::render($this->chat());

        $this->assertStringNotContainsString('[working]', $out);
        $this->assertStringNotContainsString('[stopped]', $out);
    }

    /**
     * @see Renderer::render()
     */
    public function testOmitsAgentViewWhenAgentManagerHasNoActiveAgents(): void
    {
        $chat = new Chat(agentManager: $this->agentManagerWith([$this->reviewerAgent(isActive: false)]));

        $out = Renderer::render($chat);

        // active() filters to isActive===true agents only — an inactive-only
        // roster yields an empty list, so the agent view section is omitted
        // entirely rather than showing a "[stopped]" agent that was never
        // "active" in the AgentManager sense.
        $this->assertStringNotContainsString('[stopped]', $out);
        $this->assertStringNotContainsString('[working]', $out);
    }

    /**
     * Proves '/agents' renders real AgentViewPane/AgentStatusBar content
     * through the live Chat -> Renderer path (not plain echoed command
     * text): submits '/agents' through the real Chat::update() dispatch,
     * then feeds the *resulting* Chat through Renderer::render() and checks
     * for the same distinctive component markup asserted above, alongside
     * the plain AgentsCommand text that landed in history.
     *
     * @see Renderer::render()
     * @see \SugarCraft\Crush\Commands\AgentsCommand
     */
    public function testAgentsCommandOutputRendersThroughRealAgentViewPane(): void
    {
        $chat = new Chat(inputBuf: '/agents', agentManager: $this->agentManagerWith([$this->reviewerAgent()]));

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // The plain-text /agents command output is still in history, as before.
        $this->assertStringContainsString('Active Agents', $next->history[1]->content);

        $out = Renderer::render($next);

        // AND the live renderer now also shows the real, non-textual
        // AgentViewPane/AgentStatusBar rendering of the same agent data.
        $this->assertStringContainsString('reviewer', $out);
        $this->assertStringContainsString('[working]', $out);
        $this->assertStringContainsString('agents', $out);
    }

    // =========================================================================
    // R20: session tab strip wiring
    // =========================================================================

    public function testRendersSessionTabStripWithMultipleSessionsAndBracketsCurrent(): void
    {
        $tempDir = sys_get_temp_dir() . '/renderer_test_' . uniqid('', true);
        mkdir($tempDir, 0755, true);
        $store = new SessionStore($tempDir . '/sessions.db');
        $store->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');
        $store->createSession('session-b', 'openai', 'gpt-4', null, 'Beta');

        try {
            $chat = new Chat(sessionStore: $store, currentSessionId: 'session-b');

            $out = Renderer::render($chat);

            $this->assertStringContainsString('Alpha', $out);
            $this->assertStringContainsString('[Beta]', $out);
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tempDir);
        }
    }

    public function testOmitsSessionTabStripWithFewerThanTwoSessions(): void
    {
        $tempDir = sys_get_temp_dir() . '/renderer_test_' . uniqid('', true);
        mkdir($tempDir, 0755, true);
        $store = new SessionStore($tempDir . '/sessions.db');
        $store->createSession('session-a', 'openai', 'gpt-4', null, 'Alpha');

        try {
            $chat = new Chat(sessionStore: $store, currentSessionId: 'session-a');

            $out = Renderer::render($chat);

            $this->assertStringNotContainsString('[Alpha]', $out);
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($tempDir);
        }
    }

    public function testOmitsSessionTabStripWhenNoSessionStore(): void
    {
        $out = Renderer::render($this->chat());

        // No SessionStore configured — the tab strip's "|"-joined labels
        // never appear (the status line's "·" separator is unrelated).
        $this->assertStringNotContainsString('|', $out);
    }

    public function testRendersEmptyConversationHint(): void
    {
        $out = Renderer::render($this->chat());
        $this->assertStringContainsString('empty conversation', $out);
    }

    public function testRendersUserAndAssistantTurns(): void
    {
        $out = Renderer::render($this->chat([
            Message::user('hello there', 0),
            Message::assistant('# Hi!\n\nHow can I help?', 0),
        ]));
        $this->assertStringContainsString('user>', $out);
        $this->assertStringContainsString('hello there', $out);
        $this->assertStringContainsString('assistant', $out);
    }

    /**
     * Regression for crush_feat.md §12 D3's final sentence: "surface the
     * result rendered dimmed/collapsed in the TUI". Prior to this fix,
     * `Message` had no reasoning field at all, so `Renderer` had nothing to
     * render even though the provider layer's `ReasoningExtractor` computed
     * it on every real completion.
     */
    public function testRendersAssistantReasoningDimmedAndCollapsed(): void
    {
        $out = Renderer::render($this->chat([
            Message::user('why is the sky blue?', 0),
            Message::assistant('Rayleigh scattering.', 0, reasoning: "Let me think about light wavelengths.\nBlue scatters more."),
        ]));

        $this->assertStringContainsString('💭', $out);
        $this->assertStringContainsString('Let me think about light wavelengths.', $out);
        // Collapsed onto one line: the newline inside the reasoning text
        // must not survive into the rendered block.
        $this->assertStringNotContainsString("wavelengths.\nBlue", $out);
        $this->assertStringContainsString('Rayleigh scattering.', $out);
    }

    public function testOmitsReasoningLineWhenProviderDidNotSplitAny(): void
    {
        $out = Renderer::render($this->chat([
            Message::assistant('Rayleigh scattering.', 0),
        ]));

        $this->assertStringNotContainsString('💭', $out);
    }

    public function testRendersSystemTurn(): void
    {
        $out = Renderer::render($this->chat([
            Message::system('You are a helpful assistant.', 0),
        ]));
        $this->assertStringContainsString('system:', $out);
        $this->assertStringContainsString('helpful assistant', $out);
    }

    public function testInputCursorVisibleWhenIdle(): void
    {
        $out = Renderer::render($this->chat(buf: 'partial'));
        $this->assertStringContainsString('partial', $out);
        $this->assertStringContainsString('█', $out);
    }

    public function testInputCursorHiddenWhileInFlight(): void
    {
        $out = Renderer::render($this->chat(buf: 'partial', inFlight: true));
        $this->assertStringNotContainsString('█', $out);
        $this->assertStringContainsString('thinking', $out);
    }

    public function testIdleStatusMentionsKeys(): void
    {
        $out = Renderer::render($this->chat());
        $this->assertStringContainsString('Enter', $out);
        $this->assertStringContainsString('quit', $out);
    }

    public function testIdleStatusMentionsCtrlPMenu(): void
    {
        $out = Renderer::render($this->chat());
        $this->assertStringContainsString('Ctrl+P', $out);
    }

    public function testInFlightIndicatorAppearsInChatWindowNotJustStatusBar(): void
    {
        $out = Renderer::render($this->chat(history: [Message::user('hi')], inFlight: true));
        $lines = explode("\n", $out);
        $statusLine = preg_replace('/\x1b\[[0-9;]*m/', '', (string) end($lines));

        $this->assertStringContainsString('assistant is thinking', $out);
        // The in-window indicator is a separate line from the status bar -
        // proves it's rendered in the chat body, not only at the bottom.
        $this->assertStringNotContainsString('assistant is thinking', $statusLine);
    }

    public function testRunningToolCallShowsAPendingPlaceholderBeforeItFinishes(): void
    {
        $call = new \SugarCraft\Crush\ToolCall('bash', ['command' => 'ls -la'], 'call_1');
        $running = Message::toolRunning($call);

        $out = Renderer::render($this->chat(history: [Message::user('list files'), $running]));

        $this->assertStringContainsString('running: bash', $out);
        $this->assertStringContainsString('ls -la', $out);
    }

    public function testToolResultsRenderWithADistinctMarkerNotAsPlainAssistantText(): void
    {
        $toolMsg = Message::assistant('42')->withToolResults([
            \SugarCraft\Crush\ToolResult::ok('calculator', '42', 'call_1'),
        ]);
        $out = Renderer::render($this->chat(history: [Message::user('what is 6*7?'), $toolMsg]));

        $this->assertStringContainsString('tool: calculator', $out);
        // §1 E5: a SUCCESSFUL body is hidden behind the affordance by
        // default; the marker still has to be distinct from assistant text.
        $this->assertStringContainsString('1 line hidden (ctrl+o)', $out);
    }

    /**
     * §1 E5 regression: before hide-on-success, every tool body was dumped
     * inline forever - a 500-line Grep result pushed the conversation out of
     * the viewport. Collapsed, only the affordance is printed.
     */
    public function testSuccessfulToolOutputIsHiddenByDefault(): void
    {
        $body = implode("\n", array_fill(0, 500, 'match in some/file.php'));
        $toolMsg = Message::assistant('')->withToolResults([
            \SugarCraft\Crush\ToolResult::ok('grep', $body, 'call_1'),
        ]);

        $out = Renderer::render($this->chat(history: [$toolMsg]));

        $this->assertStringNotContainsString('match in some/file.php', $out);
        $this->assertStringContainsString('500 lines hidden (ctrl+o)', $out);
    }

    public function testExpandedToolCallIdShowsTheFullSuccessBody(): void
    {
        $toolMsg = Message::assistant('')->withToolResults([
            \SugarCraft\Crush\ToolResult::ok('grep', "alpha\nbeta", 'call_1'),
        ]);

        $chat = $this->chat(history: [$toolMsg])->toggleToolOutput('call_1');
        $out = Renderer::render($chat);

        $this->assertStringContainsString('alpha', $out);
        $this->assertStringContainsString('beta', $out);
        $this->assertStringNotContainsString('hidden (ctrl+o)', $out);
    }

    /**
     * An error body is the output the user actually wants, so it is never
     * hidden - only clipped, with a trailer naming the escape hatch.
     */
    public function testCollapsedErrorOutputIsClippedNotHidden(): void
    {
        $body = implode("\n", array_map(static fn (int $i): string => "stderr line {$i}", range(1, 40)));
        $toolMsg = Message::assistant('')->withToolResults([
            \SugarCraft\Crush\ToolResult::error('bash', $body, 'call_1'),
        ]);

        $out = Renderer::render($this->chat(history: [$toolMsg]));

        $this->assertStringContainsString('stderr line 1', $out);
        $this->assertStringNotContainsString('stderr line 40', $out);
        $this->assertStringContainsString('output truncated (ctrl+o to expand)', $out);
    }

    public function testCollapseToolOutputKeepsShortOutputVerbatim(): void
    {
        $this->assertSame(
            ['output' => "a\nb", 'overflow' => false],
            Renderer::collapseToolOutput("a\nb", 10, 100),
        );
    }

    public function testCollapseToolOutputClipsOnTheLineBudget(): void
    {
        $collapsed = Renderer::collapseToolOutput("1\n2\n3\n4", 2, 1000);

        $this->assertSame("1\n2", $collapsed['output']);
        $this->assertTrue($collapsed['overflow']);
    }

    /**
     * One enormous line is still "1 line" - the character budget is what
     * catches it, which is why both limits exist.
     */
    public function testCollapseToolOutputClipsOnTheCharBudget(): void
    {
        $collapsed = Renderer::collapseToolOutput(str_repeat('x', 5000), 10, 100);

        $this->assertSame(str_repeat('x', 100), $collapsed['output']);
        $this->assertTrue($collapsed['overflow']);
    }

    public function testCollapseToolOutputCountsMultibyteCharactersNotBytes(): void
    {
        $collapsed = Renderer::collapseToolOutput(str_repeat('é', 10), 10, 4);

        $this->assertSame('éééé', $collapsed['output']);
        $this->assertTrue($collapsed['overflow']);
    }

    public function testCollapseToolOutputHandlesEmptyAndDegenerateLimits(): void
    {
        $this->assertSame(['output' => '', 'overflow' => false], Renderer::collapseToolOutput('', 10, 100));

        $collapsed = Renderer::collapseToolOutput("a\nb", 0, 0);
        $this->assertSame('a', $collapsed['output']);
        $this->assertTrue($collapsed['overflow']);
    }

    public function testFailedToolResultShowsErrorMarker(): void
    {
        $toolMsg = Message::assistant('')->withToolResults([
            \SugarCraft\Crush\ToolResult::error('bash', 'command not found', 'call_2'),
        ]);
        $out = Renderer::render($this->chat(history: [Message::user('run it'), $toolMsg]));

        $this->assertStringContainsString('tool: bash', $out);
        $this->assertStringContainsString('error', $out);
        $this->assertStringContainsString('command not found', $out);
    }

    /**
     * candy-buffer #1362 defense-in-depth: raw User turns reach the terminal
     * wire verbatim, so a C0/DEL byte or a smuggled SGR sequence must be
     * neutralized before render while the visible text survives. Revert-proof:
     * dropping the Sanitize::untrusted() call in Renderer fails these asserts.
     */
    public function testSanitizesControlBytesInUserContent(): void
    {
        $payload = "hi\x07\x00\x7f\x1b[31mPWNED\x1b[0m";
        $out = Renderer::render($this->chat([Message::user($payload, 0)]));

        $this->assertStringContainsString('PWNED', $out, 'visible text must survive');
        $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
        $this->assertStringNotContainsString("\x00", $out, 'NUL must be stripped');
        $this->assertStringNotContainsString("\x7f", $out, 'DEL must be stripped');
        // Red-foreground SGR the renderer never emits itself — proves the
        // injected escape sequence was neutralized, not just its ESC byte.
        $this->assertStringNotContainsString("\x1b[31m", $out, 'injected SGR must be neutralized');
    }

    public function testSanitizesControlBytesInSystemContent(): void
    {
        $payload = "sys\x07\x00\x7f\x1b[41mBAD\x1b[0m";
        $out = Renderer::render($this->chat([Message::system($payload, 0)]));

        $this->assertStringContainsString('BAD', $out, 'visible text must survive');
        $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
        $this->assertStringNotContainsString("\x00", $out, 'NUL must be stripped');
        $this->assertStringNotContainsString("\x7f", $out, 'DEL must be stripped');
        $this->assertStringNotContainsString("\x1b[41m", $out, 'injected SGR must be neutralized');
    }

    public function testSanitizesControlBytesInInputBuffer(): void
    {
        // A bracketed-paste dump can smuggle control bytes into the in-progress
        // draft; it must be scrubbed before hitting the terminal at draw time.
        $out = Renderer::render($this->chat(buf: "draft\x07\x00\x7f\x1b[31mX\x1b[0m"));

        $this->assertStringContainsString('draft', $out, 'visible text must survive');
        $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
        $this->assertStringNotContainsString("\x00", $out, 'NUL must be stripped');
        $this->assertStringNotContainsString("\x7f", $out, 'DEL must be stripped');
        $this->assertStringNotContainsString("\x1b[31m", $out, 'injected SGR must be neutralized');
    }

    /**
     * Guard against over-sanitization: the Assistant/CandyShine path emits
     * legitimate, already-processed SGR and must NOT be run through the
     * untrusted() strip. Shine renders bold as \x1b[1m — a sequence the
     * renderer's own "assistant" label (\x1b[1;35m) never produces, so its
     * presence proves the content styling survived intact.
     */
    public function testAssistantSgrNotOverSanitized(): void
    {
        $out = Renderer::render($this->chat([
            Message::assistant("# Heading\n\n**bold** text", 0),
        ]));

        $this->assertStringContainsString("\x1b[1m", $out, 'legitimate Shine SGR must survive');
        $this->assertStringContainsString('bold', $out);
    }

    public function testSlashMenuNotRenderedForPlainInput(): void
    {
        $out = Renderer::render($this->chat(buf: 'hello'));
        $this->assertStringNotContainsString('▸', $out);
    }

    public function testSlashMenuRendersFilteredMatchesWithSelectionMarker(): void
    {
        $out = Renderer::render($this->chat(buf: '/re'));

        $this->assertStringContainsString('▸ /rename', $out);
        $this->assertStringContainsString('/rewind', $out);
        // The unselected row is present but not marked as selected.
        $this->assertStringNotContainsString('▸ /rewind', $out);
    }

    public function testSlashMenuNotRenderedOnceArgumentsStart(): void
    {
        $out = Renderer::render($this->chat(buf: '/rename foo'));
        $this->assertStringNotContainsString('▸', $out);
    }

    public function testDifferentThemesProduceDifferentOutput(): void
    {
        $dark = new Chat(history: [Message::user('hi')], themeName: 'dark');
        $dracula = new Chat(history: [Message::user('hi')], themeName: 'dracula');

        $this->assertNotSame(Renderer::render($dark), Renderer::render($dracula));
    }

    public function testShortConversationIsPaddedToFullTerminalHeight(): void
    {
        $rows = \SugarCraft\Crush\Tui\Renderer::getTerminalSize()['rows'];
        $out = Renderer::render($this->chat());

        $this->assertCount($rows, explode("\n", $out));
    }

    /**
     * Regression: candy-core's Renderer repaints a changed row via an
     * ABSOLUTE cursorTo($row, 1) - once a frame is taller than the real
     * terminal, every row past the terminal's last line gets clamped there
     * by the terminal itself, so distinct rows (input box, status bar,
     * newest history) all collide on that one physical row. The frame must
     * never exceed $rows lines regardless of how long history gets. Forces
     * an explicit small size via WindowSizeMsg rather than the ambient
     * terminal's real size, both for a deterministic assertion and to
     * prove Renderer reads Chat::rows() (see that method's docblock for why
     * a second, independent terminal-size query is exactly the bug this
     * guards against).
     */
    public function testLongConversationIsClippedToFullTerminalHeightNotLeftUnbounded(): void
    {
        $rows = 20;
        $history = [];
        for ($i = 0; $i < $rows * 3; $i++) {
            $history[] = Message::user("message {$i}");
        }
        [$sized] = $this->chat($history)->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, $rows));

        $out = Renderer::render($sized);

        $this->assertCount($rows, explode("\n", $out));
    }

    /**
     * The tail of history - not the head - must survive clipping: the
     * newest turn and the input box (rendered after history) need to stay
     * visible, with older turns scrolling off the top instead.
     */
    public function testLongConversationClippingKeepsTheMostRecentMessageVisible(): void
    {
        $rows = 20;
        $history = [];
        for ($i = 0; $i < $rows * 3; $i++) {
            $history[] = Message::user("message {$i}");
        }
        [$sized] = $this->chat($history)->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, $rows));

        $out = Renderer::render($sized);

        $this->assertStringContainsString('message ' . ($rows * 3 - 1), $out);
        $this->assertStringNotContainsString('message 0' . "\n", $out);
    }

    /**
     * Renderer must lay out against whatever size Chat was actually told
     * via WindowSizeMsg, not the ambient terminal TuiRenderer::getTerminalSize()
     * happens to detect for THIS process - the two can legitimately differ
     * (a resize Chat received but the cached detector never re-queried).
     */
    public function testRendererUsesChatsWindowSizeNotAmbientTerminalDetection(): void
    {
        [$sized] = $this->chat()->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 15));

        $out = Renderer::render($sized);

        $this->assertCount(15, explode("\n", $out));
    }

    public function testStatusBarIsTheLastLineAndIncludesContextPercent(): void
    {
        $out = Renderer::render($this->chat());
        $lines = explode("\n", $out);
        $lastLine = preg_replace('/\x1b\[[0-9;]*m/', '', (string) end($lines));

        $this->assertMatchesRegularExpression('/\d+% context/', $lastLine);
        $this->assertStringContainsString('Enter to send', $lastLine);
    }

    public function testPaletteNotRenderedWhenClosed(): void
    {
        $out = Renderer::render($this->chat());
        $this->assertStringNotContainsString('New session', $out);
    }

    public function testPaletteRendersOverACompositedBackdropWhenOpen(): void
    {
        $chat = $this->chat();
        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $out = Renderer::render($opened);

        $this->assertStringContainsString('New session', $out);
        $this->assertStringContainsString('Exit', $out);
    }

    // =========================================================================
    // crush_feat.md §1 E3 (rendering half): Edit/Write diffs in the transcript
    // =========================================================================

    /** A Chat with a pinned viewport so width/height clipping is deterministic. */
    private function sizedChat(array $history, int $cols = 80, int $rows = 40): Chat
    {
        return new Chat(history: $history, rows: $rows, cols: $cols);
    }

    private function editResult(string $diff): Message
    {
        return Message::assistant('')->withToolResults([
            new \SugarCraft\Crush\ToolResult(
                name: 'Edit',
                result: 'File updated: src/App.php',
                id: 'call_edit',
                diff: $diff,
            ),
        ]);
    }

    /** @return list<string> visible (ANSI-stripped) lines of a rendered frame */
    private function visibleLines(string $frame): array
    {
        return array_map(
            static fn (string $line): string => (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $line),
            explode("\n", $frame),
        );
    }

    /**
     * The step-defining regression: before §1 E3's rendering half, a ToolResult
     * carrying a unified diff rendered as "🔧 tool: Edit ✓ ok / File updated: …"
     * and the diff was silently dropped. Every assertion below fails against
     * that renderer.
     */
    public function testEditToolDiffIsRenderedInTheTranscript(): void
    {
        $diff = "--- a/src/App.php\n+++ b/src/App.php\n@@ -1,3 +1,3 @@\n <?php\n-\$old = 1;\n+\$new = 2;\n";
        $out = Renderer::render($this->sizedChat([Message::user('edit it'), $this->editResult($diff)]));

        $this->assertStringContainsString('tool: Edit', $out);
        $this->assertStringContainsString('@@ -1,3 +1,3 @@', $out);
        $this->assertStringContainsString('-$old = 1;', $out);
        $this->assertStringContainsString('+$new = 2;', $out);
    }

    /**
     * Additions/removals are colour-coded, and the `---`/`+++` file headers
     * must NOT be mistaken for a whole-file removal/addition.
     */
    public function testDiffMarkersAreColourCodedAndFileHeadersAreNot(): void
    {
        $diff = "--- a/src/App.php\n+++ b/src/App.php\n@@ -1 +1 @@\n-gone\n+here\n";
        $chat = $this->sizedChat([$this->editResult($diff)]);
        $theme = $chat->theme();

        $out = Renderer::render($chat);

        $added = Style::new()->foreground(Color::ansi(2))->render('+here');
        $removed = Style::new()->foreground(Color::ansi(1))->render('-gone');
        $header = Style::new()->foreground($theme->systemLabel)->bold()->render('--- a/src/App.php');

        $this->assertStringContainsString($added, $out);
        $this->assertStringContainsString($removed, $out);
        $this->assertStringContainsString($header, $out);
        $this->assertStringNotContainsString(
            Style::new()->foreground(Color::ansi(1))->render('--- a/src/App.php'),
            $out,
        );
    }

    /**
     * Render invariant: one logical line per physical row. A diff line wider
     * than the viewport must be truncated, never wrapped -- candy-core's
     * Renderer repaints by absolute row, so a wrapped line shifts every row
     * below it.
     */
    public function testOverWideDiffLinesAreTruncatedToTheViewportWidth(): void
    {
        $diff = "--- a/x\n+++ b/x\n@@ -1 +1 @@\n+" . str_repeat('x', 400) . "\n";
        $out = Renderer::render($this->sizedChat([$this->editResult($diff)], cols: 80));

        foreach ($this->visibleLines($out) as $line) {
            $this->assertLessThanOrEqual(80, mb_strlen($line), 'over-wide row: ' . $line);
        }
    }

    /**
     * Render invariant: a huge diff must not evict the conversation it belongs
     * to, so the block is capped and the remainder reported as a count.
     */
    public function testLongDiffIsClippedWithARemainingLineCount(): void
    {
        $body = '';
        for ($i = 1; $i <= 40; $i++) {
            $body .= "+line {$i}\n";
        }
        $diff = "--- a/x\n+++ b/x\n@@ -0,0 +1,40 @@\n" . $body;

        $out = Renderer::render($this->sizedChat([$this->editResult($diff)], rows: 60));

        $this->assertStringContainsString('+line 1', $out);
        // 43 diff rows, capped at 24 -> 19 reported as remaining.
        $this->assertStringContainsString('19 more diff lines', $out);
        $this->assertStringNotContainsString('+line 40', $out);
    }

    /**
     * Diff bodies are verbatim file contents. A raw ESC in an edited file
     * would otherwise forge SGR straight onto the terminal wire.
     */
    public function testDiffContentIsSanitizedBeforeDisplay(): void
    {
        $diff = "--- a/x\n+++ b/x\n@@ -1 +1 @@\n+payload\x1b[31mRED\x07\n";
        $out = Renderer::render($this->sizedChat([$this->editResult($diff)]));

        $this->assertStringContainsString('payloadRED', $out);
        $this->assertStringNotContainsString("\x07", $out);
    }

    /** A result with no diff keeps the pre-E3 rendering exactly as it was. */
    public function testToolResultWithoutADiffRendersNoDiffBox(): void
    {
        $out = Renderer::render($this->sizedChat([
            Message::assistant('')->withToolResults([
                \SugarCraft\Crush\ToolResult::ok('calculator', '42', 'call_1'),
            ]),
        ]));

        $withDiff = Renderer::render($this->sizedChat([
            $this->editResult("--- a/x\n+++ b/x\n@@ -1 +1 @@\n-a\n+b\n"),
        ]));

        $this->assertStringContainsString('tool: calculator', $out);
        // The input box is the only Border::normal() box in a diff-free frame;
        // a rendered diff adds a second one.
        $this->assertSame(1, substr_count($out, '┌'));
        $this->assertSame(2, substr_count($withDiff, '┌'));
    }

    // =========================================================================
    // crush_feat.md §4 E3/E6/E7: palette highlighting, grouping, MRU order
    // =========================================================================

    /**
     * A visible line with the surrounding box-drawing frame and padding
     * removed. Uses a /u regex rather than trim(): trim()'s character list
     * is byte-based, so "│" and "▸" share a leading 0xE2 byte and trimming
     * the former would eat the latter's first byte.
     */
    private static function stripBox(string $line): string
    {
        return (string) preg_replace('/^[\s│╭╮╰╯─]+|[\s│╭╮╰╯─]+$/u', '', $line);
    }

    /** An open palette, optionally with $query already typed into it. */
    private function openPalette(string $query = ''): Chat
    {
        [$current] = $this->chat()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        foreach (str_split($query) as $ch) {
            [$current] = $current->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $current;
    }

    /**
     * §4 E3: the matched run carries its own SGR (bold + underline) inside
     * the row, and the row still reads as plain text once ANSI is stripped.
     */
    public function testPaletteHighlightsTheMatchedRunOfATypedQuery(): void
    {
        $out = Renderer::render($this->openPalette('them'));

        $row = '';
        foreach (explode("\n", $out) as $line) {
            if (str_contains(preg_replace('/\x1b\[[0-9;]*m/', '', $line), 'Switch theme')) {
                $row = $line;
            }
        }

        $this->assertNotSame('', $row, 'the matching palette row was not rendered');
        // "them" of "Switch theme" is wrapped in its own styled run; the "e"
        // after it is NOT part of that run.
        $this->assertMatchesRegularExpression('/\x1b\[[0-9;]*4[;m][^m]*m?them/', $row);
        $this->assertStringContainsString('them', $row);
        $this->assertStringContainsString(
            'Switch theme',
            preg_replace('/\x1b\[[0-9;]*m/', '', $row),
        );
    }

    /**
     * A highlighted run ends in a full SGR reset, so the row style has to be
     * re-opened behind it - otherwise everything after the match renders in
     * the terminal's default colour instead of the row's.
     */
    public function testPaletteReopensTheRowStyleAfterAHighlightedRun(): void
    {
        $out = Renderer::render($this->openPalette('them'));

        $row = '';
        foreach (explode("\n", $out) as $line) {
            if (str_contains(preg_replace('/\x1b\[[0-9;]*m/', '', $line), 'Switch theme')) {
                $row = $line;
            }
        }

        // …them<reset><row-style>e…  — a reset immediately followed by an SGR
        // colour re-open, not by bare text.
        $this->assertMatchesRegularExpression('/them\x1b\[0m(\x1b\[[0-9;]*m)+e/', $row);
    }

    /** §4 E6: the empty-query palette carries a header per category. */
    public function testEmptyQueryPaletteRendersCategoryHeaders(): void
    {
        $lines = $this->visibleLines(Renderer::render($this->openPalette()));
        $trimmed = array_map(self::stripBox(...), $lines);

        $this->assertContains('Session', $trimmed);
        $this->assertContains('Appearance', $trimmed);
        // A header is a bare category name; the rows under it keep their
        // "▸ "/"  " markers, so the label and its header never collide.
        $this->assertContains('▸ New session', $trimmed);
    }

    /** A typed query is a flat relevance list — no headers to break the ranking. */
    public function testQueriedPaletteOmitsCategoryHeaders(): void
    {
        $lines = $this->visibleLines(Renderer::render($this->openPalette('them')));
        $trimmed = array_map(self::stripBox(...), $lines);

        $this->assertNotContains('Appearance', $trimmed);
        $this->assertContains('▸ Switch theme', $trimmed);
    }

    /** Theme/provider lists have no categories, so they render ungrouped. */
    public function testThemeListPaletteRendersWithoutHeaders(): void
    {
        $chat = new Chat(palette: new \SugarCraft\Crush\Palette\PaletteState('themes', '', 0));

        $lines = $this->visibleLines(Renderer::render($chat));
        $trimmed = array_map(self::stripBox(...), $lines);

        $this->assertContains('▸ dark', $trimmed);
        $this->assertContains('dracula', $trimmed);
        $this->assertNotContains('Appearance', $trimmed);
    }

    /** §4 E7: a recently-run row renders at the top of the reopened palette. */
    public function testRecentlyUsedRowRendersFirst(): void
    {
        $chat = new Chat(
            palette: \SugarCraft\Crush\Palette\PaletteState::root(),
            paletteMru: ['Switch theme'],
        );

        $rows = [];
        foreach ($this->visibleLines(Renderer::render($chat)) as $line) {
            $trimmed = self::stripBox($line);
            if (str_starts_with($trimmed, '▸ ') || in_array($trimmed, ['Switch session', 'Exit'], true)) {
                $rows[] = $trimmed;
            }
        }

        $this->assertSame('▸ Switch theme', $rows[0]);
    }

    // =========================================================================
    // crush_feat.md §1 E2 (rendering half): the permission-prompt modal
    // =========================================================================

    /**
     * Park a real blocking permission prompt on a Chat by dispatching the same
     * Msg the ASK path dispatches, rather than hand-constructing the state.
     */
    private function chatAwaitingPermission(
        string $prompt = 'Run rm -rf build/?',
        array $arguments = ['description' => 'Delete the build directory'],
    ): Chat {
        [$blocked] = $this->sizedChat([Message::user('clean up')])->update(
            new \SugarCraft\Crush\PermissionRequestMsg(
                Message::assistant(''),
                new \SugarCraft\Crush\ToolCall('Bash', $arguments, 'call_1'),
                $prompt,
            ),
        );

        return $blocked;
    }

    /**
     * The step-defining regression: before this half of §1 E2 landed, a Chat
     * could park a turn on `pendingPermission()` and the renderer drew nothing
     * at all - the user saw a frozen "thinking…" frame with no question, no
     * options and no way to know a keypress was expected. Every assertion here
     * fails against that renderer.
     */
    public function testPermissionPromptIsRenderedAsAModal(): void
    {
        $out = Renderer::render($this->chatAwaitingPermission());

        $this->assertStringContainsString('permission required', $out);
        $this->assertStringContainsString('Bash', $out);
        $this->assertStringContainsString('Delete the build directory', $out);
        $this->assertStringContainsString('Run rm -rf build/?', $out);
    }

    /** Exactly the keys Chat::handlePermissionKey() accepts, and no others. */
    public function testPermissionPromptAdvertisesTheThreeAnswerKeys(): void
    {
        $out = Renderer::render($this->chatAwaitingPermission());

        $this->assertStringContainsString('allow once', $out);
        $this->assertStringContainsString('allow always', $out);
        $this->assertStringContainsString('reject', $out);
        $this->assertStringContainsString('n / Esc', $out);
    }

    /** Nothing is composited while no prompt is blocking the turn. */
    public function testNoPermissionModalWhenNoPromptIsPending(): void
    {
        $out = Renderer::render($this->sizedChat([Message::user('hi')]));

        $this->assertStringNotContainsString('permission required', $out);
    }

    /**
     * The prompt owns the keyboard in Chat::update(), so it must own the single
     * overlay slot too - a palette drawn on top of a prompt would advertise
     * keys that no longer do anything.
     */
    public function testPermissionModalTakesTheOverlaySlotFromAnOpenPalette(): void
    {
        [$opened] = $this->sizedChat([])->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertStringContainsString('New session', Renderer::render($opened));

        [$blocked] = $opened->update(new \SugarCraft\Crush\PermissionRequestMsg(
            Message::assistant(''),
            new \SugarCraft\Crush\ToolCall('Bash', [], 'call_1'),
            'Really?',
        ));
        $out = Renderer::render($blocked);

        $this->assertStringContainsString('permission required', $out);
        $this->assertStringNotContainsString('New session', $out);
    }

    /**
     * A hook may hand back an arbitrarily long message; the modal must clip it
     * rather than let its own answer keys get pushed off the viewport.
     */
    public function testLongPromptIsClippedWithACountOfTheHiddenLines(): void
    {
        $out = Renderer::render($this->chatAwaitingPermission(implode("\n", array_fill(0, 30, 'why not'))));

        $this->assertStringContainsString('more lines', $out);
        $this->assertStringContainsString('allow once', $out);
    }

    /**
     * No line of the modal may exceed its declared width: an over-wide row
     * inside a bordered box breaks the border and the viewport row accounting
     * render() does around it.
     */
    public function testLongUnbrokenArgumentTextIsWrappedNotOverflowed(): void
    {
        $blocked = $this->chatAwaitingPermission(
            str_repeat('averylongunbrokentoken', 12),
            ['command' => str_repeat('x', 400)],
        );

        foreach ($this->visibleLines(Renderer::render($blocked)) as $line) {
            $this->assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    /**
     * Veil clips the overlay to the background's widest line, and a paused turn
     * renders a narrow transcript - so without widening the backdrop first the
     * modal loses its entire right-hand border exactly when it is shown.
     */
    public function testModalKeepsItsRightBorderOverANarrowTranscript(): void
    {
        $out = Renderer::render($this->chatAwaitingPermission());

        $this->assertStringContainsString('╮', $out);
        $this->assertStringContainsString('╯', $out);
    }

    /** The modal never renders wider than the terminal it has to fit in. */
    public function testModalShrinksToNarrowTerminals(): void
    {
        [$blocked] = $this->sizedChat([Message::user('hi')], cols: 40)->update(
            new \SugarCraft\Crush\PermissionRequestMsg(
                Message::assistant(''),
                new \SugarCraft\Crush\ToolCall('Bash', ['description' => 'Delete the build directory'], 'call_1'),
                'Run it?',
            ),
        );

        $out = Renderer::render($blocked);
        $this->assertStringContainsString('permission required', $out);

        // Measured corner-to-corner, not as a whole line: the frame it is
        // centred in can carry its own pre-existing overhang (the status bar
        // is not truncated to $cols), which is not what this asserts.
        $widths = [];
        foreach ($this->visibleLines($out) as $line) {
            if (!str_contains($line, '╭')) {
                continue;
            }
            $start = mb_strpos($line, '╭');
            $end = mb_strrpos($line, '╮');
            if ($start !== false && $end !== false) {
                $widths[] = $end - $start + 1;
            }
        }

        $this->assertNotSame([], $widths);
        foreach ($widths as $width) {
            $this->assertLessThanOrEqual(40, $width);
        }
    }

    /**
     * Hook messages and tool arguments are model-authored text; an escape
     * sequence smuggled through either must not reach the terminal from inside
     * the dialog that is gating that very call.
     */
    public function testPromptTextIsSanitizedBeforeDisplay(): void
    {
        $out = Renderer::render($this->chatAwaitingPermission("danger\x1b[31mred", ['description' => "arg\x1b]0;pwn\x07"]));

        $this->assertStringNotContainsString("\x1b[31m", $out);
        $this->assertStringNotContainsString("\x1b]0;", $out);
    }
}

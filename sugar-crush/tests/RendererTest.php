<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
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

        // ANSI-stripped, because the typed "re" now carries its own SGR run
        // inside the row (crush_code.md Phase 4 item 5), so "▸ /rename" is no
        // longer contiguous in the raw bytes. The marker and the row are still
        // what is being asserted - see
        // testSlashMenuHighlightsTheMatchedRunOfTheTypedPrefix() for the SGR.
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $out);

        $this->assertStringContainsString('▸ /rename', $plain);
        $this->assertStringContainsString('/rewind', $plain);
        // The unselected row is present but not marked as selected.
        $this->assertStringNotContainsString('▸ /rewind', $plain);
    }

    /**
     * Phase 4 item 5: the "/" popup highlights the run the user typed, through
     * the same {@see \SugarCraft\Fuzzy\Highlighter} the Ctrl+P palette uses -
     * before this it was the one of the two command surfaces that could not,
     * because {@see \SugarCraft\Crush\Commands\CommandRegistry::filter()}
     * discarded the matcher's indices.
     *
     * Mirrors testPaletteHighlightsTheMatchedRunOfATypedQuery() deliberately:
     * the two surfaces should be asserted the same way, since the point of the
     * change is that they now share the mechanism.
     */
    public function testSlashMenuHighlightsTheMatchedRunOfTheTypedPrefix(): void
    {
        $out = Renderer::render($this->chat(buf: '/re'));

        $row = '';
        foreach (explode("\n", $out) as $line) {
            if (str_contains((string) preg_replace('/\x1b\[[0-9;]*m/', '', $line), '/rename')) {
                $row = $line;
            }
        }

        $this->assertNotSame('', $row, 'the matching popup row was not rendered');
        // "/" then an underline-bearing SGR then "re": the typed prefix is its
        // own styled run, and the "name" after it is not part of it.
        $this->assertMatchesRegularExpression('/\/\x1b\[[0-9;]*4[;m][^m]*m?re/', $row);
        // …re<reset><row-style>name… — the row style is re-opened behind the
        // run's full reset, or everything after the match would render in the
        // terminal's default colour.
        $this->assertMatchesRegularExpression('/re\x1b\[0m(\x1b\[[0-9;]*m)+name/', $row);
    }

    /**
     * A bare "/" lists every command with nothing typed, so nothing is
     * highlighted - the index-less MatchResults Highlighter no-ops on. Without
     * this, "highlight the matched run" would be satisfiable by highlighting
     * the whole name every time.
     */
    public function testSlashMenuHighlightsNothingWhenNothingIsTypedYet(): void
    {
        $out = Renderer::render($this->chat(buf: '/'));

        $row = '';
        foreach (explode("\n", $out) as $line) {
            if (str_contains((string) preg_replace('/\x1b\[[0-9;]*m/', '', $line), '/sessions')) {
                $row = $line;
            }
        }

        $this->assertNotSame('', $row, 'the bare "/" popup was not rendered');
        $this->assertStringContainsString('▸ /sessions', $row, 'so the row itself carries no inner SGR at all');
    }

    /**
     * Phase 4 item 5: `CommandSpec::$argumentHint` was parsed, stored on the
     * built-in rows that take arguments, and read by NOTHING - so "/rename" gave
     * no clue that it wants a name. The popup shows it now.
     */
    public function testSlashMenuShowsTheArgumentHint(): void
    {
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($this->chat(buf: '/re')));

        $this->assertStringContainsString('/rename <name> — Rename the current session', $plain);
        // …and a row with no hint gains no stray spacing from the feature.
        $this->assertStringContainsString('/rewind — Restore chat state', $plain);
    }

    /**
     * The width half of Phase 4 item 5, and the reason the hint is what gets
     * cut before the name: three different widths are involved here and every
     * one of them is `/websearch`'s, so each is named with the domain it is
     * true of - all measured with `Width::string()` over
     * `CommandRegistry::all()`, never `strlen()`:
     *
     * - its HINT ALONE is 58 columns,
     * - its popup head `/name <hint>` is 69,
     * - its `  /name <hint>` column in the `/help` listing is 71 (Chat's
     *   domain, not this one - {@see SlashDispatchTest} pins that side).
     *
     * An untruncated hint therefore pushes the row past the terminal, and
     * {@see Renderer::render()} paints one logical line per physical row, which
     * is the row-collision bug the frame clip exists to prevent.
     *
     * Asserted at SEVEN widths, and by exact row rather than by a width bound,
     * for two reasons a looser test got wrong before:
     *
     * - A bound six columns clear of the boundary (the old
     *   `assertLessThanOrEqual(60, …)` against an actual 54) cannot see a
     *   one-column budget error. Dropping the `- 1`
     *   {@see Renderer::fitSlashMenuHint()} spends on the hint's leading space
     *   moves a column from the description to the hint, which no width
     *   assertion can catch now that the row as a whole is fitted - only the
     *   exact row can.
     * - The 40-column row was MEASURED and then not asserted, at exactly the
     *   width where it was wrong: the row was 45 columns wide (stripped, marker
     *   included) inside a 40-, 30- OR 20-column terminal, because nothing
     *   clipped the description. Every width collected below is asserted.
     *
     * The BOX width (`Width::string()` of the row with its border and padding)
     * is the invariant that matters for collision, and it is asserted against
     * the terminal at every width. It is not `assertLessThanOrEqual($cols)` for
     * the WHOLE frame because the status bar is separately over-wide at narrow
     * terminals - 54 columns at any width below that, measured, pre-existing,
     * and nothing to do with this popup.
     */
    public function testSlashMenuFitsTheWholeRowNotJustTheHint(): void
    {
        // The popup box, and the box only: the input box below it also contains
        // the '/websearch' draft, so the "▸" marker is what identifies the row.
        $expected = [
            20 => '▸ /websearch …',
            30 => '▸ /websearch — Sear…',
            40 => '▸ /websearch — Search the web…',
            60 => '▸ /websearch <query>… — Search the web via SearXNG',
            80 => '▸ /websearch <query> [--safesearch 0|1|2… — Search the web via SearXNG',
            100 => '▸ /websearch <query> [--safesearch 0|1|2] [--time-range day|… — Search the web via SearXNG',
            120 => '▸ /websearch <query> [--safesearch 0|1|2] [--time-range day|month|year] — Search the web via SearXNG',
        ];

        foreach ($expected as $cols => $row) {
            $out = Renderer::render(
                (new Chat(history: [Message::user('hi')], inputBuf: '/websearch'))->withSize($cols, 30)
            );

            $box = 0;
            $found = null;
            foreach (explode("\n", $out) as $line) {
                $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
                if (str_contains($plain, '▸ /websearch')) {
                    $box = Width::string($plain);
                    // stripBox()'s /u regex rather than trim(): trim()'s
                    // charlist is byte-based, and "│" and "▸" share a leading
                    // 0xE2 byte, so trimming the border eats the marker's
                    // first byte and the row stops being valid UTF-8.
                    $found = self::stripBox($plain);
                }
            }

            $this->assertNotNull($found, "the \"/\" popup was not rendered at {$cols} columns");
            $this->assertSame($row, $found, "the popup row at {$cols} columns");
            $this->assertLessThanOrEqual(
                $cols,
                $box,
                "the popup box (border and padding included) must fit a {$cols}-column terminal, "
                . 'or render() paints it over the row below',
            );
        }

        // The three claims the exact rows above encode, said out loud so a
        // future edit to a description reads as a description change rather
        // than as a width regression.
        $this->assertStringContainsString(
            '/websearch <query> [--safesearch 0|1|2] [--time-range day|month|year] — Search',
            $expected[120],
            'at 120 columns the whole hint fits',
        );
        $this->assertStringContainsString('…', $expected[60], 'at 60 the hint is truncated, not dropped');
        $this->assertStringNotContainsString('month', $expected[60]);
        $this->assertStringNotContainsString(
            '<query>',
            $expected[40],
            'at 40 there is no room for a hint at all, so the description gets every column the name leaves',
        );
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

        $this->assertMatchesRegularExpression('/\(\d+%\)/', $lastLine);
        $this->assertStringContainsString('Enter to send', $lastLine);
    }

    /**
     * F.CTXK: a bare percentage is unactionable without knowing the budget,
     * so the bar carries the absolute count in K next to it. Would fail
     * against the old "37% context · …" bar, which printed no count at all.
     */
    public function testStatusBarShowsAbsoluteTokenCountBesideThePercentage(): void
    {
        $history = [];
        for ($i = 0; $i < 200; $i++) {
            $history[] = Message::user(str_repeat('x', 400));
        }
        $chat = $this->chat($history);
        [$sized] = $chat->update(new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40));

        $lastLine = $this->statusBar(Renderer::render($sized));

        // 200 messages x (400 chars / 4 + 10 role overhead) = 22,000 ESTIMATED
        // tokens against the 100,000-token fallback window: this fixture's Chat
        // holds the default EchoBackend, which reports no window, so
        // ContextWindow::FALLBACK_TOKENS is the denominator. On a real provider
        // it would be that provider's advertised window and the percentage
        // would differ - see
        // {@see \SugarCraft\Crush\Tests\Integration\ContextWindowWiringTest}.
        $this->assertSame(22000, $chat->contextTokens());
        $this->assertSame(100000, $chat->contextTokenLimit());
        $this->assertStringContainsString('~22K / 100K context (22%)', $lastLine);
    }

    /**
     * The COUNT is a chars/4 proxy, not a tokenizer figure, so it is prefixed
     * rather than presented as a measurement.
     *
     * The limit beside it is no longer in the same boat: since crush_code.md
     * Phase 5 item 4 it IS the model's advertised context window whenever the
     * backend can report one, so the reason for the `~` narrowed to the
     * left-hand number and the mismatch between the two units. It has not gone
     * away - this fixture's EchoBackend reports no window, so the limit here is
     * ContextWindow::FALLBACK_TOKENS.
     */
    public function testAbsoluteTokenCountIsLabelledAsAnEstimate(): void
    {
        [$sized] = $this->chat()->update(new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40));

        $this->assertMatchesRegularExpression('/~[\d.]+K \/ [\d.]+K context/', $this->statusBar(Renderer::render($sized)));
    }

    /**
     * The bar is the frame's last line and may never wrap, so the readout
     * degrades to narrower forms instead of pushing the row over the width.
     */
    public function testStatusBarNeverExceedsTerminalWidthAsTheReadoutGrows(): void
    {
        $history = [];
        for ($i = 0; $i < 400; $i++) {
            $history[] = Message::user(str_repeat('x', 4000));
        }

        foreach ([120, 80, 70, 60] as $cols) {
            [$sized] = $this->chat($history)->update(new \SugarCraft\Core\Msg\WindowSizeMsg($cols, 40));

            $lastLine = $this->statusBar(Renderer::render($sized));

            $this->assertLessThanOrEqual($cols, Width::of($lastLine), "overflowed at {$cols} cols");
            // However narrow, the percentage itself is never dropped.
            $this->assertMatchesRegularExpression('/\d+%/', $lastLine);
        }
    }

    /**
     * Below ~60 columns the bar's fixed help text ("Enter to send · Ctrl+P
     * menu · /exit or ^C to quit", ~52 columns with its separator) overflows
     * on its own — a pre-existing limit this step does not change. What is
     * asserted here is that the context readout contributes its minimum in
     * that case: the bare percentage, never a wider form.
     */
    public function testContextReadoutCollapsesToTheBarePercentageOnANarrowTerminal(): void
    {
        $history = [];
        for ($i = 0; $i < 200; $i++) {
            $history[] = Message::user(str_repeat('x', 400));
        }
        [$sized] = $this->chat($history)->update(new \SugarCraft\Core\Msg\WindowSizeMsg(40, 40));

        $this->assertStringStartsWith('22% · ', $this->statusBar(Renderer::render($sized)));
    }

    /** The final status-bar row, with SGR sequences and zone markers stripped. */
    private function statusBar(string $frame): string
    {
        $lines = explode("\n", $frame);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', (string) end($lines));

        return (string) preg_replace('/\x{E000}\/?[A-Za-z0-9._:-]*\x{E001}/u', '', (string) $plain);
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
     * crush_code.md Phase 8 item 1: the diff box used to say what changed but
     * never where. Each row now carries an old-file/new-file line-number
     * gutter, and the numbers have to be the file's, not the diff block's.
     */
    public function testDiffRowsCarryOldAndNewFileLineNumbers(): void
    {
        $diff = "--- a/src/App.php\n+++ b/src/App.php\n@@ -40,3 +40,3 @@\n <?php\n-\$old = 1;\n+\$new = 2;\n";
        $lines = $this->visibleLines(Renderer::render($this->sizedChat([$this->editResult($diff)])));

        $find = static function (string $needle) use ($lines): string {
            foreach ($lines as $line) {
                if (str_contains($line, $needle)) {
                    return $line;
                }
            }
            return '';
        };

        // Context sits on line 40 of both files; the edit is line 41 of each.
        $this->assertMatchesRegularExpression('/40 40│  <\?php/', $find('<?php'));
        $this->assertMatchesRegularExpression('/41\s+│ -\$old = 1;/', $find('-$old = 1;'));
        $this->assertMatchesRegularExpression('/\s+41│ \+\$new = 2;/', $find('+$new = 2;'));
        // File/hunk headers have no line to point at, but keep the column.
        $this->assertMatchesRegularExpression('/\s+│ @@ -40,3 \+40,3 @@/', $find('@@ -40,3'));
    }

    /**
     * The gutter is painted separately from the diff body so the body's own
     * add/remove SGR bytes stay exactly what they were before a gutter existed
     * -- otherwise every colour assertion above would have had to be loosened.
     */
    public function testTheGutterIsStyledSeparatelyFromTheDiffBody(): void
    {
        $diff = "--- a/x\n+++ b/x\n@@ -1 +1 @@\n-gone\n+here\n";
        $chat = $this->sizedChat([$this->editResult($diff)]);
        $theme = $chat->theme();
        $out = Renderer::render($chat);

        $gutterStyle = Style::new()->foreground($theme->systemLabel)->faint();
        $this->assertStringContainsString(
            $gutterStyle->render('  1│ ') . Style::new()->foreground(Color::ansi(2))->render('+here'),
            $out,
        );
    }

    /**
     * A viewport too narrow to spare the columns keeps the diff text and drops
     * the gutter rather than truncating every row back to its marker.
     */
    public function testANarrowViewportDropsTheGutterInsteadOfTheDiffText(): void
    {
        $diff = "--- a/x\n+++ b/x\n@@ -1 +1 @@\n-gone\n+here\n";
        $out = Renderer::render($this->sizedChat([$this->editResult($diff)], cols: 30));

        $this->assertStringContainsString('+here', $out);
        $this->assertStringNotContainsString('1│', $out);
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
     * The PR #1403 failure class, reached through TAB.
     *
     * candy-core's Sanitize::untrusted() preserves TAB and Width::string("\t")
     * is 0, but candy-sprinkles' Style::render() paints each tab as tabWidth
     * spaces -- so a Go/Makefile/C diff was budgeted 0 cells per tab and
     * painted 4, and the box emitted rows wider than the viewport. candy-core's
     * Renderer repaints by absolute row and clamps cursorTo(), so an over-wide
     * row lands on the status bar.
     *
     * Measured with Width::string(Ansi::strip()) rather than strlen: the whole
     * bug is that byte count and display width disagree.
     */
    public function testTabIndentedDiffRowsNeverExceedTheRequestedWidth(): void
    {
        $render = new \ReflectionMethod(Renderer::class, 'renderDiff');
        $render->setAccessible(true);
        $theme = $this->chat()->theme();

        $fixtures = [
            'makefile' => "--- a/Makefile\n+++ b/Makefile\n@@ -1,3 +1,3 @@\n build:\n-\techo old\n+\techo new\n",
            'go'       => "--- a/m.go\n+++ b/m.go\n@@ -5,4 +5,4 @@\n func f() {\n-\t\treturn 1\n+\t\treturn 2\n }\n",
            'deep'     => "--- a/x\n+++ b/x\n@@ -1,2 +1,2 @@\n-\t\t\t\tdeeply nested tabbed line\n+\t\t\t\tdeeply nested tabbed lino\n",
            'cjk-tab'  => "--- a/x\n+++ b/x\n@@ -1,2 +1,2 @@\n-\t日本語のテキスト\n+\t\t中文文本在这里\n",
            'emoji'    => "--- a/x\n+++ b/x\n@@ -1,2 +1,2 @@\n-\t🧑‍🚀🧑‍🚀 astronauts 👨‍👩‍👧‍👦\n+\t\t👍🏽 ok\n",
            'raw-esc'  => "--- a/x\n+++ b/x\n@@ -1,2 +1,2 @@\n-\x1b[31mforged\x1b[0m\n+\x1b[1;32m\tforged\x1b[0m\n",
            'six-digit' => "--- a/x\n+++ b/x\n@@ -999999,3 +999999,3 @@\n ctx\n-\told\n+\tnew\n",
        ];

        foreach ($fixtures as $name => $diff) {
            // 13 upward: below that the pre-existing max(8, $width - 4) floor
            // makes the box a fixed 12 cells regardless of the request.
            for ($width = 13; $width <= 120; $width++) {
                foreach (explode("\n", $render->invoke(null, $diff, $theme, $width)) as $row) {
                    $this->assertLessThanOrEqual(
                        $width,
                        Width::string(Ansi::strip($row)),
                        "{$name} at width {$width}: " . Ansi::strip($row),
                    );
                }
            }
        }
    }

    /**
     * The same invariant through the real render() path, at the two widths
     * either side of the gutter-drop boundary and at DIFF_MIN_BODY_COLS.
     */
    public function testATabIndentedDiffFitsTheFrameAcrossTheGutterDropBoundary(): void
    {
        $diff = "--- a/Makefile\n+++ b/Makefile\n@@ -1,3 +1,3 @@\n build:\n-\techo old\n+\techo new\n";

        foreach ([80, 100, 120] as $cols) {
            foreach (explode("\n", Renderer::render($this->sizedChat([$this->editResult($diff)], cols: $cols))) as $row) {
                $this->assertLessThanOrEqual(
                    $cols,
                    Width::string(Ansi::strip($row)),
                    "cols {$cols}: " . Ansi::strip($row),
                );
            }
        }
    }

    /** Tabs are expanded, not dropped -- the indentation still reads as depth. */
    public function testTabIndentationSurvivesAsSpaces(): void
    {
        $diff = "--- a/Makefile\n+++ b/Makefile\n@@ -1,2 +1,2 @@\n build:\n-\techo old\n";
        $out = Renderer::render($this->sizedChat([$this->editResult($diff)]));

        $this->assertStringContainsString('-    echo old', $out);
        $this->assertStringNotContainsString("\t", $out);
    }

    /**
     * `--` opens a comment in SQL, Lua, Haskell and Ada, so a deleted
     * `-- users table` arrives as `--- users table` and used to be coloured as
     * a bold file header instead of a red removal. The verdict now comes from
     * DiffGutter::fileHeaders(), which knows whether a hunk is open, so the
     * colour and the line number can no longer disagree about what a row is.
     */
    public function testADeletedCommentRowIsColouredAsARemovalNotAFileHeader(): void
    {
        $diff = "--- a/schema.sql\n+++ b/schema.sql\n@@ -10,3 +10,3 @@\n CREATE TABLE users (\n--- users table, legacy\n";
        $chat = $this->sizedChat([$this->editResult($diff)]);
        $theme = $chat->theme();

        $out = Renderer::render($chat);

        $this->assertStringContainsString(
            Style::new()->foreground(Color::ansi(1))->render('--- users table, legacy'),
            $out,
        );
        $this->assertStringNotContainsString(
            Style::new()->foreground($theme->systemLabel)->bold()->render('--- users table, legacy'),
            $out,
        );
        // The genuine header above it is still a header.
        $this->assertStringContainsString(
            Style::new()->foreground($theme->systemLabel)->bold()->render('--- a/schema.sql'),
            $out,
        );
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

    /**
     * Exactly the keys Chat::handlePermissionKey() accepts while the prompt is
     * ARMED, and no others.
     *
     * The `a` label now says it ASKS rather than allows, because that is what
     * the key does: it raises the confirm below. A modal still promising a
     * one-keystroke session grant would read as a bug the first time someone
     * pressed it.
     */
    public function testPermissionPromptAdvertisesTheThreeAnswerKeys(): void
    {
        $out = Renderer::render($this->chatAwaitingPermission());

        $this->assertStringContainsString('allow once', $out);
        $this->assertStringContainsString('allow always', $out);
        $this->assertStringContainsString('asks first', $out);
        $this->assertStringContainsString('reject', $out);
        $this->assertStringContainsString('n / Esc', $out);
    }

    /**
     * A prompt disarmed by a stray keystroke looks identical to a live one, so
     * without this the fix would trade a silent grant for a silently dead
     * modal: the user presses `y`, nothing happens, and nothing on screen says
     * why or what to do about it.
     *
     * Both halves are asserted — the cue, by the same constant the renderer
     * paints rather than a hand-copied literal, and the way back.
     */
    public function testADisarmedPromptSaysSoAndSaysHowToGetBack(): void
    {
        [$disarmed] = $this->chatAwaitingPermission()->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertSame(PermissionPromptStage::Disarmed, $disarmed->permissionStage());

        $out = Renderer::render($disarmed);

        $this->assertStringContainsString('permission required', $out, 'the question is still on screen');
        $this->assertStringContainsString(Renderer::PERMISSION_DISARMED_NOTICE, $out);
        $this->assertStringContainsString('Enter', $out, 'and the key that makes the answers live again');
        $this->assertStringContainsString('listen for an answer again', $out);
        $this->assertStringNotContainsString(
            'allow once',
            $out,
            'and it must NOT keep advertising keys that now do nothing — that is the promise this state '
            . 'exists to stop making',
        );
    }

    /**
     * The confirm REPLACES the question's own keys rather than being added
     * under them: while it is up, `y` means "the whole session", not "this one
     * call", and a modal showing both meanings at once is how a session grant
     * becomes a slip again.
     */
    public function testTheAlwaysConfirmIsRenderedWithItsOwnQuestionAndKeys(): void
    {
        [$confirming] = $this->chatAwaitingPermission()->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(PermissionPromptStage::ConfirmingAlways, $confirming->permissionStage());

        $out = Renderer::render($confirming);

        $this->assertStringContainsString('Allow every later Bash call this session?', $out);
        $this->assertStringContainsString('back to the question', $out);
        $this->assertStringNotContainsString(
            'allow once',
            $out,
            'the base prompt\'s keys are gone: "y" does not mean "once" in this stage',
        );
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

    // =========================================================================
    // crush_feat.md §1 E7: denied / interrupted tool-call visual states
    // =========================================================================

    /** One tool-result turn, so a whole frame can be rendered around it. */
    private function resultMessage(\SugarCraft\Crush\ToolResult $result): Message
    {
        return Message::assistant('')->withToolResults([$result]);
    }

    /**
     * The step-defining regression: a refused call used to render with the
     * exact same "✗ error" row as a call that ran and failed, so nothing on
     * screen distinguished "the tool blew up" from "the tool never ran". It
     * now takes the struck-through row §1 E7 asks for - a distinct visual
     * state, not just a colour.
     */
    public function testDeniedToolCallRowIsStruckThrough(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::error('bash', 'Permission denied: bash was not run.', 'call_1'),
        )]);
        $theme = $chat->theme();

        $out = Renderer::render($chat);

        $this->assertStringContainsString(
            Style::new()->foreground($theme->systemLabel)->faint()->strikethrough()->render('🔧 tool: bash'),
            $out,
        );
        $this->assertStringContainsString(
            Style::new()->foreground($theme->systemLabel)->bold()->strikethrough()->render('⊘ denied'),
            $out,
        );
        $this->assertStringNotContainsString('✗ error', $out);
        // The reason is the point of the row, so it stays readable.
        $this->assertStringContainsString('Permission denied: bash was not run.', $out);
    }

    /**
     * A restart-orphaned call ({@see Chat::reviveCheckpointMessage()}) is also
     * "never ran", so it takes the same strikethrough with its own word.
     */
    public function testInterruptedToolCallRowIsStruckThroughAndNamedDistinctly(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::error('bash', Chat::INTERRUPTED_TOOL_CALL, 'call_1'),
        )]);
        $theme = $chat->theme();

        $out = Renderer::render($chat);

        $this->assertStringContainsString(
            Style::new()->foreground($theme->systemLabel)->bold()->strikethrough()->render('⊘ interrupted'),
            $out,
        );
        $this->assertStringNotContainsString('⊘ denied', $out);
    }

    /**
     * The counterpart guard: a call that genuinely ran and failed keeps the
     * plain error row, un-struck, or the new state would mean nothing.
     */
    public function testAGenuineFailureKeepsThePlainErrorRow(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::error('bash', 'exit status 1', 'call_1'),
        )]);
        $theme = $chat->theme();

        $out = Renderer::render($chat);

        $this->assertStringContainsString(
            Style::new()->foreground($theme->systemLabel)->faint()->render('🔧 tool: bash'),
            $out,
        );
        $this->assertStringContainsString('✗ error', $out);
        $this->assertStringNotContainsString('⊘', $out);
    }

    /**
     * A successful call is untouched by any of this - no strikethrough, no
     * refusal glyph.
     */
    public function testSuccessfulToolCallIsUnaffected(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::ok('bash', 'total 0', 'call_1'),
        )]);

        $out = Renderer::render($chat);

        $this->assertStringContainsString('✓ ok', $out);
        $this->assertStringNotContainsString('⊘', $out);
    }

    /**
     * The new glyph must stay OUT of the Unicode private-use block: {@see
     * Renderer::maskImageMarkers()} masks U+E000-U+F8FF out of the copy the
     * mouse {@see \SugarCraft\Mouse\Scanner} reads, so a PUA glyph here would
     * silently shift click zones.
     */
    public function testTheRefusalGlyphIsNotInThePrivateUseBlock(): void
    {
        $code = mb_ord('⊘');

        $this->assertIsInt($code);
        $this->assertTrue($code < 0xE000 || $code > 0xF8FF, 'refusal glyph collides with the marker block');
    }

    // ---------------------------------------------------------------
    // Live session picker overlay (crush_feat.md section 5 E8)
    // ---------------------------------------------------------------

    private function pickerChat(int $cols = 100): Chat
    {
        $store = new SessionStore(':memory:');
        $store->createSession('sess-a', 'sugarcrush', 'test-model', null, 'Alpha');
        $store->createSession('sess-b', 'sugarcrush', 'test-model', null, 'Beta');

        $chat = new Chat(
            history: [Message::user('hello')],
            sessionStore: $store,
            currentSessionId: 'sess-a',
            rows: 24,
            cols: $cols,
        );

        [$opened] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        self::assertNotNull($opened->sessionPicker());

        return $opened;
    }

    public function testOpenSessionPickerIsCompositedOverTheFrame(): void
    {
        $out = (string) Renderer::render($this->pickerChat());

        $this->assertStringContainsString('session picker', $out);
        $this->assertStringContainsString('Alpha', $out);
        $this->assertStringContainsString('Beta', $out);
        $this->assertStringContainsString('esc close', $out);
    }

    /**
     * The picker is the widest overlay the renderer composites, so it is the
     * one that exposes both halves of the width budget: its own
     * border + padding(0, 1) wraps AROUND the width it is handed, and Veil
     * centres it against a backdrop still measured WITH its zone sentinels.
     * Get either wrong and a row runs past the terminal, which the diff
     * renderer (one logical line per physical row) turns into the
     * absolute-cursorTo row collision that Renderer::render()'s tail clip
     * exists to prevent.
     *
     * @dataProvider pickerTerminalWidths
     */
    public function testOpenSessionPickerNeverPaintsPastTheTerminalWidth(int $cols): void
    {
        $chat = $this->pickerChat($cols);

        $out = (string) Renderer::render($chat);

        foreach (explode("\n", $out) as $index => $line) {
            $this->assertLessThanOrEqual(
                $cols,
                Width::string($line),
                "line {$index} overflows a {$cols}-column terminal",
            );
        }
    }

    /** @return array<string, array{int}> */
    public static function pickerTerminalWidths(): array
    {
        return [
            '70 columns' => [70],
            '76 columns' => [76],
            '80x24 canonical' => [80],
            '100 columns' => [100],
        ];
    }

    public function testClosedSessionPickerCompositesNothing(): void
    {
        [$closed] = $this->pickerChat()->update(new KeyMsg(KeyType::Escape, ''));

        $out = (string) Renderer::render($closed);

        $this->assertStringNotContainsString('session picker', $out);
    }

    /**
     * The user-reported regression behind crush_feat.md §3 E2: expanding a
     * finished tool call showed its OUTPUT but the row never said which
     * command produced it. Against the old renderer this fails - the finished
     * row was `🔧 tool: bash ✓ ok` and the `ls -la` only ever appeared on the
     * transient running placeholder.
     */
    public function testFinishedToolRowShowsTheCommandThatRan(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::ok('bash', 'a.txt', 'call_1')
                ->withDescription('bash(command: "ls -la")'),
        )]);

        $out = Renderer::render($chat);

        $this->assertStringContainsString('tool: bash', $out);
        $this->assertStringContainsString('ls -la', $out);
    }

    /**
     * The tail is drawn AFTER the status marker on purpose: markToolCalls()
     * locates the clickable row by str_contains() of the label it recorded,
     * so the un-suffixed label has to stay a verbatim prefix of the row or
     * click-to-expand would start missing rows.
     */
    public function testCommandTailIsAppendedAfterTheStatusMarkerNotBeforeIt(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::ok('bash', 'a.txt', 'call_1')
                ->withDescription('bash(command: "ls -la")'),
        )]);
        $theme = $chat->theme();

        $out = Renderer::render($chat);
        $label = Style::new()->foreground($theme->systemLabel)->faint()->render('🔧 tool: bash')
            . ' ' . Style::new()->foreground($theme->assistantLabel)->bold()->render('✓ ok');

        $this->assertStringContainsString($label, $out);
        $this->assertStringContainsString(
            $label . Style::new()->foreground($theme->systemLabel)->faint()->render(' — bash(command: "ls -la")'),
            $out,
        );
    }

    /** A result with no known call keeps exactly the row it always had. */
    public function testToolRowWithoutADescriptionIsUnchanged(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::ok('bash', 'a.txt', 'call_1'),
        )]);
        $theme = $chat->theme();

        $out = Renderer::render($chat);

        $this->assertStringContainsString(
            Style::new()->foreground($theme->systemLabel)->faint()->render('🔧 tool: bash')
                . ' ' . Style::new()->foreground($theme->assistantLabel)->bold()->render('✓ ok'),
            $out,
        );
        $this->assertStringNotContainsString('—', $out);
    }

    /**
     * The tail is model-authored text on a row candy-core repaints by
     * absolute position, so it must never push the row past the terminal.
     */
    public function testCommandTailIsTruncatedToTheTerminalWidth(): void
    {
        $cols = 60;
        $chat = $this->sizedChat(
            [$this->resultMessage(
                \SugarCraft\Crush\ToolResult::ok('bash', 'done', 'call_1')
                    ->withDescription('bash(command: "' . str_repeat('very-long-argument ', 20) . '")'),
            )],
            $cols,
        );

        $out = Renderer::render($chat);

        $rows = array_values(array_filter(
            explode("\n", $out),
            static fn(string $line): bool => str_contains($line, 'tool: bash'),
        ));

        $this->assertCount(1, $rows, 'the tail must stay on the one row it belongs to');
        $this->assertLessThanOrEqual($cols, Width::string($rows[0]));
    }

    /**
     * Raw ESC in a model-authored description would forge SGR straight onto
     * the terminal wire, exactly as the rest of this renderer's untrusted
     * strings are guarded against.
     */
    public function testCommandTailIsScrubbedOfControlBytes(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::ok('bash', 'done', 'call_1')
                ->withDescription("bash(command: \"\x1b[31mred\x07\")"),
        )]);

        $out = Renderer::render($chat);

        $this->assertStringContainsString('red', $out);
        $this->assertStringNotContainsString("\x1b[31m", $out);
        $this->assertStringNotContainsString("\x07", $out);
    }

    /**
     * A tail so squeezed it could only show a couple of columns of the
     * command is not worth the status marker's breathing room.
     */
    public function testNoTailIsDrawnWhenTheRowHasNoRoomForOne(): void
    {
        $chat = $this->sizedChat(
            [$this->resultMessage(
                \SugarCraft\Crush\ToolResult::ok(str_repeat('n', 40), 'done', 'call_1')
                    ->withDescription('ls -la'),
            )],
            26,
        );

        $out = Renderer::render($chat);

        $this->assertStringNotContainsString('ls -la', $out);
    }

    // =========================================================================
    // Image-bearing tool results: caption + collapse (crush_feat.md §9 E3 read
    // through §1 E5). User-reported: `/doctor` "shows just a big green box for
    // output, nothing else, not collapsable or expandable" - the swatch was
    // painted unconditionally, at up to a full viewport of rows, so it evicted
    // its own tool row from the tail-clipped transcript AND ignored Ctrl+O.
    // =========================================================================

    /** A real, decodable PNG — candy-mosaic refuses anything it cannot decode. */
    private function pngBytes(int $width = 20, int $height = 10): string
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($gd, 46, 160, 74));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    /** The shape `/doctor` produces: a summary string plus a capability swatch. */
    private function doctorChat(?\SugarCraft\Mosaic\Mosaic $mosaic, ?string $bytes = null, int $rows = 40): Chat
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }

        return new Chat(
            history: [$this->resultMessage(new \SugarCraft\Crush\ToolResult(
                name: 'doctor',
                result: 'Detected pixel-graphics protocol: sixel.',
                id: 'call_img',
                imageBytes: $bytes ?? $this->pngBytes(),
                imageProtocol: 'sixel',
            ))],
            rows: $rows,
            cols: 80,
            mosaic: $mosaic,
        );
    }

    /**
     * The step-defining regression: collapsed, the picture is replaced by a
     * one-line affordance, and the tool row plus the result's own summary -
     * the picture's only caption - are both on screen.
     */
    public function testCollapsedImageResultShowsItsRowCaptionAndExpandAffordance(): void
    {
        $out = Renderer::render($this->doctorChat(\SugarCraft\Mosaic\Mosaic::halfBlock()));

        $this->assertStringContainsString('tool: doctor', $out);
        $this->assertStringContainsString('Detected pixel-graphics protocol: sixel.', $out);
        $this->assertStringContainsString('image hidden (ctrl+o)', $out);
        $this->assertStringNotContainsString('▀', $out, 'a collapsed picture must not be painted at all');
    }

    /** The affordance answers "how big" and "with what", the swatch's whole point. */
    public function testCollapsedImageNoticeNamesTheSourceDimensionsAndProtocol(): void
    {
        $out = Renderer::render($this->doctorChat(\SugarCraft\Mosaic\Mosaic::halfBlock()));

        $this->assertStringContainsString('20×10 sixel image hidden (ctrl+o)', $out);
    }

    /**
     * Ctrl+O (and the click zone that shares its key, §8 E5) now actually
     * reaches the picture: expanding paints it and drops the affordance.
     */
    public function testExpandingAnImageResultPaintsThePictureAndDropsTheAffordance(): void
    {
        $chat = $this->doctorChat(\SugarCraft\Mosaic\Mosaic::halfBlock())->toggleToolOutput('call_img');

        $out = Renderer::render($chat);

        $this->assertStringContainsString('▀', $out, 'half-block cells must be visible once expanded');
        $this->assertStringNotContainsString('image hidden (ctrl+o)', $out);
        $this->assertStringContainsString('Detected pixel-graphics protocol: sixel.', $out);
    }

    /** A collapsed picture is never decoded, so it never reaches the image layer. */
    public function testCollapsedPixelGraphicsImageRegistersNoPlacement(): void
    {
        $chat = $this->doctorChat(\SugarCraft\Mosaic\Mosaic::sixel());

        $this->assertSame([], Renderer::renderView($chat)->images);
        $this->assertNotSame([], Renderer::renderView($chat->toggleToolOutput('call_img'))->images);
    }

    /**
     * The reported failure mode itself: a tall source is budgeted the whole
     * viewport minus two rows, so painting it unconditionally pushed the tool
     * row - and everything else - off the tail-clipped transcript.
     */
    public function testATallImageNoLongerEvictsItsOwnToolRow(): void
    {
        $out = Renderer::render($this->doctorChat(\SugarCraft\Mosaic\Mosaic::halfBlock(), $this->pngBytes(16, 1600), 12));

        $this->assertStringContainsString('tool: doctor', $out);
        $this->assertStringContainsString('image hidden (ctrl+o)', $out);
    }

    /**
     * With no probed protocol expanding could only ever reveal nothing, so the
     * affordance would be a promise the renderer cannot keep - the caption
     * still carries the result.
     */
    public function testImageResultWithoutAMosaicOffersNoExpandAffordance(): void
    {
        $out = Renderer::render($this->doctorChat(null));

        $this->assertStringContainsString('tool: doctor', $out);
        $this->assertStringContainsString('Detected pixel-graphics protocol: sixel.', $out);
        $this->assertStringNotContainsString('image hidden (ctrl+o)', $out);
    }

    /** Unreadable bytes cost the dimensions, not the row. */
    public function testCollapsedImageNoticeOmitsDimensionsItCannotRead(): void
    {
        $out = Renderer::render($this->doctorChat(\SugarCraft\Mosaic\Mosaic::halfBlock(), 'definitely-not-a-png'));

        $this->assertStringContainsString('sixel image hidden (ctrl+o)', $out);
        $this->assertStringNotContainsString('×', $out);
    }

    /** A text-only success keeps §1 E5's hide-the-body policy untouched. */
    public function testTextOnlySuccessBodyIsStillHiddenWhenCollapsed(): void
    {
        $chat = $this->sizedChat([$this->resultMessage(
            \SugarCraft\Crush\ToolResult::ok('grep', "alpha\nbeta", 'call_1'),
        )]);

        $out = Renderer::render($chat);

        $this->assertStringNotContainsString('alpha', $out);
        $this->assertStringContainsString('2 lines hidden (ctrl+o)', $out);
    }
}

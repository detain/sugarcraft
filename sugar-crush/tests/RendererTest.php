<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
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
        $this->assertStringContainsString('42', $out);
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
}

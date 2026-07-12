<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use PHPUnit\Framework\TestCase;

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
}

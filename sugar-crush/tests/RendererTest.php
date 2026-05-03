<?php

declare(strict_types=1);

namespace CandyCore\Crush\Tests;

use CandyCore\Crush\Chat;
use CandyCore\Crush\Message;
use CandyCore\Crush\Renderer;
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
}

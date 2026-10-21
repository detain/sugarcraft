<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Util\Exporter;

/**
 * @see Exporter
 */
final class ExporterTest extends TestCase
{
    // =========================================================================
    // toMarkdown Tests
    // =========================================================================

    public function testToMarkdownWithUserMessage(): void
    {
        $messages = [
            new UserMessage('Hello, world!'),
        ];

        $output = Exporter::toMarkdown($messages);

        $this->assertStringContainsString('### User', $output);
        $this->assertStringContainsString('Hello, world!', $output);
    }

    public function testToMarkdownWithAssistantMessage(): void
    {
        $messages = [
            new AssistantMessage('I am an assistant'),
        ];

        $output = Exporter::toMarkdown($messages);

        $this->assertStringContainsString('### Assistant', $output);
        $this->assertStringContainsString('I am an assistant', $output);
    }

    public function testToMarkdownWithSystemMessage(): void
    {
        $messages = [
            new SystemMessage('You are helpful'),
        ];

        $output = Exporter::toMarkdown($messages);

        $this->assertStringContainsString('### System', $output);
        $this->assertStringContainsString('You are helpful', $output);
    }

    public function testToMarkdownWithToolResultMessage(): void
    {
        $messages = [
            new ToolResultMessage('call_123', 'Tool execution result'),
        ];

        $output = Exporter::toMarkdown($messages);

        $this->assertStringContainsString('### Tool', $output);
        $this->assertStringContainsString('Tool execution result', $output);
    }

    public function testToMarkdownWithMixedMessages(): void
    {
        $messages = [
            new SystemMessage('You are a helpful assistant'),
            new UserMessage('What is the weather?'),
            new AssistantMessage('The weather is sunny.'),
            new ToolResultMessage('call_1', 'Weather data retrieved'),
        ];

        $output = Exporter::toMarkdown($messages);

        $this->assertStringContainsString('### System', $output);
        $this->assertStringContainsString('### User', $output);
        $this->assertStringContainsString('### Assistant', $output);
        $this->assertStringContainsString('### Tool', $output);
    }

    public function testToMarkdownWithEmptyArray(): void
    {
        $output = Exporter::toMarkdown([]);

        $this->assertSame('', $output);
    }

    public function testToMarkdownWithUnknownMessageType(): void
    {
        // Create an anonymous class implementing Message
        $unknownMessage = new class implements Message {
            public function role(): string { return 'unknown'; }
            public function content(): string { return 'Unknown content'; }
            public function toArray(): array { return ['role' => 'unknown', 'content' => 'Unknown content']; }
        };

        $output = Exporter::toMarkdown([$unknownMessage]);

        $this->assertStringContainsString('### Unknown', $output);
        $this->assertStringContainsString('Unknown content', $output);
    }

    public function testToMarkdownSeparatesMessagesWithHorizontalRule(): void
    {
        $messages = [
            new UserMessage('First message'),
            new UserMessage('Second message'),
        ];

        $output = Exporter::toMarkdown($messages);

        $this->assertStringContainsString("\n---\n", $output);
    }

    // =========================================================================
    // toJson Tests
    // =========================================================================

    public function testToJsonWithUserMessage(): void
    {
        $messages = [
            new UserMessage('Hello'),
        ];

        $output = Exporter::toJson($messages);

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertCount(1, $decoded);
        $this->assertSame('user', $decoded[0]['role']);
        $this->assertSame('Hello', $decoded[0]['content']);
    }

    public function testToJsonWithAssistantMessage(): void
    {
        $messages = [
            new AssistantMessage('Response'),
        ];

        $output = Exporter::toJson($messages);

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertSame('assistant', $decoded[0]['role']);
        $this->assertSame('Response', $decoded[0]['content']);
    }

    public function testToJsonWithSystemMessage(): void
    {
        $messages = [
            new SystemMessage('You are helpful'),
        ];

        $output = Exporter::toJson($messages);

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertSame('system', $decoded[0]['role']);
    }

    public function testToJsonWithToolResultMessage(): void
    {
        $messages = [
            new ToolResultMessage('call_123', 'Result content'),
        ];

        $output = Exporter::toJson($messages);

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertSame('tool', $decoded[0]['role']);
        $this->assertSame('call_123', $decoded[0]['tool_call_id']);
        $this->assertSame('Result content', $decoded[0]['content']);
    }

    public function testToJsonWithMixedMessages(): void
    {
        $messages = [
            new SystemMessage('You are helpful'),
            new UserMessage('Question'),
            new AssistantMessage('Answer'),
            new ToolResultMessage('call_1', 'Result'),
        ];

        $output = Exporter::toJson($messages);

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertCount(4, $decoded);
    }

    public function testToJsonWithEmptyArray(): void
    {
        $output = Exporter::toJson([]);

        $this->assertSame('[]', $output);
    }

    public function testToJsonWithNonMessageArray(): void
    {
        // When a non-Message object is passed, it uses toArray if available
        $messages = [
            new UserMessage('Hello'),
            ['role' => 'custom', 'content' => 'Custom message'],
        ];

        $output = Exporter::toJson($messages);

        $this->assertJson($output);
        $decoded = json_decode($output, true);
        $this->assertCount(2, $decoded);
        $this->assertSame('Hello', $decoded[0]['content']);
        $this->assertSame('Custom message', $decoded[1]['content']);
    }

    public function testToJsonIsPrettyPrinted(): void
    {
        $messages = [
            new UserMessage('Hello'),
        ];

        $output = Exporter::toJson($messages);

        // JSON_PRETTY_PRINT adds newlines and indentation
        $this->assertStringContainsString("\n", $output);
    }

    public function testToJsonEscapesSlashes(): void
    {
        $messages = [
            new UserMessage('https://example.com'),
        ];

        $output = Exporter::toJson($messages);

        // JSON_UNESCAPED_SLASHES should keep slashes unescaped
        $this->assertStringNotContainsString('\/', $output);
        $this->assertStringContainsString('https://example.com', $output);
    }

    // =========================================================================
    // toText Tests
    // =========================================================================

    public function testToTextWithUserMessage(): void
    {
        $messages = [
            new UserMessage('Hello'),
        ];

        $output = Exporter::toText($messages);

        $this->assertStringContainsString('[User]', $output);
        $this->assertStringContainsString('Hello', $output);
    }

    public function testToTextWithAssistantMessage(): void
    {
        $messages = [
            new AssistantMessage('Response'),
        ];

        $output = Exporter::toText($messages);

        $this->assertStringContainsString('[Assistant]', $output);
        $this->assertStringContainsString('Response', $output);
    }

    public function testToTextWithSystemMessage(): void
    {
        $messages = [
            new SystemMessage('You are helpful'),
        ];

        $output = Exporter::toText($messages);

        $this->assertStringContainsString('[System]', $output);
        $this->assertStringContainsString('You are helpful', $output);
    }

    public function testToTextWithToolResultMessage(): void
    {
        $messages = [
            new ToolResultMessage('call_123', 'Result content'),
        ];

        $output = Exporter::toText($messages);

        $this->assertStringContainsString('[Tool]', $output);
        $this->assertStringContainsString('Result content', $output);
    }

    public function testToTextWithMixedMessages(): void
    {
        $messages = [
            new UserMessage('First'),
            new AssistantMessage('Second'),
        ];

        $output = Exporter::toText($messages);

        $this->assertStringContainsString('[User]', $output);
        $this->assertStringContainsString('[Assistant]', $output);
    }

    public function testToTextWithEmptyArray(): void
    {
        $output = Exporter::toText([]);

        $this->assertSame('', $output);
    }

    public function testToTextSeparatesMessagesWithNewline(): void
    {
        $messages = [
            new UserMessage('First'),
            new UserMessage('Second'),
        ];

        $output = Exporter::toText($messages);

        $this->assertStringContainsString("\n", $output);
    }

    public function testToTextWithUnknownMessageType(): void
    {
        $unknownMessage = new class implements Message {
            public function role(): string { return 'custom'; }
            public function content(): string { return 'Custom'; }
            public function toArray(): array { return ['role' => 'custom', 'content' => 'Custom']; }
        };

        $output = Exporter::toText([$unknownMessage]);

        $this->assertStringContainsString('[Unknown]', $output);
    }

    // =========================================================================
    // Comparison Tests
    // =========================================================================

    public function testAllThreeFormatsHandleSameMessages(): void
    {
        $messages = [
            new SystemMessage('System prompt'),
            new UserMessage('User input'),
            new AssistantMessage('Assistant output'),
            new ToolResultMessage('call_1', 'Tool result'),
        ];

        $markdown = Exporter::toMarkdown($messages);
        $json = Exporter::toJson($messages);
        $text = Exporter::toText($messages);

        // All should contain the actual content
        $this->assertStringContainsString('System prompt', $markdown);
        $this->assertStringContainsString('System prompt', $json);
        $this->assertStringContainsString('System prompt', $text);

        $this->assertStringContainsString('User input', $markdown);
        $this->assertStringContainsString('User input', $json);
        $this->assertStringContainsString('User input', $text);

        $this->assertStringContainsString('Assistant output', $markdown);
        $this->assertStringContainsString('Assistant output', $json);
        $this->assertStringContainsString('Assistant output', $text);

        $this->assertStringContainsString('Tool result', $markdown);
        $this->assertStringContainsString('Tool result', $json);
        $this->assertStringContainsString('Tool result', $text);
    }

    /**
     * Roadmap 1.B-2: a row the transcript hides (a turn's step record, a
     * harness nudge — {@see \SugarCraft\Code\Message::$userVisible} false)
     * is skipped by every export format and by a share, the same rule the
     * transcript's own paint applies.
     */
    public function testRowsTheTranscriptHidesAreLeftOutOfEveryFormatAndAShare(): void
    {
        $messages = [
            \SugarCraft\Code\Message::user('VISIBLE-QUESTION'),
            \SugarCraft\Code\Message::assistant('HIDDEN-STEP-NARRATION')->withUserVisible(false),
            \SugarCraft\Code\Message::user('HIDDEN-NUDGE')->withUserVisible(false),
            \SugarCraft\Code\Message::assistant('VISIBLE-ANSWER'),
        ];

        foreach ([
            'markdown' => Exporter::toMarkdown($messages),
            'json' => Exporter::toJson($messages),
            'text' => Exporter::toText($messages),
            'html' => Exporter::toHtml($messages),
        ] as $format => $out) {
            $this->assertStringContainsString('VISIBLE-QUESTION', $out, $format);
            $this->assertStringContainsString('VISIBLE-ANSWER', $out, $format);
            $this->assertStringNotContainsString('HIDDEN-STEP-NARRATION', $out, $format);
            $this->assertStringNotContainsString('HIDDEN-NUDGE', $out, $format);
        }

        $share = new \SugarCraft\Code\Share\ShareSession($messages);
        $this->assertSame(2, $share->messageCount());
        $this->assertStringNotContainsString('HIDDEN-STEP-NARRATION', $share->serialize());
    }

    /**
     * SEC-4 (audit 2026-10-21): `/share` publishes the transcript behind a
     * URL — the memory audit trail already redacts, so the published body
     * must run the same SecretRedactor. The boundary is deliberate: a share
     * is redacted in every format, a LOCAL export keeps the raw bytes (it
     * never leaves the machine), pinned by the polarity pair below.
     */
    public function testAPublishedShareRedactsSecretsWhileALocalExportKeepsThem(): void
    {
        $key = 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $messages = [
            \SugarCraft\Code\Message::user('WHAT IS MY KEY'),
            \SugarCraft\Code\Message::assistant("Your key is {$key} — keep it safe."),
        ];

        foreach ([
            \SugarCraft\Code\Share\ShareSession::FORMAT_MARKDOWN,
            \SugarCraft\Code\Share\ShareSession::FORMAT_JSON,
            \SugarCraft\Code\Share\ShareSession::FORMAT_TEXT,
            \SugarCraft\Code\Share\ShareSession::FORMAT_HTML,
        ] as $format) {
            $body = (new \SugarCraft\Code\Share\ShareSession($messages, $format))->serialize();
            $this->assertStringNotContainsString($key, $body, $format);
            $this->assertStringContainsString(\SugarCraft\Code\Memory\SecretRedactor::MARKER, $body, $format);
            $this->assertStringContainsString('WHAT IS MY KEY', $body, $format);
        }

        // The redactor changes nothing that is not secret-shaped.
        $benign = (new \SugarCraft\Code\Share\ShareSession([
            \SugarCraft\Code\Message::assistant('a plain honest sentence'),
        ]))->serialize();
        $this->assertStringNotContainsString(\SugarCraft\Code\Memory\SecretRedactor::MARKER, $benign);

        // Polarity: the local exporter stays raw — this is the publish seam.
        $this->assertStringContainsString($key, \SugarCraft\Code\Util\Exporter::toMarkdown($messages));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;

/**
 * S-O21 (once-022): a tool-result image over the model budget used to fall back
 * to caption text with no word to the user. It now announces the drop once per
 * payload, naming bytes against budget, while the wire stays caption-only.
 */
final class AnthropicOversizeAttachmentNoticeTest extends TestCase
{
    public function testAnOversizeImageAnnouncesItsDropOncePerPayload(): void
    {
        $over = "\x89PNG\r\n\x1a\n" . str_repeat('o', AnthropicProvider::TOOL_RESULT_IMAGE_MAX_BYTES);
        $provider = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-sonnet-4-6', static fn (): array => ['content' => []], null, [], false);
        $request = new CompleteRequest(model: 'claude-sonnet-4-6', messages: [
            new ToolResultMessage(toolCallId: 'tu_1', content: 'caption', imageBytes: $over, imageProtocol: 'sixel'),
        ]);

        RuntimeNoticeSink::arm(false);
        try {
            RuntimeNoticeSink::drain();
            $provider->complete($request);
            $rows = RuntimeNoticeSink::drain();

            self::assertCount(1, $rows);
            self::assertStringContainsString(number_format(strlen($over)), $rows[0]);
            self::assertStringContainsString(number_format(AnthropicProvider::TOOL_RESULT_IMAGE_MAX_BYTES), $rows[0]);

            $provider->complete($request);
            self::assertSame([], RuntimeNoticeSink::drain(), 'the per-step replay stays silent');
        } finally {
            RuntimeNoticeSink::drain();
        }
    }
}

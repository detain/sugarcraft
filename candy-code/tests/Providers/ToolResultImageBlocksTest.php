<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\AttachmentEncoding;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\VertexProvider;

/**
 * SHL-3 T2: an image a tool CARRIED (T1: Read's in-cap image) rides into the
 * model's context as an `image` block inside the Anthropic family's
 * `tool_result` — but only through the wire's explicit gate chain, and every
 * refusal in that chain lands on the caption STRING, never on a silently
 * different wire shape.
 *
 * THE GATE POLES pinned below: vision vs blind model (`supportsVision()`),
 * inside vs over the model budget (`TOOL_RESULT_IMAGE_MAX_BYTES`, the 256KiB
 * text-page contract reused — pinned as lineage), sniffable vs rotted header
 * (the bytes are RE-sniffed at the wire: trusting the tool's earlier verdict
 * would let forged bytes reach the API and 400 the whole turn), result vs
 * error (`is_error` rows are text by construction). And the DECISION the
 * sibling OpenAI-family wires record in
 * {@see AnthropicMessagesWire::toolResultImage()} — no follow-up-user-turn
 * workaround there — is pinned from this side by the blind-model row: on a
 * wire that cannot see, the caption alone is the deliberate answer, proven
 * byte-stable below so the choice never degrades the prompt prefix.
 *
 * Both users of the shared wire are exercised: the native Messages endpoint
 * and Vertex's `:rawPredict` arm, which must land the identical block.
 */
final class ToolResultImageBlocksTest extends TestCase
{
    private const VISION_MODEL = 'claude-sonnet-4-6';
    private const BLIND_MODEL = 'mistral-large-latest';
    private const VERTEX_MODEL = 'claude-3-sonnet@20240229';
    private const CAPTION = 'Binary image (image/png), 72 bytes — caption';

    private static function png(): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat('a', 64);
    }

    /** @param-out array{0: string, 1: array<string, mixed>} $captured */
    private function nativeProvider(string $model, array &$captured): AnthropicProvider
    {
        $captured = ['', []];
        $seam = static function (string $endpoint, array $body) use (&$captured): array {
            $captured = [$endpoint, $body];

            return ['content' => []];
        };

        return new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            $model,
            $seam,
            null,
            [],
            false,
        );
    }

    /** @param-out array{body: array<string, mixed>} $captured */
    private function vertexProvider(array &$captured): VertexProvider
    {
        $captured = ['body' => []];

        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: self::VERTEX_MODEL,
            predictor: static function (string $endpoint, string $method, array $body) use (&$captured): array {
                $captured = ['body' => $body];

                return ['content' => []];
            },
        );
    }

    private function request(string $model, ToolResultMessage $result): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: [$result]);
    }

    /** @param array{0: string, 1: array<string, mixed>} $captured @return array<string, mixed> */
    private function toolResultBlock(array $captured): array
    {
        /** @var array<int, array<string, mixed>> $messages */
        $messages = $captured[1]['messages'];
        $this->assertSame('user', $messages[0]['role'], 'tool results are user turns on this wire');
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = $messages[0]['content'];
        $this->assertSame('tool_result', $blocks[0]['type']);

        return $blocks[0];
    }

    // -------------------------------------------------------------------------
    // The positive pole: a seeing wire gets the block
    // -------------------------------------------------------------------------

    public function testTheVisionWireCarriesTheCarriedImageInsideTheToolResult(): void
    {
        $captured = [];
        $provider = $this->nativeProvider(self::VISION_MODEL, $captured);

        $provider->complete($this->request(self::VISION_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            imageBytes: self::png(),
            imageProtocol: 'sixel',
        )));

        $block = $this->toolResultBlock($captured);
        $this->assertSame('tu_1', $block['tool_use_id']);
        $this->assertIsArray($block['content'], 'a seeing model gets blocks, not a caption string');
        $this->assertCount(2, $block['content']);
        $this->assertSame(['type' => 'text', 'text' => self::CAPTION], $block['content'][0], 'the caption keeps its row beside the pixels');
        $this->assertSame(AttachmentEncoding::anthropicImage('image/png', self::png()), $block['content'][1]);
    }

    public function testABlankCaptionSendsTheImageBlockAlone(): void
    {
        $captured = [];
        $provider = $this->nativeProvider(self::VISION_MODEL, $captured);

        $provider->complete($this->request(self::VISION_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: '',
            imageBytes: self::png(),
            imageProtocol: 'kitty',
        )));

        $block = $this->toolResultBlock($captured);
        $this->assertSame([AttachmentEncoding::anthropicImage('image/png', self::png())], $block['content']);
    }

    public function testTheBlockShapeIsThePinnedAnthropicContract(): void
    {
        // The wire document is versioned by Anthropic: type/source/base64/
        // media_type/data, the payload base64 of the RAW bytes. Pinned as
        // literal, not just as round-trip through the builder, so a "fix" to
        // the shared shape cannot quietly move the contract.
        $this->assertSame([
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => 'image/png',
                'data' => base64_encode(self::png()),
            ],
        ], AttachmentEncoding::anthropicImage('image/png', self::png()));
    }

    public function testTheVertexRawPredictArmLandsTheIdenticalBlock(): void
    {
        $captured = [];
        $provider = $this->vertexProvider($captured);

        $provider->complete($this->request(self::VERTEX_MODEL, new ToolResultMessage(
            toolCallId: 'tu_9',
            content: self::CAPTION,
            imageBytes: self::png(),
            imageProtocol: 'sixel',
        )));

        /** @var array<int, array<string, mixed>> $messages */
        $messages = $captured['body']['messages'];
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = $messages[0]['content'];
        $this->assertSame('tool_result', $blocks[0]['type']);
        $this->assertSame(AttachmentEncoding::anthropicImage('image/png', self::png()), $blocks[0]['content'][1], 'one wire, both hosts — the block must not know which door it came in by');
    }

    // -------------------------------------------------------------------------
    // The refusal poles: caption string, never a broken or faked wire
    // -------------------------------------------------------------------------

    public function testABlindModelKeepsThePlainCaptionString(): void
    {
        // supportsVision() is false for a non-Anthropic model on this host —
        // the bytes are simply not offered, and the wire keeps the exact
        // pre-SHL-3 shape. This row is ALSO the pin that the OpenAI-family
        // decision ("caption alone, named in the docblock, never a smuggled
        // follow-up turn") has a teeth-bearing twin here: on a blind wire the
        // string is the whole answer.
        $captured = [];
        $provider = $this->nativeProvider(self::BLIND_MODEL, $captured);

        $provider->complete($this->request(self::BLIND_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            imageBytes: self::png(),
            imageProtocol: 'sixel',
        )));

        $this->assertSame(self::CAPTION, $this->toolResultBlock($captured)['content']);
    }

    public function testErrorResultsNeverGainAnImageLegEvenOnAVisionWire(): void
    {
        $captured = [];
        $provider = $this->nativeProvider(self::VISION_MODEL, $captured);

        $provider->complete($this->request(self::VISION_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: 'Image failed to load.',
            isError: true,
            imageBytes: self::png(),
            imageProtocol: 'sixel',
        )));

        $block = $this->toolResultBlock($captured);
        $this->assertTrue($block['is_error']);
        $this->assertSame('Image failed to load.', $block['content'], 'an error row is text by construction');
    }

    public function testBytesPastTheModelBudgetStayCaptionText(): void
    {
        // Inside the tool's 5MiB DISPLAY cap (so Read carried them), past the
        // 256KiB model budget: the human still gets the pixels, the model
        // keeps the caption. The budget refuses OVER it, exactly at it rides.
        $over = "\x89PNG\r\n\x1a\n" . str_repeat('a', AnthropicProvider::TOOL_RESULT_IMAGE_MAX_BYTES);
        $at = "\x89PNG\r\n\x1a\n" . str_repeat('a', AnthropicProvider::TOOL_RESULT_IMAGE_MAX_BYTES - 8);
        $this->assertLessThanOrEqual(FileMentions::IMAGE_MAX_BYTES, strlen($over), 'the fixture must stay inside the human channel to isolate this gate');

        $captured = [];
        $provider = $this->nativeProvider(self::VISION_MODEL, $captured);
        $provider->complete($this->request(self::VISION_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            imageBytes: $over,
            imageProtocol: 'sixel',
        )));
        $this->assertSame(self::CAPTION, $this->toolResultBlock($captured)['content']);

        $provider->complete($this->request(self::VISION_MODEL, new ToolResultMessage(
            toolCallId: 'tu_2',
            content: self::CAPTION,
            imageBytes: $at,
            imageProtocol: 'sixel',
        )));
        $this->assertIsArray($this->toolResultBlock($captured)['content'], 'the budget refuses past it, not at it');
    }

    public function testBytesThatNoLongerSniffAsAnImageStayCaptionText(): void
    {
        // The wire re-sniffs instead of trusting the carrier: rotted or forged
        // bytes answer the sniffer with null and stay text, rather than
        // becoming an image block the API 400s on.
        $captured = [];
        $provider = $this->nativeProvider(self::VISION_MODEL, $captured);

        $provider->complete($this->request(self::VISION_MODEL, new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            imageBytes: 'not really a png at all',
            imageProtocol: 'sixel',
        )));

        $this->assertSame(self::CAPTION, $this->toolResultBlock($captured)['content']);
    }

    // -------------------------------------------------------------------------
    // The contract's provenance and its stability
    // -------------------------------------------------------------------------

    public function testTheModelBudgetIsTheTextPageContractReused(): void
    {
        // 256KiB lineage: the constant is FileMentions::TEXT_MAX_BYTES' value
        // re-armed for the wire, pinned equal by name so a change to either
        // must be a decision about BOTH.
        $this->assertSame(FileMentions::TEXT_MAX_BYTES, AnthropicProvider::TOOL_RESULT_IMAGE_MAX_BYTES);
        $this->assertSame(256 * 1024, AnthropicProvider::TOOL_RESULT_IMAGE_MAX_BYTES);
    }

    public function testTheImageBearingRequestIsByteStableAcrossTurns(): void
    {
        // Prompt-cache discipline: base64 is deterministic and the block
        // order is fixed (caption, then image), so the same history renders
        // the same bytes on every send — the prefix cache keeps its hit.
        $captured = [];
        $provider = $this->nativeProvider(self::VISION_MODEL, $captured);
        $result = new ToolResultMessage(
            toolCallId: 'tu_1',
            content: self::CAPTION,
            imageBytes: self::png(),
            imageProtocol: 'sixel',
        );

        $provider->complete($this->request(self::VISION_MODEL, $result));
        $first = $captured[1];

        $provider->complete($this->request(self::VISION_MODEL, $result));
        $this->assertSame($first, $captured[1]);
    }
}

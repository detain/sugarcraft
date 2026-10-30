<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Attachments\FileMentions;
use SugarCraft\Code\Context\McpOutputFence;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\ToolResult as BootProbe;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * C-TOOL-2 (GAP-MCP-14): the first decodable image part and the first in-budget
 * PDF resource part of an MCP reply RIDE THE TOOL-RESULT CHANNEL beside the
 * text, where a vision- or document-capable wire can attach them as real
 * content blocks — while every description line stays, so a non-capable wire
 * loses nothing.
 *
 * THE CHANNEL IS NOT THE WIRE: these tests pin what lands on the ToolResult.
 * The provider-side gates (model capability, the wire's own 256 KiB image
 * budget, error suppression) live in {@see \SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire}
 * and are pinned there; this file's forged-mime and audio cases pin only the
 * bridge's own sniff-first rule.
 *
 * Helpers are byte-copied from {@see McpToolBridgeTest} for drift discipline:
 * same fake-server-over-reflection harness, no real MCP server may be involved.
 */
final class McpToolBridgeImageChannelTest extends TestCase
{
    /** Twelve bytes whose PNG magic sniffs, with filler so the two fixtures differ. */
    private const PNG_A_BYTES = "\x89PNG\r\n\x1a\nab";

    private const PNG_B_BYTES = "\x89PNG\r\n\x1a\ncd";

    /** Decodes, sniffs as a document, and stays far inside the channel budget. */
    private const PDF_BYTES = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\ntrailer stub\n";

    public function testTheFirstDecodableImagePartRidesTheChannelAndSaysSo(): void
    {
        $pngA = base64_encode(self::PNG_A_BYTES);
        $pngB = base64_encode(self::PNG_B_BYTES);

        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => [
            ['type' => 'text', 'text' => 'look'],
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => $pngA],
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => $pngB],
        ]]);

        $result = $this->bridgeOver($server)->execute([]);

        self::assertSame(
            $this->fenced(
                "look\n"
                . sprintf(
                    '[image: image/png, %d base64 bytes carried as an image on this result — the first decodable image part only; later image parts stay text]',
                    strlen($pngA),
                )
                . "\n[image: image/png, " . strlen($pngB) . ' base64 bytes not shown]',
            ),
            $result->content(),
        );
        self::assertSame(self::PNG_A_BYTES, $result->imageBytes(), 'exactly one image rides — the FIRST passing part');
        self::assertNull($result->documentBytes());
        self::assertFalse($result->isError());
    }

    public function testTheCarriedPartReportsTheProbeProtocol(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => [
            ['type' => 'image', 'data' => base64_encode(self::PNG_A_BYTES)],
        ]]);

        $result = $this->bridgeOver($server)->execute([]);

        self::assertSame(BootProbe::mosaic()->protocol(), $result->imageProtocol(), 'the same probe Read uses for its carry');
        self::assertNotNull($result->imageBytes());

        $plain = $this->bridgeOver($this->serving([['type' => 'text', 'text' => 'no media']]))->execute([]);
        self::assertNull($plain->imageProtocol(), 'no carry, no protocol claim');
        self::assertNull($plain->imageBytes());
    }

    public function testAnOversizeImageStaysTextWithAnEmptyChannel(): void
    {
        $huge = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', FileMentions::IMAGE_MAX_BYTES));

        $result = $this->bridgeOver($this->serving([
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => $huge],
        ]))->execute([]);

        self::assertSame(
            $this->fenced('[image: image/png, ' . strlen($huge) . ' base64 bytes not shown]'),
            $result->content(),
        );
        self::assertNull($result->imageBytes(), 'over the tool-side image budget means it was never going to ride anywhere');
    }

    /**
     * The text-length door inside `decodeSniffed` (REVIEW-1 MINOR): a payload
     * that cannot fit the budget is refused on its base64 TEXT length, BEFORE
     * `base64_decode` materialises it, so a hostile-but-framing-legal server
     * inside the transport's 64 MiB frame cap cannot force a ~48 MB transient
     * decode per failing candidate.
     *
     * THE DOOR IS OBSERVABLY EQUIVALENT, WHICH IS WHY THE PIN IS STRUCTURAL.
     * Every 3 decoded bytes occupy 4 text bytes, so the set of candidates the
     * text door refuses is exactly the set the legacy decoded-length check
     * refuses — no $payload can make the two disagree, and no behavioural
     * assertion can discriminate the guard. What DOES discriminate is ORDER:
     * remove the guard and the source scan reddens, because there is no longer
     * a refusal between the string gate and the decode. The end-to-end leg
     * keeps the user-visible promise honest at the real image budget: the
     * oversized candidate still says its size and carries nothing.
     */
    public function testAPayloadPastTheTextLengthDoorIsRefusedWithoutDecoding(): void
    {
        $method = new \ReflectionMethod(McpToolBridge::class, 'decodeSniffed');
        $lines = (array) file((string) $method->getFileName());
        $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $decodeAt = strpos($source, 'base64_decode($payload');
        $doorAt = strpos($source, 'strlen($payload) > 4 * intdiv($maxBytes, 3) + 4');
        self::assertIsInt($doorAt, 'the pre-decode text-length door is gone: an oversize candidate would decode in full before any refusal');
        self::assertIsInt($decodeAt);
        self::assertLessThan($decodeAt, $doorAt, 'the door must run BEFORE base64_decode — after it, the transient materialisation the door exists to avoid still happens');

        $pngOverDoor = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', FileMentions::IMAGE_MAX_BYTES + 1));
        self::assertGreaterThan(4 * intdiv(FileMentions::IMAGE_MAX_BYTES, 3) + 4, strlen($pngOverDoor), 'a real oversize PNG candidate sits past the door for the real image budget');

        $result = $this->bridgeOver($this->serving([
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => $pngOverDoor],
        ]))->execute([]);

        self::assertStringContainsString(
            '[image: image/png, ' . strlen($pngOverDoor) . ' base64 bytes not shown]',
            $result->content(),
            'the size line is stated whether the door or the decode refused it',
        );
        self::assertStringNotContainsString('carried as an image', $result->content());
        self::assertNull($result->imageBytes());
    }

    public function testAForgedImageSniffsNowhereAndReachesNeitherChannel(): void
    {
        $junk = base64_encode('this is plainly not an image');
        $pdfBytes = base64_encode(self::PDF_BYTES);

        $result = $this->bridgeOver($this->serving([
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => $junk],
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => $pdfBytes],
        ]))->execute([]);

        self::assertStringContainsString('base64 bytes not shown]', $result->content());
        self::assertStringNotContainsString('carried as an image', $result->content());
        self::assertNull($result->imageBytes(), 'a lying mimeType must not put a PDF on the IMAGE channel');
        self::assertNull($result->documentBytes(), 'the document walk reads resource blobs, not image parts');
    }

    public function testInvalidBase64StaysText(): void
    {
        $broken = '!!!!not-valid-base64!!!!';

        $result = $this->bridgeOver($this->serving([
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => $broken],
        ]))->execute([]);

        self::assertSame(
            $this->fenced('[image: image/png, ' . strlen($broken) . ' base64 bytes not shown]'),
            $result->content(),
        );
        self::assertNull($result->imageBytes());
    }

    public function testAudioIsDescribedEvenWhenItsBytesAreReal(): void
    {
        $wav = base64_encode('RIFF' . "\x2e\x00\x00\x00" . 'WAVEfmt ');

        $result = $this->bridgeOver($this->serving([
            ['type' => 'audio', 'mimeType' => 'audio/wav', 'data' => $wav],
        ]))->execute([]);

        self::assertSame(
            $this->fenced('[audio: audio/wav, ' . strlen($wav) . ' base64 bytes not shown]'),
            $result->content(),
        );
        self::assertNull($result->imageBytes(), 'there is no audio channel and this walk does not invent one');
        self::assertNull($result->documentBytes());
    }

    public function testAPdfResourceBlobRidesTheDocumentChannelWithItsLineIntact(): void
    {
        $blob = base64_encode(self::PDF_BYTES);

        $result = $this->bridgeOver($this->serving([
            ['type' => 'resource', 'resource' => [
                'uri' => 'file:///docs/report.pdf', 'mimeType' => 'application/pdf', 'blob' => $blob,
            ]],
        ]))->execute([]);

        self::assertSame(
            $this->fenced('[resource: file:///docs/report.pdf: application/pdf, ' . strlen($blob) . ' base64 bytes not shown]'),
            $result->content(),
            'the description line is byte-preserved — the channel rides beside it, not instead of it',
        );
        self::assertSame(self::PDF_BYTES, $result->documentBytes());
        self::assertSame('report.pdf', $result->documentName(), 'the block is titled with the uri basename');
        self::assertNull($result->imageBytes());
    }

    public function testTheDocumentChannelRefusesNonPdfAndOversizeBlobs(): void
    {
        $notPdf = base64_encode('ABCDEFGH');

        $oversize = base64_encode('%PDF-' . str_repeat('y', AnthropicProvider::TOOL_RESULT_DOCUMENT_MAX_BYTES));

        $result = $this->bridgeOver($this->serving([
            ['type' => 'resource', 'resource' => ['uri' => 'r://junk', 'mimeType' => 'application/pdf', 'blob' => $notPdf]],
            ['type' => 'resource', 'resource' => ['uri' => 'r://big.pdf', 'mimeType' => 'application/pdf', 'blob' => $oversize]],
        ]))->execute([]);

        self::assertNull($result->documentBytes());
        self::assertNull($result->documentName());
    }

    public function testTheFirstPassingPdfWinsWhenEarlierCandidatesFail(): void
    {
        $result = $this->bridgeOver($this->serving([
            ['type' => 'resource', 'resource' => ['uri' => 'r://junk', 'blob' => base64_encode('not a pdf')]],
            ['type' => 'resource', 'resource' => ['uri' => 'file:///x/g.pdf', 'blob' => base64_encode(self::PDF_BYTES)]],
        ]))->execute([]);

        self::assertSame(self::PDF_BYTES, $result->documentBytes(), 'a failing candidate moves the walk on, it does not stop it');
        self::assertSame('g.pdf', $result->documentName());
    }

    public function testABlobWithoutUriStillGetsANameableDefault(): void
    {
        $result = $this->bridgeOver($this->serving([
            ['type' => 'resource', 'resource' => ['mimeType' => 'application/pdf', 'blob' => base64_encode(self::PDF_BYTES)]],
        ]))->execute([]);

        self::assertSame(self::PDF_BYTES, $result->documentBytes());
        self::assertSame('document.pdf', $result->documentName());
    }

    public function testATextResourceIsUntouchedByTheDocumentWalk(): void
    {
        $result = $this->bridgeOver($this->serving([
            ['type' => 'resource', 'resource' => ['uri' => 'file:///note.md', 'text' => '# hi']],
        ]))->execute([]);

        self::assertSame($this->fenced("[resource: file:///note.md]\n# hi"), $result->content());
        self::assertNull($result->documentBytes());
        self::assertNull($result->imageBytes());
    }

    public function testTheCarriageIsNotConditionedOnTheErrorFlag(): void
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn([
            'content' => [['type' => 'image', 'data' => base64_encode(self::PNG_A_BYTES)]],
            'isError' => true,
        ]);

        $result = $this->bridgeOver($server)->execute([]);

        self::assertTrue($result->isError());
        self::assertSame(
            self::PNG_A_BYTES,
            $result->imageBytes(),
            'the transcript preview shows what the reply held; the WIRE is what refuses to bill it',
        );

        $envelope = $this->bridgeOver($this->servingError())->execute([]);
        self::assertNull($envelope->imageBytes());
        self::assertNull($envelope->documentBytes());
        self::assertTrue($envelope->isError());
    }

    /**
     * The MCP-7 promise, extended to bytes: one `replyResult()` serves both
     * spellings, so a dispatched `mcp_call` and a bridged call carry the same
     * media off the same reply. Behavioural leg drives the shared entry point
     * directly; structural leg pins that McpCallTool really goes through it
     * rather than re-rendering on its own.
     */
    public function testBothSpellingsRenderAndCarryThroughOneEntryPoint(): void
    {
        $raw = ['content' => [
            ['type' => 'text', 'text' => 'shot'],
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => base64_encode(self::PNG_A_BYTES)],
            ['type' => 'resource', 'resource' => ['uri' => 'r://d.pdf', 'blob' => base64_encode(self::PDF_BYTES)]],
        ]];

        $direct = McpToolBridge::replyResult(
            $raw,
            'call-1',
            hrtime(true),
            'srv',
            'ping',
            static fn (string $text): string => $text,
        );

        $bridged = $this->bridgeOver($this->serving($raw['content']))->execute([]);

        self::assertSame($bridged->content(), $direct->content());
        self::assertSame($bridged->imageBytes(), $direct->imageBytes());
        self::assertSame($bridged->documentBytes(), $direct->documentBytes());
        self::assertSame(self::PNG_A_BYTES, $direct->imageBytes());
        self::assertSame(self::PDF_BYTES, $direct->documentBytes());

        $dispatcherSource = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Tools/BuiltIn/McpCallTool.php');
        self::assertStringContainsString('McpToolBridge::replyResult(', $dispatcherSource, 'the dispatcher renders here, not at home');
    }

    public function testTheTextIsClippedWhileTheChannelBytesRideUntouched(): void
    {
        $png = base64_encode(self::PNG_A_BYTES);
        $raw = ['content' => [
            ['type' => 'text', 'text' => str_repeat('wide ', 200)],
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => $png],
        ]];

        $result = McpToolBridge::replyResult(
            $raw,
            'call-2',
            hrtime(true),
            'srv',
            'ping',
            static fn (string $text): string => strlen($text) > 48 ? substr($text, 0, 48) : $text,
        );

        self::assertSame(48, strlen($result->content()), 'the cutter clips the render, exactly as the bridge cap does');
        self::assertSame(self::PNG_A_BYTES, $result->imageBytes(), 'the channel is outside the text budget');
    }

    // =========================================================================
    // Helpers — byte-copied from McpToolBridgeTest (drift discipline)
    // =========================================================================

    /** The exact bytes a body must arrive under: fenced for its server and tool. */
    private function fenced(string $body, string $server = 'srv', string $tool = 'ping'): string
    {
        return McpOutputFence::wrap($body, $server, $tool);
    }

    private function descriptor(
        string $server = 'srv',
        string $name = 'ping',
        string $description = 'Answer with pong.',
        array $schema = ['type' => 'object', 'properties' => ['note' => ['type' => 'string']], 'required' => []],
    ): McpTool {
        return new McpTool(name: $name, description: $description, inputSchema: $schema, serverName: $server);
    }

    /** @return McpServer&\PHPUnit\Framework\MockObject\MockObject */
    private function fakeServer(): McpServer
    {
        return $this->createMock(McpServer::class);
    }

    private function bridgeOver(McpServer $server): McpToolBridge
    {
        $descriptor = $this->descriptor();

        return new McpToolBridge($this->clientWith($server, $descriptor), $descriptor);
    }

    /**
     * A real {@see McpClient} with $server injected under the descriptor's server
     * name, so `callToolByName()` can find it — the same reflection route
     * `tests/MCP/McpClientTest.php` uses, because `$servers` is populated only by
     * `startServers()` and nothing here may start a process.
     */
    private function clientWith(?McpServer $server = null, ?McpTool $descriptor = null): McpClient
    {
        $client = new McpClient('/nonexistent/.mcp.json', unrestricted: true);

        if ($server === null) {
            return $client;
        }

        $descriptor ??= $this->descriptor();
        $server->method('listTools')->willReturn([$descriptor]);

        $servers = new \ReflectionProperty($client, 'servers');
        $servers->setAccessible(true);
        $servers->setValue($client, [$descriptor->serverName => $server]);

        return $client;
    }

    /** A fake server answering `callTool` with exactly these content parts. */
    private function serving(array $content, ?McpServer $reuse = null): McpServer
    {
        $server = $reuse ?? $this->fakeServer();
        $server->method('callTool')->willReturn(['content' => $content]);

        return $server;
    }

    /** A fake server answering with the transport-failure envelope. */
    private function servingError(): McpServer
    {
        $server = $this->fakeServer();
        $server->method('callTool')->willReturn(['error' => 'boom']);

        return $server;
    }
}

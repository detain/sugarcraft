<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Script;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Script\ScriptBridge;

/**
 * The NDJSON codec of the fd-3 bridge, judged in memory — no child, no
 * sockets. Everything the parent trusts about a model-written child's
 * framing is decided here, so the shapes that matter are hostile ones: a
 * frame split across feeds, two frames coalesced into one feed, a line that
 * is not JSON, a line over the cap, and a line that streams forever without
 * ever ending. The law under test is fail-CLOSED: nothing undecoded is
 * ever trusted, nothing refused is ever silent, and nothing unbounded is
 * ever buffered.
 */
final class ScriptBridgeTest extends TestCase
{
    public function testASplitFrameDecodesOnlyOnceWhole(): void
    {
        $bridge = new ScriptBridge();
        $whole = '{"id":1,"method":"call","params":{"name":"Read","arguments":{}}}';
        $half = (int) (strlen($whole) / 2);
        $bridge->feed(substr($whole, 0, $half));

        self::assertNull($bridge->next(), 'an incomplete line is not a frame');

        $bridge->feed(substr($whole, $half));
        self::assertNull($bridge->next(), 'a whole line still needs its terminator');

        $bridge->feed("\n");
        $frame = $bridge->next();

        self::assertIsArray($frame);
        self::assertSame(1, $frame['id']);
        self::assertSame('call', $frame['method']);
        self::assertSame('Read', $frame['params']['name']);
        self::assertSame(strlen($whole) + 1, $bridge->lastFrameBytes(), 'the ledger counts the request line, terminator included');
        self::assertNull($bridge->next(), 'one frame is consumed once');
    }

    public function testCoalescedFeedsYieldFramesInStreamOrder(): void
    {
        $bridge = new ScriptBridge();
        $bridge->feed(
            "{\"id\":1,\"method\":\"call\",\"params\":{\"name\":\"A\",\"arguments\":[]}}\n"
                . "{\"id\":2,\"method\":\"list\"}\n"
                . '{"id":3,"method":"return","value":7}' . "\n",
        );

        $one = $bridge->next();
        $two = $bridge->next();
        $three = $bridge->next();

        self::assertSame(1, $one['id'] ?? null);
        self::assertSame('call', $one['method'] ?? null);
        self::assertSame(2, $two['id'] ?? null);
        self::assertSame('list', $two['method'] ?? null);
        self::assertSame(3, $three['id'] ?? null);
        self::assertSame('return', $three['method'] ?? null);
        self::assertSame(7, $three['value'] ?? null);
        self::assertNull($bridge->next());
    }

    public function testAnOversizeFrameIsRefusedNotBuffered(): void
    {
        $bridge = new ScriptBridge();
        $head = str_repeat('A', ScriptBridge::MAX_BRIDGE_FRAME_BYTES + 1);

        $bridge->feed($head);
        self::assertNull($bridge->next(), 'the refusal is named when the line CLOSES, not per byte');
        self::assertTrue($bridge->discardingOversize(), 'an uncapped stream is visibly still running');

        // Hostile memory pin: past the cap, feed must stop growing the buffer
        // entirely. One more megabyte changes nothing observable — the frame
        // is already refused; only the count moves.
        $bridge->feed(str_repeat('B', 1_048_576));
        $bridge->feed("\n");

        $refusal = $bridge->next();
        self::assertIsArray($refusal);
        self::assertArrayNotHasKey('method', $refusal, 'a refused frame carries no decoded execution');
        self::assertNull($refusal['id'], 'a head of pure filler names no id to echo');
        self::assertStringContainsString('exceeds', (string) $refusal['error']);
        self::assertSame(
            2 * ScriptBridge::MAX_BRIDGE_FRAME_BYTES + 1,
            $bridge->takeOversizeBytes(),
            'every discarded byte is counted for the named breach',
        );
        self::assertSame(0, $bridge->takeOversizeBytes(), 'one breach is counted once');
        self::assertFalse($bridge->discardingOversize());
    }

    public function testAMalformedFrameAnswersWithAnErrorFrameEchoingItsId(): void
    {
        $bridge = new ScriptBridge();
        $bridge->feed('{"id":9,"method":BOGUS-not-json}' . "\n");
        $bad = $bridge->next();

        self::assertIsArray($bad);
        self::assertSame(9, $bad['id'] ?? null, 'a malformed frame still gets the reply it addressed');
        self::assertStringContainsString('malformed frame', (string) ($bad['error'] ?? ''));
        self::assertArrayNotHasKey('method', $bad);

        $bridge->feed("i am not json and name no id\n");
        $nameless = $bridge->next();
        self::assertArrayHasKey('id', $nameless);
        self::assertNull($nameless['id'], 'no id in the bytes, no id invented');
        self::assertStringContainsString('malformed frame', (string) ($nameless['error'] ?? ''));

        $bridge->feed("[1,2,3]\n");
        $list = $bridge->next();
        self::assertStringContainsString('malformed frame', (string) ($list['error'] ?? ''), 'a JSON array is not an object frame');
        self::assertArrayNotHasKey(0, $list);
    }

    public function testAnUnknownMethodArrivesUntouchedBecauseRejectionIsTheRunnersJob(): void
    {
        $bridge = new ScriptBridge();
        $bridge->feed('{"id":4,"method":"poltergeist"}' . "\n");
        $frame = $bridge->next();

        self::assertSame('poltergeist', $frame['method'] ?? null, 'the codec decodes and JUDGES nothing');
    }

    public function testBlankLinesAreSkippedAndTheFollowingFrameStillDecodes(): void
    {
        $bridge = new ScriptBridge();
        $bridge->feed("\n\n{\"id\":5,\"method\":\"list\"}\n");

        $frame = $bridge->next();
        self::assertSame(5, $frame['id'] ?? null);
        self::assertNull($bridge->next());
    }

    public function testARefusedOversizeLinePrecedesTheFramesBehindIt(): void
    {
        $bridge = new ScriptBridge();
        $bridge->feed(str_repeat('C', ScriptBridge::MAX_BRIDGE_FRAME_BYTES + 10));
        $bridge->feed("\n{\"id\":6,\"method\":\"list\"}\n");

        $refusal = $bridge->next();
        self::assertArrayNotHasKey('method', $refusal ?? []);
        $after = $bridge->next();
        self::assertSame(6, $after['id'] ?? null, 'one bad line never poisons the stream after it');
    }

    public function testEncodeProducesOneLineAndAnUnencodableFrameFallsBackLoudly(): void
    {
        $line = ScriptBridge::encode(['id' => 1, 'result' => ['content' => 'hi', 'isError' => false]]);
        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"), 'exactly one NDJSON line');
        self::assertIsArray(json_decode($line, true));

        $handle = tmpfile();
        $fallback = ScriptBridge::encode(['id' => 3, 'result' => ['content' => $handle, 'isError' => false]]);
        $decoded = json_decode($fallback, true);

        self::assertSame(3, $decoded['id'] ?? null, 'the fallback still answers the call that was waiting');
        self::assertStringContainsString('not JSON-encodable', (string) ($decoded['error'] ?? ''));
        fclose($handle);
    }

    public function testTheCapConstantIsTheDesignNumber(): void
    {
        // Byte-stable pin (house doctrine for wire caps): a revalue of the
        // 1 MiB frame budget must be a deliberate edit through here.
        self::assertSame(1_048_576, ScriptBridge::MAX_BRIDGE_FRAME_BYTES);
    }
}

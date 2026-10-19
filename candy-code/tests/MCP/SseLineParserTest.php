<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\SseLineParser;

/**
 * MCP-4: the hoisted SSE framing parser, pinned as a unit.
 *
 * {@see SseLineParser} was extracted verbatim from `HttpMcpServer`'s private
 * reply reader so the legacy SSE transport and Streamable HTTP's event-stream
 * replies share ONE parser. `HttpMcpServer`'s own suite passing untouched
 * proves the extraction did not drift on the paths it already exercised;
 * this file exercises the parser directly across the shapes the old loop
 * guaranteed — resumable framing across arbitrary chunk splits, the lone-CR
 * tail that must WAIT for the next read to decide whether it is a terminator
 * or the first half of a CRLF, the end-of-stream flush of an unterminated
 * final event, and the reply matcher's string-ordered id comparison.
 *
 * No sockets here: the reader contract is `fn(): string` with `''` at end of
 * stream, so a queued-closure reader drives every case deterministically.
 */
final class SseLineParserTest extends TestCase
{
    /** @param list<string> $chunks */
    private function parser(array $chunks): SseLineParser
    {
        $i = 0;

        return new SseLineParser(static function () use (&$i, $chunks): string {
            return $i < count($chunks) ? $chunks[$i++] : '';
        });
    }

    public function testASingleEventParsesItsFieldsAndTrimsOneLeadingSpace(): void
    {
        $parser = $this->parser(["data:  two spaces kept one\n\n"]);

        $event = $parser->nextEvent();

        self::assertSame(['event' => '', 'data' => ' two spaces kept one'], $event);
        self::assertNull($parser->nextEvent(), 'end of stream answers null');
    }

    public function testMultipleDataLinesJoinWithNewlinesAndUnknownFieldsAreIgnored(): void
    {
        $parser = $this->parser(["id: 7\nretry: 500\ndata: one\ndata: two\n\nevent: message\ndata: three\n\n"]);

        self::assertSame(['event' => '', 'data' => "one\ntwo"], $parser->nextEvent());
        self::assertSame(['event' => 'message', 'data' => 'three'], $parser->nextEvent());
    }

    public function testCommentLinesAreKeepAlivesNotEvents(): void
    {
        $parser = $this->parser([": ping\n\n", ": another\n", "data: real\n\n"]);

        self::assertSame(['event' => '', 'data' => 'real'], $parser->nextEvent());
    }

    public function testADispatchWithoutDataCarriesNothingAndIsNotReturned(): void
    {
        $parser = $this->parser(["\n\nevent: ping\n\n", "data: body\n\n"]);

        // The first chunk dispatches twice with empty data (blank line, then
        // an event field with no data); the third yields the first real event.
        self::assertSame(['event' => '', 'data' => 'body'], $parser->nextEvent());
    }

    public function testAFieldWithoutColonIsTheFieldWithAnEmptyValue(): void
    {
        $parser = $this->parser(["data\n\n"]);

        self::assertSame(['event' => '', 'data' => ''], $parser->nextEvent());
    }

    public function testFramingSurvivesEveryLineEndingSplitAcrossChunks(): void
    {
        $cases = [
            'CRLF split across reads' => [["data: a\r", "\n\r", "\n"], 'a'],
            'LF split after the field' => [["data: b", "\n", "\n"], 'b'],
            'CR terminators in separate reads' => [["data: c\r", "\r"], 'c'],
        ];

        foreach ($cases as $label => [$chunks, $expected]) {
            $parser = $this->parser($chunks);

            self::assertSame(['event' => '', 'data' => $expected], $parser->nextEvent(), $label);
            self::assertNull($parser->nextEvent(), $label);
        }
    }

    public function testALoneCrAtTheTailWaitsForTheNextChunkToDecide(): void
    {
        // Reader hands 'data: x\r' first. If the parser treated that CR as a
        // terminator NOW it would consume the line early and the following
        // '\r\n' would dispatch an EMPTY event instead — the event would carry
        // no data and never be returned. Correct behaviour: wait, then see the
        // CRLF and keep the data line intact.
        $parser = $this->parser(["data: x\r", "\r\n", '']);

        self::assertSame(['event' => '', 'data' => 'x'], $parser->nextEvent());
        self::assertNull($parser->nextEvent());
    }

    public function testAnUnterminatedFinalEventIsStillDispatchedAtEndOfStream(): void
    {
        $parser = $this->parser(["data: tail-line"]);

        self::assertSame(['event' => '', 'data' => 'tail-line'], $parser->nextEvent());
        self::assertNull($parser->nextEvent(), 'the flush happens once; end of stream is sticky');
    }

    public function testReplyToSkipsNamedAndNonMatchingEventsAndFindsTheMatch(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['ok' => true]]);
        $noise = [
            "event: endpoint\ndata: /post\n\n",                                   // named event, not message
            "data: " . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/x']) . "\n\n", // no id
            "data: " . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => []]) . "\n\n",     // other id
            "data: not json at all\n\n",                                          // undecodable
        ];

        $parser = $this->parser([...$noise, "event: message\ndata: {$body}\n\n"]);

        $reply = $parser->replyTo(3);

        self::assertIsArray($reply);
        self::assertSame(3, $reply['id']);
        self::assertSame(['ok' => true], $reply['result']);
    }

    public function testReplyToAnswersNullWhenTheStreamEndsWithoutAMatch(): void
    {
        $parser = $this->parser(['data: ' . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]) . "\n\n"]);

        self::assertNull($parser->replyTo(42));
    }

    public function testABatchInsideOneEventIsMatchedEntryWise(): void
    {
        $batch = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['first' => true]],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['second' => true]],
        ]);
        $parser = $this->parser(["data: {$batch}\n\n"]);

        $reply = $parser->replyTo(2);

        self::assertIsArray($reply);
        self::assertSame(['second' => true], $reply['result']);
    }

    public function testMatchReplyComparesIdsAsStringsSoForkTaggedIdsMatch(): void
    {
        $forked = ['jsonrpc' => '2.0', 'id' => '12345-abcd-0', 'result' => ['hit' => true]];

        self::assertSame($forked, SseLineParser::matchReply([$forked], '12345-abcd-0'));
        self::assertNull(SseLineParser::matchReply($forked, '12345-abcd-0'), 'the list shape is the contract');
    }

    public function testMatchReplySkipsRequestsErrorsAndUnrelatedShapes(): void
    {
        $request = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping', 'result' => []];
        $noId = ['jsonrpc' => '2.0', 'result' => []];
        $neither = ['jsonrpc' => '2.0', 'id' => 7];
        $error = ['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -1, 'message' => 'no']];

        self::assertNull(SseLineParser::matchReply([$request, $noId, $neither], 7), 'a server-to-client request may reuse the id value and must not match');
        self::assertSame($error, SseLineParser::matchReply([$request, $noId, $neither, $error], 7), 'an error IS a reply');
        self::assertSame($error, SseLineParser::matchReply([$error, $error], 7), 'first match wins');
    }

    public function testIntegerIdsFromTheOwnerProcessMatchStringEchoesAndViceVersa(): void
    {
        $stringEcho = ['jsonrpc' => '2.0', 'id' => '0', 'result' => []];

        self::assertSame($stringEcho, SseLineParser::matchReply([$stringEcho], 0), 'owner mints int 0; a server echoing "0" still matches');
        self::assertNull(SseLineParser::matchReply([$stringEcho], 1));
    }
}

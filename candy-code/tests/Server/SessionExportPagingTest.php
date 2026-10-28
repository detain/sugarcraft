<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\JsonRpc;
use SugarCraft\Code\Protocol\Methods\SessionMethods;
use SugarCraft\Code\Protocol\Schema\MethodSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;
use SugarCraft\Code\Util\Exporter;

/**
 * C-WEB-7.c (source WEB-1-P2): `session.export` answers the frame cap by
 * paging, not by refusing. A transcript whose markdown exceeded
 * {@see Dispatcher::MAX_SERVER_FRAME_BYTES} answered `too_large` with no way
 * to ask for less — now `limit`/`offset` cut the answer into byte pages the
 * way `tool.output` pages a tool's output, `revision` (xxh128 of the whole
 * render) refuses pages cut from a re-rendered transcript so two renders can
 * never splice, and a page of control bytes — which escapes about six-fold —
 * is shrunk until it fits inside the frame with headroom.
 *
 * The ISS-058 rule {@see SnapshotPagingTest} pins for snapshots holds here
 * too: the unparameterized answer keeps the pre-paging `{format, content}`
 * shape byte-for-byte, `too_large` and all.
 */
final class SessionExportPagingTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testTheUnparameterizedAnswerKeepsThePrePagingShape(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->turn($client, $sessionId, 'small prompt', 'small reply');

        $answer = $client->call('session.export', ['sessionId' => $sessionId, 'format' => 'markdown']);

        self::assertSame(['format', 'content'], array_keys($answer), 'an unpaged answer carries only the historical keys');
        $host = $this->fixture->hub->get($sessionId);
        self::assertNotNull($host);
        $rows = array_values(array_filter($host->history(), static fn (Message $row): bool => $row->userVisible));
        self::assertSame(Exporter::toMarkdown($rows), $answer['content'], 'the unpaged content is the plain render');
        foreach (['offset', 'nextOffset', 'total', 'more', 'revision'] as $meta) {
            self::assertArrayNotHasKey($meta, $answer);
        }
    }

    public function testATranscriptOverTheFrameCapStillRefusesTooLargeUnpaged(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->turn($client, $sessionId, 'big', str_repeat('x', Dispatcher::MAX_SERVER_FRAME_BYTES + 300_000));

        $answer = $client->request('session.export', ['sessionId' => $sessionId, 'format' => 'markdown']);

        self::assertSame('too_large', $answer['error']['data']['kind'] ?? null, 'without limit the historical bound still answers');
    }

    public function testWalkingThePagesReassemblesTheWholeRenderByteIdentically(): void
    {
        foreach (['markdown', 'json'] as $format) {
            $client = $this->fixture->client();
            $sessionId = $this->fixture->session($client);
            $this->turn($client, $sessionId, 'page me', str_repeat('abcdefghij', 24_000));

            $whole = $client->call('session.export', ['sessionId' => $sessionId, 'format' => $format]);
            $joined = '';
            $pages = 0;
            foreach ($this->walkExport($client, $sessionId, $format) as $page) {
                $joined .= (string) $page['content'];
                $pages++;
            }

            self::assertSame($whole['content'], $joined, $format . ': the pages concatenate to exactly the unpaged render');
            self::assertGreaterThanOrEqual(4, $pages, $format . ': a 240 KB transcript is several 64 KB pages, not one');
        }
    }

    public function testEveryPageFitsTheDeclaredResultShape(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->turn($client, $sessionId, 'schema me', str_repeat('0123456789', 8_000));

        $schema = MethodSchemas::result('session.export');
        self::assertNotNull($schema);
        foreach ($this->walkExport($client, $sessionId, 'markdown') as $page) {
            self::assertSame([], ProtocolSchema::errors($page, $schema), 'the page is schema-valid: ' . json_encode(array_diff_key($page, ['content' => 1])));
        }
    }

    public function testAnOffsetMidCodepointBacksUpToTheLeadByte(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        // Two-byte accents: the continuation byte is 0xA9, so an offset that
        // lands on it must back up one to the 0xC3 lead.
        $this->turn($client, $sessionId, 'unicode', str_repeat("caf\u{e9} ", 20_000));

        $whole = (string) $client->call('session.export', ['sessionId' => $sessionId, 'format' => 'markdown'])['content'];
        $continuation = strpos($whole, "\xA9", 100);
        self::assertIsInt($continuation);

        $page = $client->call('session.export', [
            'sessionId' => $sessionId,
            'format' => 'markdown',
            'offset' => $continuation,
            'limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES,
        ]);

        self::assertSame($continuation - 1, $page['offset'], 'the page opens at the accent lead, not its tail');
        self::assertSame(0xC3, ord(substr((string) $page['content'], 0, 1)), 'and its first byte is that lead');
        self::assertSame($continuation - 1 + strlen((string) $page['content']), $page['nextOffset']);
    }

    public function testAPageOfEscapedControlBytesIsShrunkToStayInsideTheFrame(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->turn($client, $sessionId, 'raw', str_repeat("\x01", 1_200_000));

        // A raw page at the maximum limit would JSON-escape about six-fold
        // and trip `too_large` again; the shrink pass must halve it until the
        // encoded page fits under the frame cap with headroom.
        $first = $client->call('session.export', [
            'sessionId' => $sessionId,
            'format' => 'markdown',
            'offset' => 0,
            'limit' => SessionMethods::EXPORT_MAX_PAGE_BYTES,
        ]);
        self::assertTrue($first['more']);
        self::assertLessThan(SessionMethods::EXPORT_MAX_PAGE_BYTES, strlen((string) $first['content']), 'the cut was shrunk');
        self::assertLessThanOrEqual(Dispatcher::MAX_SERVER_FRAME_BYTES, strlen(JsonRpc::encode(['result' => $first])));

        $joined = (string) $first['content'];
        foreach ($this->walkExport($client, $sessionId, 'markdown', from: (int) $first['nextOffset'], revision: (string) $first['revision']) as $page) {
            self::assertLessThanOrEqual(Dispatcher::MAX_SERVER_FRAME_BYTES, strlen(JsonRpc::encode(['result' => $page])));
            $joined .= (string) $page['content'];
        }

        self::assertSame((int) $first['total'], strlen($joined));
        self::assertSame($first['revision'], hash('xxh128', $joined), 'the pages reassemble to exactly the revision-hashed render');
    }

    public function testAStaleRevisionRefusesTheNextPageSoTwoRendersCannotSplice(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->turn($client, $sessionId, 'first', str_repeat('abcdefghij', 8_000));

        $page = $client->call('session.export', ['sessionId' => $sessionId, 'format' => 'markdown', 'limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES]);
        self::assertTrue($page['more']);

        // A compaction or a running turn reshaping the transcript is what the
        // revision guards against; a second settled turn models the change.
        $this->turn($client, $sessionId, 'second', 'appended');

        $stale = $client->request('session.export', [
            'sessionId' => $sessionId,
            'format' => 'markdown',
            'offset' => (int) $page['nextOffset'],
            'limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES,
            'revision' => (string) $page['revision'],
        ]);
        self::assertSame(ErrorCode::Conflict->value, $stale['error']['code'] ?? null);
        self::assertSame('export_changed', $stale['error']['data']['kind'] ?? null);

        $fresh = $client->call('session.export', ['sessionId' => $sessionId, 'format' => 'markdown', 'limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES]);
        self::assertNotSame($page['revision'], $fresh['revision'], 'the re-render carries a new revision to restart with');
    }

    public function testAPagingRefusalFromTheFreshRevisionStillWaitsForTheClient(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->turn($client, $sessionId, 'steady', str_repeat('abcdefghij', 8_000));

        $page = $client->call('session.export', ['sessionId' => $sessionId, 'format' => 'markdown', 'limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES]);
        $second = $client->call('session.export', [
            'sessionId' => $sessionId,
            'format' => 'markdown',
            'offset' => (int) $page['nextOffset'],
            'limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES,
            'revision' => (string) $page['revision'],
        ]);

        self::assertSame((int) $page['nextOffset'], (int) $second['offset'], 'an unchanged render pages on with its revision accepted');
    }

    public function testPagingBoundsOutsideTheDeclaredWindowAreRefused(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->turn($client, $sessionId, 'bounds', 'ok');

        foreach ([
            ['limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES - 1],
            ['limit' => SessionMethods::EXPORT_MAX_PAGE_BYTES + 1],
            ['offset' => -1],
        ] as $extra) {
            $answer = $client->request('session.export', ['sessionId' => $sessionId, 'format' => 'markdown'] + $extra);
            self::assertSame('invalid_params', $answer['error']['data']['kind'] ?? null, json_encode($extra) . ' is refused, not clamped');
        }
    }

    public function testTheServerComputesNextOffsetSoTheClientNeverByteCountsJSON(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        // Lone surrogates cannot ride the wire (JSON_INVALID_UTF8_SUBSTITUTE
        // rewrites them in transit); valid multibyte text proves the
        // contract: nextOffset is byte arithmetic over the server's render.
        $this->turn($client, $sessionId, 'arith', "\u{1F600} \u{00E9} tail");

        $page = $client->call('session.export', ['sessionId' => $sessionId, 'format' => 'markdown', 'offset' => 0, 'limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES]);
        self::assertSame((int) $page['total'], strlen((string) $page['content']));
        self::assertFalse($page['more']);

        $whole = (string) $client->call('session.export', ['sessionId' => $sessionId, 'format' => 'markdown'])['content'];
        self::assertSame(strlen($whole), $page['total'], 'total is the server render, not the client-visible substitute');
    }

    /**
     * Send one turn and let it settle.
     */
    private function turn(WireClient $client, string $sessionId, string $prompt, string $reply): void
    {
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => $prompt]);
        $this->fixture->backend->settle(Message::assistant($reply));
        $this->fixture->run();
    }

    /**
     * Walk every page of a paged export, echoing the revision from page 2 on
     * the way the web store does, and yield each answer.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function walkExport(WireClient $client, string $sessionId, string $format, int $from = 0, ?string $revision = null): \Generator
    {
        $offset = $from;
        $echo = $revision;
        do {
            $page = (array) $client->call('session.export', array_filter([
                'sessionId' => $sessionId,
                'format' => $format,
                'offset' => $offset,
                'limit' => SessionMethods::EXPORT_MIN_PAGE_BYTES,
                'revision' => $echo,
            ], static fn (mixed $v): bool => $v !== null));
            yield $page;
            $echo = (string) $page['revision'];
            $offset = (int) $page['nextOffset'];
        } while ($page['more']);
    }
}

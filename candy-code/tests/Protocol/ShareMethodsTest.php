<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\TranscriptStore;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Share\ShareStore;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * SES-3 (COMPARISON §6.5): `session.share` / `session.unshare` are the RPC
 * twins of `/share serve` / `/share revoke` — same table, same snapshot-at-
 * the-explicit-act security model, same no-oracle death. Served by the SAME
 * serve process that answers `GET /s/{token}`, so a page published over the
 * wire must be readable from the workspace's own session database.
 */
final class ShareMethodsTest extends TestCase
{
    use HomeSandboxTrait;

    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $home = \sys_get_temp_dir() . '/ses3-home-' . \uniqid('', true);
        $this->useHomeSandbox($home);
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
        $this->restoreHomeSandbox();
    }

    public function testSharePublishesASavedSessionsSnapshotAndUnshareRevokesIt(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->seedSession('ses3-1', [
            Message::user('the question'),
            Message::assistant('the answer'),
        ]);

        $shared = $client->call('session.share', ['sessionId' => $sessionId]);

        self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', (string) $shared['token']), '128-bit hex token');
        self::assertSame('/s/' . $shared['token'], $shared['path']);
        self::assertSame('html', $shared['format']);
        self::assertGreaterThanOrEqual(time() + 6 * 86400, (int) $shared['expiresAt'], 'default 7 days');

        $record = $this->store()->lookup((string) $shared['token']);
        self::assertNotNull($record, 'the serve process reads the workspace database');
        self::assertStringContainsString('the answer', $record->html);

        $revoked = $client->call('session.unshare', ['token' => $shared['token']]);
        self::assertTrue($revoked['revoked']);
        self::assertNull($this->store()->lookup((string) $shared['token']), 'the page 404s from its next request');
    }

    /**
     * The security model over the wire: the row holds what was rendered AT
     * the share call. A message appended afterwards must not appear.
     */
    public function testTheSnapshotNeverTracksLaterTranscriptEdits(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->seedSession('ses3-2', [Message::user('public part')]);

        $shared = $client->call('session.share', ['sessionId' => $sessionId, 'days' => 3]);
        $before = $this->store()->lookup((string) $shared['token']);
        self::assertNotNull($before);

        TranscriptStore::new($this->fixture->store)->save($sessionId, [
            Message::user('public part'),
            Message::user('a secret appended after the share act'),
        ]);

        $after = $this->store()->lookup((string) $shared['token']);
        self::assertNotNull($after);
        self::assertSame($before->html, $after->html);
        self::assertStringNotContainsString('secret appended after', $after->html);
        self::assertLessThanOrEqual(time() + 3 * 86400 + 1, (int) $after->expiresAt, 'days is honoured');
    }

    public function testSharingASessionThatDoesNotExistIsNotFound(): void
    {
        $client = $this->fixture->client();

        $reply = $client->request('session.share', ['sessionId' => 'no-such-session']);

        self::assertSame(ErrorCode::NotFound->value, $reply['error']['code']);
        self::assertSame('session_not_found', $reply['error']['data']['kind']);
    }

    public function testSharingAnEmptyLiveSessionIsRefused(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);

        $reply = $client->request('session.share', ['sessionId' => $sessionId]);

        self::assertSame(ErrorCode::InvalidParams->value, $reply['error']['code'], 'no empty page is ever published');
        self::assertStringContainsString('nothing to share', $reply['error']['message']);
    }

    public function testUnshareOfAnUnknownTokenAnswersFalseWithoutDistinguishing(): void
    {
        $client = $this->fixture->client();

        $answer = $client->call('session.unshare', ['token' => str_repeat('0f', 16)]);

        self::assertFalse($answer['revoked']);
    }

    /**
     * C-SES-3.c: `session.shares {sessionId}` — the Share/Revoke menu's list
     * for one session. Each row carries the FULL token exactly as
     * `session.share`'s answer does (the method is token-scoped like the page
     * it lists), beside the display prefix, path, expiry, format and the
     * owning session's name.
     */
    public function testSharesListsTheLiveSharesOfOneSessionWithTheirFullTokens(): void
    {
        $client = $this->fixture->client();
        $mine = $this->seedSession('ses3-sh-a', [Message::user('mine')], 'Alpha notes');
        $theirs = $this->seedSession('ses3-sh-b', [Message::user('theirs')], 'Beta notes');

        $first = $client->call('session.share', ['sessionId' => $mine]);
        $second = $client->call('session.share', ['sessionId' => $mine]);
        $other = $client->call('session.share', ['sessionId' => $theirs]);

        $rows = $client->call('session.shares', ['sessionId' => $mine])['items'];

        self::assertCount(2, $rows, 'the listing holds exactly the two live shares of this session');
        // Newest first, per ShareStore::liveAll()'s ORDER BY.
        self::assertSame([$second['token'], $first['token']], array_column($rows, 'token'), 'full tokens, newest first');
        foreach ($rows as $row) {
            self::assertSame($mine, $row['sessionId']);
            self::assertSame('Alpha notes', $row['sessionName']);
            self::assertSame(substr((string) $row['token'], 0, 8), $row['tokenPrefix'], 'prefix is the leading hex chars');
            self::assertSame('/s/' . $row['token'], $row['path']);
            self::assertSame('html', $row['format']);
            self::assertGreaterThanOrEqual(time(), (int) $row['expiresAt'], 'only live rows are listed');
        }
        self::assertNotContains($other['token'], array_column($rows, 'token'), "another session's share is not listed");
    }

    public function testSharesWithoutSessionIdListsEverySessionsLiveShares(): void
    {
        $client = $this->fixture->client();
        $alpha = $this->seedSession('ses3-all-a', [Message::user('a')], 'Alpha notes');
        $beta = $this->seedSession('ses3-all-b', [Message::user('b')], 'Beta notes');

        $shared = $client->call('session.share', ['sessionId' => $alpha]);
        $sharedAgain = $client->call('session.share', ['sessionId' => $beta]);

        $rows = $client->call('session.shares')['items'];

        self::assertCount(2, $rows);
        self::assertSame([$sharedAgain['token'], $shared['token']], array_column($rows, 'token'));
        $namesBySession = array_column($rows, 'sessionName', 'sessionId');
        self::assertSame(['ses3-all-b' => 'Beta notes', 'ses3-all-a' => 'Alpha notes'], $namesBySession, 'the all-sessions list names each owner');
    }

    public function testARevokedShareLeavesTheList(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->seedSession('ses3-rev', [Message::user('r')]);

        $shared = $client->call('session.share', ['sessionId' => $sessionId]);
        self::assertCount(1, $client->call('session.shares', ['sessionId' => $sessionId])['items']);

        self::assertTrue($client->call('session.unshare', ['token' => $shared['token']])['revoked']);

        self::assertSame([], $client->call('session.shares')['items'], 'a revoked token is gone from every listing');
    }

    public function testSharesOfASessionThatNeverExistedIsTheEmptyList(): void
    {
        $client = $this->fixture->client();

        $answer = $client->call('session.shares', ['sessionId' => 'ses3-no-such']);

        self::assertSame([], $answer['items'], 'the listing states the shares table, which holds nothing for it');
    }

    /** @param list<Message> $messages */
    private function seedSession(string $id, array $messages, ?string $name = null): string
    {
        $this->fixture->store->createSession($id, 'anthropic', 'claude-sonnet-4-6', null, $name);
        TranscriptStore::new($this->fixture->store)->save($id, $messages);

        return $id;
    }

    private function store(): ShareStore
    {
        return ShareStore::atPath($this->fixture->store->path());
    }
}
